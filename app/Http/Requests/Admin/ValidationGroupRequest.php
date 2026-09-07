<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Models\ValidationGroup;
use App\Services\Validation\ValidationGroupService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation d'un groupe de validation, en création comme en modification.
 *
 * L'autorisation reste à la Policy, appelée par le contrôleur : cette classe
 * ne s'occupe que de la forme et de la cohérence des données.
 */
class ValidationGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $groupId = $this->routeGroup()?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('validation_groups', 'name')->ignore($groupId),
            ],
            'validator_1_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'validator_2_id' => [
                'required',
                'integer',
                'different:validator_1_id',
                Rule::exists('users', 'id'),
            ],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer', Rule::exists('users', 'id')],

            'notify_by_email' => ['boolean'],
            // `required_if` sur un tableau échoue quand il est vide : cocher la
            // case sans saisir d'adresse est donc refusé, sans règle dédiée.
            'notification_emails' => ['array', 'required_if:notify_by_email,true', 'max:50'],
            'notification_emails.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom du groupe est obligatoire.',
            'name.unique' => 'Un groupe portant ce nom existe déjà.',
            'validator_1_id.required' => 'Le Valideur 1 est obligatoire.',
            'validator_1_id.exists' => 'Le Valideur 1 sélectionné est introuvable.',
            'validator_2_id.required' => 'Le Valideur 2 est obligatoire.',
            'validator_2_id.exists' => 'Le Valideur 2 sélectionné est introuvable.',
            'validator_2_id.different' => 'Le Valideur 2 doit être différent du Valideur 1.',
            'member_user_ids.*.exists' => 'Un des utilisateurs sélectionnés est introuvable.',
            'notification_emails.required_if' => 'Renseignez au moins une adresse email, ou décochez l\'envoi par email.',
            'notification_emails.max' => 'Un groupe ne peut pas dépasser 50 adresses email.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $notifyByEmail = $this->boolean('notify_by_email');

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'member_user_ids' => collect($this->input('member_user_ids', []))
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all(),

            'notify_by_email' => $notifyByEmail,

            // Le formulaire envoie une seule ligne, adresses séparées par des
            // virgules. Elle est découpée, nettoyée et dédoublonnée AVANT
            // validation : les règles portent ensuite sur la liste réelle, et
            // c'est cette même liste qui sera enregistrée.
            //
            // Option décochée : la saisie est ignorée plutôt que validée. Le
            // champ est masqué à l'écran, refuser l'enregistrement à cause d'un
            // contenu invisible serait incompréhensible. Les adresses déjà
            // enregistrées, elles, sont conservées par le service.
            'notification_emails' => $notifyByEmail
                ? ValidationGroup::parseEmailList($this->emailInput())
                : [],
        ]);
    }

    /**
     * Saisie brute des adresses, quelle que soit la forme envoyée.
     *
     * Le formulaire transmet une chaîne ; un appel programmatique peut envoyer
     * un tableau. Les deux sont acceptés et ramenés à une chaîne, que
     * parseEmailList() découpe ensuite.
     */
    private function emailInput(): ?string
    {
        $raw = $this->input('notification_emails');

        if (is_array($raw)) {
            return implode(',', array_map(fn ($value): string => (string) $value, $raw));
        }

        return is_string($raw) ? $raw : null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateValidatorsAreActive($validator);
            $this->validateMembersAreFree($validator);
            $this->validateNotificationEmails($validator);
        });
    }

    /**
     * Attributs métier prêts pour le service.
     *
     * @return array{name:string,validator_1_id:int,validator_2_id:int,member_user_ids:array<int,int>}
     */
    public function groupAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => trim((string) $validated['name']),
            'validator_1_id' => (int) $validated['validator_1_id'],
            'validator_2_id' => (int) $validated['validator_2_id'],
            'member_user_ids' => array_map('intval', $validated['member_user_ids'] ?? []),
            'notify_by_email' => (bool) ($validated['notify_by_email'] ?? false),
            'notification_emails' => array_values($validated['notification_emails'] ?? []),
        ];
    }

    public function routeGroup(): ?ValidationGroup
    {
        $group = $this->route('validationGroup');

        return $group instanceof ValidationGroup ? $group : null;
    }

    /**
     * Chaque adresse est validée individuellement, et les fautives sont
     * nommées dans un seul message.
     *
     * `email:rfc` et non `email:rfc,dns` : la résolution DNS ajouterait une
     * requête réseau par adresse à chaque enregistrement, et ferait échouer la
     * saisie sur une simple lenteur du résolveur. La forme est vérifiée, pas
     * l'existence de la boîte — que rien ne permet de garantir de toute façon.
     */
    private function validateNotificationEmails(Validator $validator): void
    {
        if (! $this->boolean('notify_by_email')) {
            return;
        }

        /** @var array<int, string> $emails */
        $emails = $this->input('notification_emails', []);

        $invalid = array_values(array_filter(
            $emails,
            fn (string $email): bool => \Illuminate\Support\Facades\Validator::make(
                ['email' => $email],
                ['email' => ['email:rfc']],
            )->fails(),
        ));

        if ($invalid === []) {
            return;
        }

        $validator->errors()->add(
            'notification_emails',
            count($invalid) > 1
                ? 'Ces adresses email sont invalides : '.implode(', ', $invalid).'.'
                : 'Cette adresse email est invalide : '.$invalid[0].'.',
        );
    }

    /**
     * Un compte désactivé ne peut pas se voir confier des validations : il ne
     * se connecte plus, les demandes qui lui seraient adressées resteraient
     * sans réponse.
     */
    private function validateValidatorsAreActive(Validator $validator): void
    {
        foreach (['validator_1_id' => 'Le Valideur 1', 'validator_2_id' => 'Le Valideur 2'] as $field => $label) {
            $userId = (int) $this->input($field);
            $isActive = User::query()->whereKey($userId)->value('is_active');

            if (! $isActive) {
                $validator->errors()->add($field, $label.' sélectionné est un compte désactivé.');
            }
        }
    }

    /**
     * Un utilisateur n'appartient qu'à un seul groupe. Le contrôle est repris
     * en base (index unique) et dans le service : celui-ci n'existe que pour
     * rendre un message clair au formulaire.
     */
    private function validateMembersAreFree(Validator $validator): void
    {
        $memberIds = collect($this->input('member_user_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($memberIds === []) {
            return;
        }

        $conflicts = app(ValidationGroupService::class)
            ->conflictingMemberships($this->routeGroup(), $memberIds);

        if ($conflicts === []) {
            return;
        }

        $names = collect($conflicts)
            ->map(fn (array $conflict): string => $conflict['group_name'])
            ->unique()
            ->implode(', ');

        $validator->errors()->add(
            'member_user_ids',
            'Certains utilisateurs sélectionnés appartiennent déjà à un autre groupe ('.$names.').',
        );
    }
}

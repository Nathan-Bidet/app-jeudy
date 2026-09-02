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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'member_user_ids' => collect($this->input('member_user_ids', []))
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateValidatorsAreActive($validator);
            $this->validateMembersAreFree($validator);
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
        ];
    }

    public function routeGroup(): ?ValidationGroup
    {
        $group = $this->route('validationGroup');

        return $group instanceof ValidationGroup ? $group : null;
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

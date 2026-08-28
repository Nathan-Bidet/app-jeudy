<?php

namespace App\Http\Requests\Maintenance;

use App\Models\MaintenanceTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MaintenanceTaskRequest extends FormRequest
{
    /**
     * L'accès à la route est déjà filtré par le middleware sector.access, et
     * l'autorisation fine (création vs demande, propriété de la tâche) est
     * vérifiée par MaintenanceTaskPolicy dans le contrôleur.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'fin_date' => ['nullable', 'date', 'after_or_equal:date'],
            'due_date' => ['nullable', 'date'],

            'assignee_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assignee_label_free' => ['nullable', 'string', 'max:255'],

            'depot_id' => ['nullable', 'integer', 'exists:depots,id'],
            'address_free' => ['nullable', 'string', 'max:5000'],

            'task' => ['required', 'string', 'max:5000'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'comment_hidden' => ['nullable', 'boolean'],

            // Origine souhaitée. Le droit de l'utiliser est contrôlé par la
            // Policy ; toute autre valeur est rejetée ici.
            'origin' => ['nullable', Rule::in([MaintenanceTask::ORIGIN_CREATION, MaintenanceTask::ORIGIN_REQUEST])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'La date est obligatoire.',
            'fin_date.after_or_equal' => 'La date de fin de période ne peut pas être antérieure à la date de début.',
            'task.required' => 'La description de la tâche est obligatoire.',
            'assignee_user_id.exists' => 'Utilisateur affecté invalide.',
            'depot_id.exists' => 'Dépôt invalide.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'assignee_label_free' => $this->nullableTrimmed('assignee_label_free'),
            'address_free' => $this->nullableTrimmed('address_free'),
            'comment' => $this->nullableTrimmed('comment'),
            'comment_hidden' => $this->boolean('comment_hidden'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasUser = filled($this->input('assignee_user_id'));
            $hasFreeLabel = filled($this->input('assignee_label_free'));

            if ($hasUser && $hasFreeLabel) {
                $validator->errors()->add(
                    'assignee_label_free',
                    'Choisissez un utilisateur ou saisissez une personne libre, pas les deux.'
                );
            }
        });
    }

    /**
     * Champs métier destinés au modèle. Volontairement restreint : ni auteur,
     * ni origine, ni état de pointage ne transitent par ici.
     *
     * @return array<string, mixed>
     */
    public function taskAttributes(): array
    {
        $validated = $this->validated();

        return [
            'date' => $validated['date'],
            'fin_date' => $validated['fin_date'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'assignee_user_id' => $validated['assignee_user_id'] ?? null,
            'assignee_label_free' => $validated['assignee_label_free'] ?? null,
            'depot_id' => $validated['depot_id'] ?? null,
            'address_free' => $validated['address_free'] ?? null,
            'task' => $validated['task'],
            'comment' => $validated['comment'] ?? null,
            'comment_hidden' => (bool) ($validated['comment_hidden'] ?? false),
        ];
    }

    public function requestedOrigin(): ?string
    {
        $origin = $this->validated()['origin'] ?? null;

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    private function nullableTrimmed(string $key): ?string
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = trim((string) $this->input($key));

        return $value !== '' ? $value : null;
    }
}

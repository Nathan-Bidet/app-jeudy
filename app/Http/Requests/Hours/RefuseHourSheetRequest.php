<?php

namespace App\Http\Requests\Hours;

use App\Models\HourSheet;
use App\Services\Validation\TwoStepValidationService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Refus d'une journée d'heures : le motif est OBLIGATOIRE.
 *
 * L'autorisation passe AVANT la validation : quelqu'un qui ne peut pas
 * trancher cette journée reçoit un refus d'accès, jamais une erreur de
 * validation qui lui confirmerait l'existence du formulaire.
 *
 * Propre aux heures : le refus d'un congé ne demande aucun motif et n'utilise
 * pas cette requête.
 */
class RefuseHourSheetRequest extends FormRequest
{
    public const REASON_MAX_LENGTH = 2000;

    public function authorize(): bool
    {
        $hourSheet = $this->route('hourSheet');

        return $this->user() !== null
            && $hourSheet instanceof HourSheet
            && app(TwoStepValidationService::class)->canDecide($hourSheet, $this->user());
    }

    /**
     * Espaces, tabulations et retours à la ligne en bordure sont retirés ; une
     * valeur qui n'en contient pas d'autres devient vide, donc refusée. Le
     * middleware TrimStrings le fait déjà pour les formulaires, mais la règle
     * ne doit pas dépendre de la pile de middlewares.
     */
    protected function prepareForValidation(): void
    {
        $reason = $this->input('refusal_reason');

        if (is_string($reason)) {
            $trimmed = preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $reason) ?? '';

            $this->merge(['refusal_reason' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'refusal_reason' => ['required', 'string', 'max:'.self::REASON_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'refusal_reason.required' => 'Le motif du refus est obligatoire.',
            'refusal_reason.string' => 'Le motif du refus est obligatoire.',
            'refusal_reason.max' => 'Le motif du refus ne peut pas dépasser '.self::REASON_MAX_LENGTH.' caractères.',
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('refusal_reason');
    }
}

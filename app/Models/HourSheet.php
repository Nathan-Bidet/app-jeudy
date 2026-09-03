<?php

namespace App\Models;

use App\Models\Concerns\HasTwoStepValidation;
use App\Support\Validation\ValidationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HourSheet extends Model
{
    use HasTwoStepValidation;

    public const STATUS_PENDING = ValidationStage::PENDING;
    public const STATUS_APPROVED = ValidationStage::APPROVED;
    public const STATUS_REFUSED = ValidationStage::REFUSED;

    protected $fillable = [
        'user_id',
        'work_date',
        'morning_start',
        'morning_end',
        'afternoon_start',
        'afternoon_end',
        'total_minutes',
        'description',
        'is_not_worked',
        'is_continuous_day',
        'has_breakfast_before_5',
        'has_lunch',
        'has_dinner_after_21',
        'has_long_night',
        'status',
        'validation_group_id',
        'validation_group_name',
        'validator_1_id',
        'validator_1_label',
        'validator_1_decision',
        'validator_1_decided_at',
        'validator_1_decided_by_id',
        'validator_2_id',
        'validator_2_label',
        'validator_2_decision',
        'validator_2_decided_at',
        'validator_2_decided_by_id',
        'refusal_reason',
    ];

    protected $casts = [
        'work_date' => 'date',
        'validator_1_decided_at' => 'datetime',
        'validator_2_decided_at' => 'datetime',
        'is_not_worked' => 'boolean',
        'is_continuous_day' => 'boolean',
        'has_breakfast_before_5' => 'boolean',
        'has_lunch' => 'boolean',
        'has_dinner_after_21' => 'boolean',
        'has_long_night' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Journée saisie avant la mise en place de la validation : elle n'a jamais
     * été soumise à personne, et n'entre donc dans aucune file de validation.
     */
    public function isLegacyEntry(): bool
    {
        return $this->status === null;
    }
}

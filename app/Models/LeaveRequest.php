<?php

namespace App\Models;

use App\Models\Concerns\HasTwoStepValidation;
use App\Support\Validation\ValidationStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory, HasTwoStepValidation;

    /**
     * `STATUS_PENDING` vaut toujours `pending` : c'est l'état d'une demande
     * dont les deux accords ne sont pas encore réunis, quel que soit le
     * valideur qui manque. Conserver cette valeur évite de réécrire
     * l'historique et laisse intacts le calendrier, les exports et le front.
     */
    public const STATUS_PENDING = ValidationStage::PENDING;
    public const STATUS_APPROVED = ValidationStage::APPROVED;
    public const STATUS_REFUSED = ValidationStage::REFUSED;
    public const STATUS_PENDING_USER_CONFIRMATION = 'pending_user_confirmation';

    /**
     * Ancien état du circuit séquentiel, migré vers `pending`.
     *
     * @deprecated Les rangs portent désormais leur propre décision.
     */
    public const STATUS_PENDING_VALIDATOR_2 = ValidationStage::LEGACY_PENDING_VALIDATOR_2;

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PENDING_VALIDATOR_2,
        self::STATUS_APPROVED,
        self::STATUS_REFUSED,
        self::STATUS_PENDING_USER_CONFIRMATION,
    ];

    /**
     * États dans lesquels une demande occupe encore un valideur.
     */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PENDING_VALIDATOR_2,
        self::STATUS_PENDING_USER_CONFIRMATION,
    ];

    protected $fillable = [
        'requester_user_id',
        'target_user_id',
        'leave_type_id',
        'start_at',
        'end_at',
        'start_portion',
        'end_portion',
        'is_all_day',
        'custom_start_time',
        'custom_end_time',
        'message',
        'status',
        'validator_user_id',
        'decided_by_user_id',
        'decided_at',
        'proposed_start_at',
        'proposed_end_at',
        'proposed_start_portion',
        'proposed_end_portion',
        'proposed_custom_start_time',
        'proposed_custom_end_time',
        'proposed_message',
        'proposed_by_user_id',
        'proposed_at_level',
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
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_all_day' => 'boolean',
        'decided_at' => 'datetime',
        'proposed_start_at' => 'datetime',
        'proposed_end_at' => 'datetime',
        'validator_1_decided_at' => 'datetime',
        'validator_2_decided_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }
}

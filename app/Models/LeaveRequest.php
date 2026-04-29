<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_PENDING_USER_CONFIRMATION = 'pending_user_confirmation';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REFUSED,
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
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_all_day' => 'boolean',
        'decided_at' => 'datetime',
        'proposed_start_at' => 'datetime',
        'proposed_end_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
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

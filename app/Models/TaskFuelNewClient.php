<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskFuelNewClient extends Model
{
    protected $fillable = [
        'task_tiers_record_id',
        'created_by_user_id',
        'validated_at',
        'validated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'task_tiers_record_id' => 'integer',
            'created_by_user_id' => 'integer',
            'validated_at' => 'datetime',
            'validated_by_user_id' => 'integer',
        ];
    }

    public function tiersRecord(): BelongsTo
    {
        return $this->belongsTo(TaskTiersRecord::class, 'task_tiers_record_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }
}

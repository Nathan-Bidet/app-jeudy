<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskFuelRecurring extends Model
{
    protected $fillable = [
        'client_name',
        'code_tiers',
        'phone',
        'address',
        'postal_code',
        'city',
        'site',
        'fuel_type',
        'volume_liters',
        'urgent',
        'comment',
        'first_delivery_date',
        'recurrence_type',
        'recurrence_config',
        'days_before',
        'active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'urgent' => 'boolean',
            'active' => 'boolean',
            'volume_liters' => 'integer',
            'days_before' => 'integer',
            'recurrence_config' => 'array',
            'first_delivery_date' => 'date',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TaskFuelDelivery::class, 'recurring_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}

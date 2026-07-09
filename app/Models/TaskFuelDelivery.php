<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskFuelDelivery extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'recurring_id',
        'recurring_occurrence_date',
        'delivery_date',
        'actual_delivery_date',
        'delivered_driver_user_id',
        'delivered_at',
        'delivered_pointed_by_user_id',
        'site',
        'tiers_record_id',
        'code_tiers',
        'client_name',
        'phone',
        'address',
        'postal_code',
        'city',
        'fuel_type',
        'volume_liters',
        'comment',
        'urgent',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'recurring_id' => 'integer',
            'recurring_occurrence_date' => 'date',
            'delivery_date' => 'date',
            'actual_delivery_date' => 'date',
            'delivered_at' => 'datetime',
            'delivered_driver_user_id' => 'integer',
            'delivered_pointed_by_user_id' => 'integer',
            'tiers_record_id' => 'integer',
            'volume_liters' => 'integer',
            'urgent' => 'boolean',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(TaskFuelRecurring::class, 'recurring_id');
    }

    public function tiersRecord(): BelongsTo
    {
        return $this->belongsTo(TaskTiersRecord::class, 'tiers_record_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deliveryDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_driver_user_id');
    }

    public function deliveryPointedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_pointed_by_user_id');
    }
}

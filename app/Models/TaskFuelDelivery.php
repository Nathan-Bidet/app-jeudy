<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskFuelDelivery extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'delivery_date',
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
            'delivery_date' => 'date',
            'tiers_record_id' => 'integer',
            'volume_liters' => 'integer',
            'urgent' => 'boolean',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vehicle extends Model
{
    protected $fillable = [
        'vehicle_type_id',
        'name',
        'registration',
        'code_zeendoc',
        'driver_user_id',
        'driver_carb_user_id',
        'depot_id',
        'sector_id',
        'is_rental',
        'garage_id',
        'tractor_vehicle_id',
        'is_active',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_type_id' => 'integer',
            'driver_user_id' => 'integer',
            'driver_carb_user_id' => 'integer',
            'depot_id' => 'integer',
            'sector_id' => 'integer',
            'garage_id' => 'integer',
            'tractor_vehicle_id' => 'integer',
            'is_rental' => 'boolean',
            'is_active' => 'boolean',
            'attributes' => 'array',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function driverCarb(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_carb_user_id');
    }

    public function garage(): BelongsTo
    {
        return $this->belongsTo(Garage::class);
    }

    public function tractor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'tractor_vehicle_id');
    }

    public function bennes(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'vehicle_ensemble_bennes',
            'ensemble_vehicle_id',
            'benne_vehicle_id'
        )->withTimestamps();
    }

    public function entityFiles(): MorphMany
    {
        return $this->morphMany(EntityFile::class, 'attachable');
    }
}

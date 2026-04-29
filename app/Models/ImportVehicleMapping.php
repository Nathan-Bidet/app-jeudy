<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportVehicleMapping extends Model
{
    protected $fillable = [
        'old_vehicle_id',
        'new_vehicle_id',
        'old_vehicle_free',
        'target_type',
        'target_id',
    ];

    protected function casts(): array
    {
        return [
            'old_vehicle_id' => 'integer',
            'new_vehicle_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    public function newVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'new_vehicle_id');
    }
}

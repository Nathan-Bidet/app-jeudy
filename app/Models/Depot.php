<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Depot extends Model
{
    protected $fillable = [
        'name',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
        'phone',
        'email',
        'gps_lat',
        'gps_lng',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'gps_lat' => 'float',
            'gps_lng' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function attachedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function entityFiles(): MorphMany
    {
        return $this->morphMany(EntityFile::class, 'attachable');
    }
}

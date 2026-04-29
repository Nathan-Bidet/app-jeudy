<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnsemblePl extends Model
{
    protected $table = 'ensemble_pls';

    protected $fillable = [
        'code',
        'name',
        'registration',
        'depot_id',
        'sector_id',
        'is_active',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'depot_id' => 'integer',
            'sector_id' => 'integer',
            'is_active' => 'boolean',
            'attributes' => 'array',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}

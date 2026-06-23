<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CotationMarketRefresh extends Model
{
    protected $fillable = [
        'source_url',
        'is_success',
        'http_status',
        'row_count',
        'error_message',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'is_success' => 'boolean',
            'http_status' => 'integer',
            'row_count' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CotationMarketPrice::class, 'refresh_id');
    }
}

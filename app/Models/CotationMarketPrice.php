<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotationMarketPrice extends Model
{
    protected $fillable = [
        'refresh_id',
        'product_code',
        'product_name',
        'product_sort',
        'contract_code',
        'maturity_label',
        'maturity_month',
        'maturity_year',
        'harvest_year',
        'price',
        'raw_price',
        'maturity_sort',
        'quoted_at',
    ];

    protected function casts(): array
    {
        return [
            'refresh_id' => 'integer',
            'product_sort' => 'integer',
            'maturity_month' => 'integer',
            'maturity_year' => 'integer',
            'harvest_year' => 'integer',
            'price' => 'decimal:4',
            'maturity_sort' => 'integer',
            'quoted_at' => 'datetime',
        ];
    }

    public function refresh(): BelongsTo
    {
        return $this->belongsTo(CotationMarketRefresh::class, 'refresh_id');
    }
}

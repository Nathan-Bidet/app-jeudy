<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotationManualPrice extends Model
{
    protected $fillable = [
        'identity_hash',
        'market_identity_hash',
        'line_type',
        'product_code',
        'product_name',
        'product_sort',
        'contract_code',
        'display_label',
        'maturity_label',
        'maturity_month',
        'maturity_year',
        'harvest_year',
        'manual_matif',
        'final_price_reference_key',
        'margin',
        'sort_order',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'product_sort' => 'integer',
            'maturity_month' => 'integer',
            'maturity_year' => 'integer',
            'harvest_year' => 'integer',
            'manual_matif' => 'decimal:4',
            'margin' => 'decimal:4',
            'sort_order' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public static function identityHash(string $productCode, int $harvestYear, int $maturityYear, ?int $maturityMonth, string $maturityLabel): string
    {
        return sha1(implode('|', [
            mb_strtoupper(trim($productCode), 'UTF-8'),
            $harvestYear,
            $maturityYear,
            $maturityMonth ?: '',
            mb_strtoupper(trim($maturityLabel), 'UTF-8'),
        ]));
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

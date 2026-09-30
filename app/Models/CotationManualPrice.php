<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotationManualPrice extends Model
{
    public const MARGIN_SUBTRACT = 'subtract';

    public const MARGIN_ADD = 'add';

    public const MARGIN_OPERATIONS = [self::MARGIN_SUBTRACT, self::MARGIN_ADD];

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
        'margin_operation',
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

    public static function normalizeMarginOperation(mixed $operation): string
    {
        return $operation === self::MARGIN_ADD ? self::MARGIN_ADD : self::MARGIN_SUBTRACT;
    }

    /**
     * Prix final = MATIF − base (« subtract », défaut historique) ou MATIF + base (« add »).
     * La valeur de la base est toujours positive : le signe vit uniquement dans l'opération.
     * Mirroré par applyMargin() dans resources/js/Pages/Cotations/Index.jsx.
     */
    public static function applyMargin(float $matif, mixed $margin, mixed $operation): float
    {
        $base = $margin !== null && $margin !== '' ? abs((float) $margin) : 0.0;

        return self::normalizeMarginOperation($operation) === self::MARGIN_ADD
            ? $matif + $base
            : $matif - $base;
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

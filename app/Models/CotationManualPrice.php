<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotationManualPrice extends Model
{
    public const BASE_SUBTRACT = 'subtract';

    public const BASE_ADD = 'add';

    public const BASE_OPERATIONS = [self::BASE_SUBTRACT, self::BASE_ADD];

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
        'base',
        'base_operation',
        'sale_price',
        'sale_price_operation',
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
            'base' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'margin' => 'decimal:2',
            'sort_order' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * Point unique de calcul de la marge enregistrée : toute sauvegarde du modèle la recalcule
     * à partir des valeurs réellement stockées, jamais d'une valeur reçue du navigateur.
     */
    protected static function booted(): void
    {
        static::saving(function (self $manual): void {
            $manual->margin = round(self::saleMargin(
                $manual->sale_price,
                $manual->sale_price_operation,
                $manual->base,
                $manual->base_operation,
            ), 2);
        });
    }

    public static function normalizeBaseOperation(mixed $operation): string
    {
        return $operation === self::BASE_ADD ? self::BASE_ADD : self::BASE_SUBTRACT;
    }

    /**
     * Prix final = MATIF − base (« subtract », défaut historique) ou MATIF + base (« add »).
     * La valeur de la base est toujours positive : le signe vit uniquement dans l'opération.
     * Mirroré par applyBase() dans resources/js/Pages/Cotations/Index.jsx.
     */
    public static function applyBase(float $matif, mixed $baseValue, mixed $operation): float
    {
        $base = $baseValue !== null && $baseValue !== '' ? abs((float) $baseValue) : 0.0;

        return self::normalizeBaseOperation($operation) === self::BASE_ADD
            ? $matif + $base
            : $matif - $base;
    }

    /**
     * Marge = prix de vente signé − base signée (« add » = +, « subtract » = −).
     * Indicateur réservé au mode modification : n'intervient jamais dans le prix final.
     * Mirroré par computeSaleMargin() dans resources/js/Pages/Cotations/Index.jsx.
     */
    public static function saleMargin(mixed $salePrice, mixed $saleOperation, mixed $base, mixed $baseOperation): float
    {
        $signedSale = (self::normalizeSaleOperation($saleOperation) === self::BASE_ADD ? 1 : -1)
            * ($salePrice !== null && $salePrice !== '' ? abs((float) $salePrice) : 0.0);
        $signedBase = (self::normalizeBaseOperation($baseOperation) === self::BASE_ADD ? 1 : -1)
            * ($base !== null && $base !== '' ? abs((float) $base) : 0.0);

        return round($signedSale - $signedBase, 4);
    }

    /** Le prix de vente est positif par défaut (contrairement à la base, soustraite par défaut). */
    public static function normalizeSaleOperation(mixed $operation): string
    {
        return $operation === self::BASE_SUBTRACT ? self::BASE_SUBTRACT : self::BASE_ADD;
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

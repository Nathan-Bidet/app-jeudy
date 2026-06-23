<?php

namespace App\Support\Cotations;

/**
 * Mirrors the formatPrice/formatMargin/formatRoundedPrice helpers from
 * resources/js/Pages/Cotations/Index.jsx so the PDF export shows the exact
 * same figures as the read-only screen.
 */
class CotationPdfFormatter
{
    public static function price(mixed $value): string
    {
        $number = self::toFloat($value);
        if ($number === null) {
            return '—';
        }

        return number_format($number, 2, ',', ' ').' €';
    }

    public static function roundedPrice(mixed $value): string
    {
        $number = self::toFloat($value);
        if ($number === null) {
            return '—';
        }

        return number_format(round($number), 0, ',', ' ').' €';
    }

    public static function margin(mixed $value): string
    {
        $number = self::toFloat($value);
        if ($number === null) {
            return '—';
        }

        return '-'.number_format(round(abs($number)), 0, ',', ' ').' €';
    }

    /**
     * Mirrors transportReferenceKey() in the front-end so transport grid
     * columns can resolve the same final-price reference used on screen.
     */
    public static function referenceKey(array $row): string
    {
        if (! empty($row['identity_hash'])) {
            return 'identity:'.$row['identity_hash'];
        }

        if (! empty($row['manual_id'])) {
            return 'manual:'.$row['manual_id'];
        }

        return 'line:'.($row['product_code'] ?? '').':'.($row['harvest_year'] ?? '').':'.($row['label'] ?? '');
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', (string) $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}

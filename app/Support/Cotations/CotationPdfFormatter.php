<?php

namespace App\Support\Cotations;

use DOMDocument;
use DOMText;

/**
 * Mirrors the formatPrice/formatMargin/formatRoundedPrice helpers from
 * resources/js/Pages/Cotations/Index.jsx so the PDF export shows the exact
 * same figures as the read-only screen.
 */
class CotationPdfFormatter
{
    public static function text(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $text) ?? $text;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text);
    }

    private static function textNode(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text === '') {
            return '';
        }

        return preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $text) ?? $text;
    }

    public static function html(mixed $value): string
    {
        $html = (string) ($value ?? '');
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="pdf-text-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $document->getElementById('pdf-text-root');
        if (! $root) {
            return self::text($html);
        }

        foreach ([$root, ...iterator_to_array($root->getElementsByTagName('*'))] as $node) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMText) {
                    $child->nodeValue = self::textNode($child->nodeValue);
                }
            }
        }

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

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

<?php

namespace App\Support\Cotations;

/**
 * Server-side port of the cascading HT/TTC computation used by the
 * read-only fuel grid on the Cotations page (resources/js/Pages/Cotations/Index.jsx,
 * FuelGridSection). Each section's rows derive their price from the previous
 * row (HT - écart), unless a manual HT or custom text override is set.
 * Used only to render the PDF export with the exact same figures as the screen.
 */
class CotationFuelCalculator
{
    /**
     * @param  array<string, mixed>  $grid
     * @return array<string, mixed>
     */
    public static function compute(array $grid): array
    {
        $vatRate = self::toFloat($grid['vat_rate'] ?? null) ?? 20.0;
        $sections = $grid['sections'] ?? [];

        $lastComputedHt = null;
        $gnrAgriFirstHt = null;
        $computedSections = [];

        foreach ($sections as $section) {
            $taxHt = self::toFloat($grid['gnr_tax']['ht'] ?? null) ?? 0.0;
            $forcedFirstHt = ($section['id'] ?? null) === 'gnr_taxe' && $gnrAgriFirstHt !== null
                ? $gnrAgriFirstHt + $taxHt
                : null;

            $computedRows = self::computeRows($section['rows'] ?? [], $lastComputedHt, $forcedFirstHt);

            if (($section['id'] ?? null) === 'gnr_agri' && $computedRows !== []) {
                $gnrAgriFirstHt = $computedRows[0]['computed_ht'];
            }

            $lastComputedHt = $computedRows !== []
                ? $computedRows[count($computedRows) - 1]['computed_ht']
                : $lastComputedHt;

            $computedSections[] = [
                'id' => $section['id'] ?? null,
                'label' => $section['label'] ?? '',
                'rows' => array_map(static fn (array $row): array => [
                    'tranche' => $row['tranche'] ?? '',
                    'text' => trim((string) ($row['text'] ?? '')),
                    'computed_ht' => $row['computed_ht'],
                    'computed_ttc' => $row['computed_ht'] !== null ? $row['computed_ht'] * (1 + $vatRate / 100) : null,
                ], $computedRows),
            ];
        }

        $gazole = $grid['gazole'] ?? [];
        $gazoleGap = self::toFloat($gazole['gap'] ?? null) ?? 0.0;
        $gazoleManualTtc = self::toFloat($gazole['ttc'] ?? null);
        $gazoleHtFromManualTtc = $gazoleManualTtc !== null
            ? $gazoleManualTtc / (1 + $vatRate / 100)
            : null;
        $gazoleHt = $gazoleHtFromManualTtc ?? ($lastComputedHt !== null ? $lastComputedHt - $gazoleGap : null);
        $gazoleDisplayedTtc = $gazoleManualTtc ?? ($gazoleHt !== null ? $gazoleHt * (1 + $vatRate / 100) : null);

        return [
            'vat_rate' => $vatRate,
            'sections' => $computedSections,
            'gazole' => [
                'label' => $gazole['label'] ?? 'GAZOLE',
                'tranche' => $gazole['tranche'] ?? 'GAZOLE',
                'text' => trim((string) ($gazole['text'] ?? '')),
                'computed_ht' => $gazoleHt,
                'computed_ttc' => $gazoleDisplayedTtc,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function computeRows(array $rows, ?float $initialPrevious, ?float $forcedFirstHt): array
    {
        $previous = $initialPrevious;
        $result = [];

        foreach (array_values($rows) as $index => $row) {
            $manualHt = self::toFloat($row['ht'] ?? null);
            $gap = self::toFloat($row['gap'] ?? null) ?? 0.0;
            $ht = $index === 0 && $forcedFirstHt !== null
                ? $forcedFirstHt
                : ($manualHt !== null ? $manualHt : ($previous !== null ? $previous - $gap : null));
            $previous = $ht;

            $result[] = [...$row, 'computed_ht' => $ht];
        }

        return $result;
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $value);
    }
}

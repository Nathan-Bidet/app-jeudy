<?php

namespace App\Services\Cotations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation (lecture seule) de l'historique des cotations de céréales déjà
 * conservé par l'application : la table `cotation_market_prices`, alimentée à
 * chaque actualisation (`cotations:refresh`) avec un horodatage `created_at`,
 * puis compactée à un relevé par contrat et par jour au-delà de la fenêtre de
 * rétention (CotationHistoryCompactor). Aucune copie n'est créée.
 *
 * Granularité : un relevé par contrat (produit, récolte, échéance) et par
 * actualisation. Règle pour une journée à plusieurs relevés : le DERNIER relevé
 * de la journée (fuseau métier) pour chaque contrat — l'état de fin de journée.
 * Les anciennes entrées ne sont jamais modifiées.
 *
 * Ce que cet historique ne contient PAS (configuration modifiable écrasée sur
 * place, sans table d'historique dédiée) : marges et prix finaux, lignes de
 * cotation manuelles, libellés, ordre et céréales personnalisées, texte
 * d'information, grille de transport. Ces valeurs ne sont donc pas restituées.
 */
class CotationMarketHistoryService
{
    private const BASE_PRODUCT_CODES = ['ECO', 'EBM', 'EMA'];

    /** Informations visibles aujourd'hui mais absentes de l'historique en base. */
    public const NOT_HISTORIZED = [
        'Marges et prix finaux',
        'Lignes de cotation manuelles et libellés personnalisés',
        'Ordre des céréales et céréales personnalisées',
        "Texte d'information",
        'Grille de transport',
    ];

    public function __construct(private readonly CotationHistoryCompactor $compactor)
    {
    }

    public function available(): bool
    {
        return Schema::hasTable('cotation_market_prices');
    }

    /**
     * Journées (fuseau métier) disposant d'au moins un relevé, de la plus
     * récente à la plus ancienne.
     *
     * Une seule requête groupée par jour de stockage : le jour métier ne change
     * qu'une fois dans un jour de stockage, donc les relevés min et max de chaque
     * groupe suffisent à retrouver tous les jours métier. Servi par l'index
     * (created_at, id).
     *
     * @return array<int, string> dates Y-m-d
     */
    public function dates(): array
    {
        if (! $this->available()) {
            return [];
        }

        $storage = (string) config('app.timezone', 'UTC');
        $business = $this->compactor->businessTimezone();
        $days = [];

        DB::table('cotation_market_prices')
            ->whereNotNull('created_at')
            ->whereIn('product_code', self::BASE_PRODUCT_CODES)
            ->selectRaw('MIN(created_at) as first_at, MAX(created_at) as last_at')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->each(function (object $group) use (&$days, $storage, $business): void {
                foreach ([$group->first_at, $group->last_at] as $value) {
                    $days[CarbonImmutable::parse((string) $value, $storage)->setTimezone($business)->toDateString()] = true;
                }
            });

        $dates = array_keys($days);
        rsort($dates);

        return $dates;
    }

    /**
     * Cotations d'une journée, ou null si aucun relevé ce jour-là.
     *
     * @return array<string, mixed>|null
     */
    public function forDate(string $date): ?array
    {
        if (! $this->available() || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        [$start, $end] = $this->compactor->dayBounds($date);

        // Dernier relevé (created_at le plus récent) de chaque contrat dans la journée.
        $latest = DB::table('cotation_market_prices')
            ->selectRaw('product_code, harvest_year, maturity_year, COALESCE(maturity_month, -1) as month_key, maturity_label, MAX(created_at) as last_at')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->whereIn('product_code', self::BASE_PRODUCT_CODES)
            ->groupBy('product_code', 'harvest_year', 'maturity_year', DB::raw('COALESCE(maturity_month, -1)'), 'maturity_label');

        $candidates = DB::table('cotation_market_prices as p')
            ->joinSub($latest, 'l', function ($join): void {
                $join->on('p.product_code', '=', 'l.product_code')
                    ->on('p.harvest_year', '=', 'l.harvest_year')
                    ->on('p.maturity_year', '=', 'l.maturity_year')
                    ->on('p.maturity_label', '=', 'l.maturity_label')
                    ->on('p.created_at', '=', 'l.last_at')
                    ->whereRaw('COALESCE(p.maturity_month, -1) = l.month_key');
            })
            ->orderBy('p.id')
            ->get(['p.id', 'p.product_code', 'p.product_name', 'p.product_sort', 'p.contract_code', 'p.maturity_label',
                'p.maturity_month', 'p.maturity_year', 'p.harvest_year', 'p.maturity_sort', 'p.price', 'p.quoted_at', 'p.created_at']);

        if ($candidates->isEmpty()) {
            return null;
        }

        // Égalité d'horodatage : le relevé d'identifiant le plus élevé l'emporte.
        $rows = $candidates
            ->keyBy(fn (object $row): string => implode('|', [$row->product_code, $row->harvest_year, $row->maturity_year, $row->maturity_month ?? '', $row->maturity_label]))
            ->sortBy([['product_sort', 'asc'], ['product_name', 'asc'], ['harvest_year', 'asc'], ['maturity_sort', 'asc']])
            ->values();

        $storage = (string) config('app.timezone', 'UTC');
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->product_code] ??= [
                'code' => $row->product_code,
                'name' => $row->product_name,
                'sort' => (int) $row->product_sort,
                'harvests' => [],
            ];

            $harvest = (int) $row->harvest_year;
            $groups[$row->product_code]['harvests'][$harvest] ??= ['year' => $harvest, 'rows' => []];
            $groups[$row->product_code]['harvests'][$harvest]['rows'][] = [
                'label' => $row->maturity_label,
                'maturity_year' => (int) $row->maturity_year,
                'contract_code' => $row->contract_code,
                'price' => (float) $row->price,
                'quoted_at' => $row->quoted_at ? CarbonImmutable::parse((string) $row->quoted_at, $storage)->toIso8601String() : null,
                'recorded_at' => CarbonImmutable::parse((string) $row->created_at, $storage)->toIso8601String(),
            ];
        }

        $groups = array_values(array_map(function (array $group): array {
            $group['harvests'] = array_values($group['harvests']);

            return $group;
        }, $groups));

        return [
            'business_date' => $date,
            'recorded_at' => $rows->max('created_at')
                ? CarbonImmutable::parse((string) $rows->max('created_at'), $storage)->toIso8601String()
                : null,
            'row_count' => $rows->count(),
            'groups' => $groups,
            'not_historized' => self::NOT_HISTORIZED,
        ];
    }
}

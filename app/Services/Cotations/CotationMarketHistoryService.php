<?php

namespace App\Services\Cotations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation (lecture seule) de l'historique des cotations de céréales déjà
 * conservé par l'application : `cotation_market_refreshes` (une ligne par
 * actualisation, `cotations:refresh` toutes les minutes) et
 * `cotation_market_prices` (les cours MATIF de chaque actualisation). Aucune
 * copie n'est créée.
 *
 * RÈGLE DU RELEVÉ DE 15 H (fuseau métier Europe/Paris) — la même que celle du
 * compactage (CotationHistoryCompactor, cotations.compaction.target_time) :
 *  - on retient, pour la journée, l'actualisation dont l'horodatage est le
 *    plus proche de 15 h 00 (à égalité, la plus ancienne, donc « au plus tard
 *    à 15 h ») ; toutes les lignes de la journée viennent de CETTE
 *    actualisation, jamais d'un mélange de relevés ;
 *  - l'actualisation doit tomber dans la tolérance
 *    cotations.history.tolerance_minutes (10 min par défaut, cadence réelle :
 *    une actualisation par minute) ; sinon la journée est « sans relevé de
 *    15 h » : elle n'est pas proposée et on n'affiche jamais un prix d'une
 *    autre heure (23 h 59 par exemple).
 * Les journées anciennes, compactées, ne conservent qu'un relevé proche de
 * 15 h : la même règle s'y applique.
 */
class CotationMarketHistoryService
{
    private const BASE_PRODUCT_CODES = ['ECO', 'EBM', 'EMA'];

    public const STATUS_OK = 'ok';

    /** Aucune actualisation ce jour-là. */
    public const STATUS_NONE = 'none';

    /** Des actualisations existent mais aucune dans la tolérance autour de 15 h. */
    public const STATUS_NO_TARGET = 'no_target';

    public function __construct(
        private readonly CotationHistoryCompactor $compactor,
        private readonly CotationMarketService $marketService,
        private readonly CotationConfigHistoryService $configHistory,
    ) {
    }

    public function available(): bool
    {
        return Schema::hasTable('cotation_market_prices') && Schema::hasTable('cotation_market_refreshes');
    }

    public function toleranceMinutes(): int
    {
        return max(0, (int) config('cotations.history.tolerance_minutes', 10));
    }

    /**
     * Journées disposant d'un relevé de 15 h exploitable, de la plus récente à
     * la plus ancienne. Une journée n'est exploitable que si les réglages
     * (marges, lignes) sont reconstituables, c'est-à-dire postérieure au début
     * du journal d'audit.
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
        $coverageStart = $this->coverageStart();
        $best = [];

        DB::table('cotation_market_refreshes')
            ->where('is_success', true)
            ->where('row_count', '>', 0)
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function (object $refresh) use (&$best, $storage, $business): void {
                $at = CarbonImmutable::parse((string) $refresh->created_at, $storage)->setTimezone($business);
                $day = $at->toDateString();
                $distance = abs($at->diffInSeconds($this->target($day), false));

                if ($distance > $this->toleranceMinutes() * 60) {
                    return;
                }

                // Plus proche de 15 h ; à égalité la plus ancienne (premier rencontré, ids croissants).
                if (! isset($best[$day]) || $distance < $best[$day]['distance']) {
                    $best[$day] = ['distance' => $distance, 'at' => $at];
                }
            });

        $dates = array_keys(array_filter(
            $best,
            fn (array $entry): bool => $coverageStart === null || $entry['at']->greaterThanOrEqualTo($coverageStart),
        ));
        rsort($dates);

        return $dates;
    }

    /**
     * Relevé de 15 h d'une journée : statut, et — si exploitable — l'actualisation
     * retenue et ses cours, au format des lignes de marché de l'affichage courant.
     *
     * @return array{status:string, refresh_id?:int, recorded_at?:CarbonImmutable, market_rows?:array<string, array<string, mixed>>}
     */
    public function selectForDate(string $date): array
    {
        if (! $this->available() || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['status' => self::STATUS_NONE];
        }

        [$start, $end] = $this->compactor->dayBounds($date);
        $storage = (string) config('app.timezone', 'UTC');
        $business = $this->compactor->businessTimezone();
        $target = $this->target($date);

        $refreshes = DB::table('cotation_market_refreshes')
            ->where('is_success', true)
            ->where('row_count', '>', 0)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->orderBy('id')
            ->get(['id', 'created_at']);

        if ($refreshes->isEmpty()) {
            return ['status' => self::STATUS_NONE];
        }

        $best = null;
        foreach ($refreshes as $refresh) {
            $at = CarbonImmutable::parse((string) $refresh->created_at, $storage)->setTimezone($business);
            $distance = abs($at->diffInSeconds($target, false));

            if ($best === null || $distance < $best['distance']) {
                $best = ['id' => (int) $refresh->id, 'at' => $at, 'distance' => $distance];
            }
        }

        if ($best['distance'] > $this->toleranceMinutes() * 60) {
            return ['status' => self::STATUS_NO_TARGET];
        }

        $marketRows = [];
        DB::table('cotation_market_prices')
            ->where('refresh_id', $best['id'])
            ->whereIn('product_code', self::BASE_PRODUCT_CODES)
            ->orderBy('product_sort')
            ->orderBy('product_name')
            ->orderBy('harvest_year')
            ->orderBy('maturity_sort')
            ->get()
            ->each(function (object $price) use (&$marketRows): void {
                $row = $this->marketService->marketRowFromPrice($price);
                $marketRows[$row['identity_hash']] ??= $row;
            });

        if ($marketRows === []) {
            return ['status' => self::STATUS_NONE];
        }

        return [
            'status' => self::STATUS_OK,
            'refresh_id' => $best['id'],
            'recorded_at' => $best['at'],
            'market_rows' => $marketRows,
        ];
    }

    public function target(string $day): CarbonImmutable
    {
        return $this->compactor->dayBounds($day)[2];
    }

    /** Instant avant lequel les réglages ne sont pas reconstituables. */
    private function coverageStart(): ?CarbonImmutable
    {
        $audit = $this->configHistory->firstAuditAt();

        if ($audit !== null) {
            return $audit;
        }

        // Aucun journal : seules les lignes actuelles existent, depuis leur création.
        $first = Schema::hasTable('cotation_manual_prices') ? DB::table('cotation_manual_prices')->min('created_at') : null;

        return $first ? CarbonImmutable::parse((string) $first, (string) config('app.timezone')) : null;
    }
}

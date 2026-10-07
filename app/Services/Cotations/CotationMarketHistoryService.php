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
        $days = array_keys($this->availableDays());
        rsort($days);

        return $days;
    }

    /**
     * Pour chaque journée exploitable (éventuellement bornée à [from, to], dates
     * Y-m-d du fuseau métier) : l'actualisation retenue selon la règle de 15 h.
     * Avec une plage, seules les actualisations de la plage sont lues (index created_at).
     *
     * @return array<string, array{at:CarbonImmutable, refresh_id:int}> clé = date Y-m-d
     */
    public function availableDays(?string $from = null, ?string $to = null): array
    {
        if (! $this->available()) {
            return [];
        }

        $storage = (string) config('app.timezone', 'UTC');
        $business = $this->compactor->businessTimezone();
        $coverageStart = $this->coverageStart();
        $best = [];

        $query = DB::table('cotation_market_refreshes')
            ->where('is_success', true)
            ->where('row_count', '>', 0);

        if ($from !== null) {
            $query->where('created_at', '>=', $this->compactor->dayBounds($from)[0]);
        }
        if ($to !== null) {
            $query->where('created_at', '<', $this->compactor->dayBounds($to)[1]);
        }

        $query->orderBy('id')
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
                    $best[$day] = ['distance' => $distance, 'at' => $at, 'refresh_id' => (int) $refresh->id];
                }
            });

        $days = [];
        foreach ($best as $day => $entry) {
            if ($coverageStart === null || $entry['at']->greaterThanOrEqualTo($coverageStart)) {
                $days[$day] = ['at' => $entry['at'], 'refresh_id' => $entry['refresh_id']];
            }
        }
        ksort($days);

        return $days;
    }

    /**
     * Cours de marché (format des lignes de l'affichage courant) de plusieurs
     * actualisations, en peu de requêtes.
     *
     * @param  array<int, int>  $refreshIds
     * @return array<int, array<string, array<string, mixed>>> refresh_id => identité => ligne
     */
    public function marketRowsForRefreshes(array $refreshIds): array
    {
        $result = [];

        foreach (array_chunk(array_values(array_unique($refreshIds)), 400) as $chunk) {
            DB::table('cotation_market_prices')
                ->whereIn('refresh_id', $chunk)
                ->whereIn('product_code', self::BASE_PRODUCT_CODES)
                ->orderBy('refresh_id')
                ->orderBy('product_sort')
                ->orderBy('product_name')
                ->orderBy('harvest_year')
                ->orderBy('maturity_sort')
                ->get()
                ->each(function (object $price) use (&$result): void {
                    $row = $this->marketService->marketRowFromPrice($price);
                    $result[(int) $price->refresh_id][$row['identity_hash']] ??= $row;
                });
        }

        return $result;
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

    /**
     * Pour chaque contrat connu de l'historique (clé = identité des lignes de
     * cotation) : dernier cours enregistré jusqu'à l'instant donné, et dernier
     * cours enregistré tout court. Sert à expliquer précisément pourquoi une
     * ligne n'a pas de cours dans un relevé (contrat disparu du flux, cours à
     * une autre heure…), sans jamais en fabriquer un.
     *
     * @return array<string, array{last_before:?CarbonImmutable,last_any:?CarbonImmutable}>
     */
    public function contractAvailability(CarbonImmutable $at): array
    {
        if (! $this->available()) {
            return [];
        }

        $storage = (string) config('app.timezone', 'UTC');
        $limit = $at->setTimezone($storage)->format('Y-m-d H:i:s');
        $parse = static fn (?string $value): ?CarbonImmutable => $value ? CarbonImmutable::parse($value, $storage) : null;
        $result = [];

        DB::table('cotation_market_prices')
            ->selectRaw('product_code, harvest_year, maturity_year, maturity_month, maturity_label, MAX(created_at) as last_any, MAX(CASE WHEN created_at <= ? THEN created_at END) as last_before', [$limit])
            ->groupBy('product_code', 'harvest_year', 'maturity_year', 'maturity_month', 'maturity_label')
            ->get()
            ->each(function (object $row) use (&$result, $parse): void {
                $hash = \App\Models\CotationManualPrice::identityHash(
                    (string) $row->product_code,
                    (int) $row->harvest_year,
                    (int) $row->maturity_year,
                    $row->maturity_month !== null ? (int) $row->maturity_month : null,
                    (string) $row->maturity_label,
                );
                $result[$hash] = ['last_before' => $parse($row->last_before), 'last_any' => $parse($row->last_any)];
            });

        return $result;
    }

    /**
     * Dernier cours enregistré de chaque contrat de l'historique (clé = identité des
     * lignes de cotation) : ligne de marché et horodatage. Une seule lecture, partagée
     * par toutes les journées d'une plage.
     *
     * @param  array<int, string>|null  $hashes  restreint aux identités demandées
     * @return array<string, array{row:array<string,mixed>, last_any:string}>
     */
    public function contractLastQuotes(?array $hashes = null): array
    {
        if (! $this->available()) {
            return [];
        }

        $wanted = $hashes === null ? null : array_flip($hashes);
        $latestIds = DB::table('cotation_market_prices')
            ->selectRaw('MAX(id) as id')
            ->whereIn('product_code', self::BASE_PRODUCT_CODES)
            ->groupBy('product_code', 'harvest_year', 'maturity_year', 'maturity_month', 'maturity_label')
            ->pluck('id')
            ->all();

        $result = [];
        foreach (array_chunk($latestIds, 400) as $chunk) {
            DB::table('cotation_market_prices')->whereIn('id', $chunk)->get()->each(function (object $price) use (&$result, $wanted): void {
                $row = $this->marketService->marketRowFromPrice($price);

                if ($wanted === null || isset($wanted[$row['identity_hash']])) {
                    $result[$row['identity_hash']] = ['row' => $row, 'last_any' => (string) $price->created_at];
                }
            });
        }

        return $result;
    }

    /**
     * Dernier cours connu des contrats ÉCHUS absents du relevé de 15 h — même règle
     * que l'affichage actuel (qui reprend le dernier cours d'une échéance échue
     * conservée), appliquée « à la date consultée » :
     *  - le contrat (produit, récolte, année, mois, libellé exacts) est identifié
     *    par l'identité de la ligne ; aucun autre contrat n'est jamais utilisé ;
     *  - il doit ne plus avoir AUCUN cours à partir du début de la journée
     *    consultée : un contrat qui reçoit encore des cours ce jour-là ou plus tard
     *    est actif, son absence est une anomalie de collecte et n'est PAS masquée ;
     *  - le cours repris est donc son dernier cours, forcément antérieur à la
     *    journée (donc au plus tard à 15 h ce jour-là) — jamais un cours postérieur.
     *
     * @param  array<string, array{row:array<string,mixed>, last_any:string}>  $contractLast  cf. contractLastQuotes()
     * @param  array<int, string>  $hashes  identités de ligne de marché sans cours
     * @return array<string, array<string, mixed>> identité => ligne de marché + `carried_quote_at`
     */
    public function carriedFrom(array $contractLast, string $date, array $hashes): array
    {
        $storage = (string) config('app.timezone', 'UTC');
        $business = $this->compactor->businessTimezone();
        [$dayStart] = $this->compactor->dayBounds($date);
        $carried = [];

        foreach ($hashes as $hash) {
            $contract = $contractLast[$hash] ?? null;

            if ($contract === null || $contract['last_any'] >= $dayStart) {
                continue;
            }

            $row = $contract['row'];
            $row['carried_quote_at'] = CarbonImmutable::parse($contract['last_any'], $storage)->setTimezone($business)->toIso8601String();
            $carried[$hash] = $row;
        }

        return $carried;
    }

    /**
     * @param  array<int, string>  $hashes
     * @return array<string, array<string, mixed>>
     */
    public function carriedQuotes(string $date, CarbonImmutable $recordedAt, array $hashes): array
    {
        if ($hashes === [] || ! $this->available()) {
            return [];
        }

        return $this->carriedFrom($this->contractLastQuotes($hashes), $date, $hashes);
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

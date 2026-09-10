<?php

namespace App\Services\Cotations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compactage de l'historique des cotations de marché.
 *
 * `cotations:refresh` est planifiée toutes les minutes : chaque journée ajoute
 * environ 1 440 actualisations, soit ~36 000 lignes dans
 * `cotation_market_prices`. Sur trois mois la table dépasse déjà 2,7 millions
 * de lignes alors que l'application ne lit jamais que le dernier relevé de
 * chaque cotation (cf. CotationMarketService::latestMarketRowsByIdentity).
 *
 * Politique appliquée :
 *  - les `retention_days` derniers jours gardent leur granularité complète ;
 *  - toute journée civile ENTIÈREMENT antérieure à cette fenêtre est réduite à
 *    un seul relevé par cotation, celui dont l'horodatage est le plus proche de
 *    15 h (heure `Europe/Paris`).
 *
 * Une journée à cheval sur la fenêtre n'est jamais traitée : on ne peut donc
 * pas obtenir une journée à moitié détaillée et à moitié compactée.
 */
class CotationHistoryCompactor
{
    /**
     * Clé métier d'une cotation.
     *
     * Ce sont exactement les colonnes utilisées par
     * CotationManualPrice::identityHash() et par le GROUP BY de
     * CotationMarketService : compacter sur cette clé garantit qu'aucune ligne
     * affichée aujourd'hui ne peut disparaître de l'historique.
     *
     * `contract_code` en est volontairement absent : il varie d'un mois de
     * cotation à l'autre pour une même échéance et n'entre pas dans l'identité
     * métier côté application.
     */
    public const IDENTITY_COLUMNS = [
        'product_code',
        'harvest_year',
        'maturity_year',
        'maturity_month',
        'maturity_label',
    ];

    /** Garde-fou : nombre maximal de lots de suppression pour une journée. */
    private const MAX_DELETE_BATCHES = 10000;

    /**
     * Journées candidates au compactage, de la plus ancienne à la plus récente.
     *
     * @param  string|null  $until  date `Y-m-d` (incluse) plafonnant le traitement
     * @param  int  $limit  nombre maximal de journées renvoyées (0 = illimité)
     * @return array<int, string> dates `Y-m-d` exprimées dans le fuseau métier
     */
    public function eligibleDays(?CarbonImmutable $now = null, ?int $retentionDays = null, ?string $until = null, int $limit = 0): array
    {
        $lastEligible = $this->lastEligibleDay($now, $retentionDays);
        $first = $this->firstRecordedDay();

        if ($first === null) {
            return [];
        }

        if ($until !== null && trim($until) !== '') {
            $cap = CarbonImmutable::parse(trim($until), $this->businessTimezone())->startOfDay();

            if ($cap->lessThan($lastEligible)) {
                $lastEligible = $cap;
            }
        }

        $days = [];

        for ($day = $first; $day->lessThanOrEqualTo($lastEligible); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');

            if ($limit > 0 && count($days) >= $limit) {
                break;
            }
        }

        return $days;
    }

    /**
     * Dernière journée civile entièrement antérieure à la fenêtre protégée.
     *
     * Avec 7 jours de rétention et un appel le 10/09 à 17 h, `now - 7 jours`
     * tombe le 03/09 à 17 h : cette journée est partiellement dans la fenêtre,
     * elle est donc protégée en entier et la dernière journée compactable est
     * le 02/09. La granularité complète est ainsi garantie sur AU MOINS
     * 7 jours glissants.
     */
    public function lastEligibleDay(?CarbonImmutable $now = null, ?int $retentionDays = null): CarbonImmutable
    {
        $retentionDays = max(1, $retentionDays ?? (int) config('cotations.compaction.retention_days', 7));

        return ($now ?? CarbonImmutable::now())
            ->setTimezone($this->businessTimezone())
            ->subDays($retentionDays)
            ->startOfDay()
            ->subDay();
    }

    /**
     * Sélectionne, pour une journée, le relevé conservé de chaque cotation.
     *
     * La lecture se fait par lots bornés sur le Query Builder : aucun modèle
     * Eloquent n'est hydraté et la mémoire reste proportionnelle au nombre de
     * cotations distinctes (27 en production), pas au nombre de lignes.
     *
     * @return array{
     *     day: string,
     *     total: int,
     *     kept: array<string, array<string, mixed>>,
     *     refresh_ids: array<int, int>,
     *     single_refresh: bool
     * }
     */
    public function planForDay(string $day, ?int $chunkSize = null): array
    {
        $chunkSize = max(100, $chunkSize ?? (int) config('cotations.compaction.chunk_size', 5000));
        [$start, $end, $target] = $this->dayBounds($day);

        $kept = [];
        $total = 0;
        $lastId = 0;

        while (true) {
            $rows = DB::table('cotation_market_prices')
                ->select(array_merge(['id', 'refresh_id', 'created_at'], self::IDENTITY_COLUMNS))
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $total++;

                $at = CarbonImmutable::parse((string) $row->created_at, $this->storageTimezone());
                $distance = abs($at->getTimestamp() - $target->getTimestamp());
                $isAfter = $at->getTimestamp() >= $target->getTimestamp();
                $key = $this->identityKey($row);

                $candidate = [
                    'id' => (int) $row->id,
                    'refresh_id' => (int) $row->refresh_id,
                    'at' => $at,
                    'distance' => $distance,
                    'after' => $isAfter,
                    'identity' => $this->identityLabel($row),
                ];

                if (! isset($kept[$key]) || $this->beats($candidate, $kept[$key])) {
                    $kept[$key] = $candidate;
                }
            }

            if ($rows->count() < $chunkSize) {
                break;
            }
        }

        $refreshIds = array_values(array_unique(array_column($kept, 'refresh_id')));
        sort($refreshIds);

        return [
            'day' => $day,
            'total' => $total,
            'kept' => $kept,
            'refresh_ids' => $refreshIds,
            'single_refresh' => count($refreshIds) <= 1,
        ];
    }

    /**
     * Départage deux relevés d'une même cotation sur une même journée.
     *
     * Règle documentée, strictement déterministe :
     *  1. l'écart le plus faible avec 15 h l'emporte ;
     *  2. à écart égal, le relevé POSTÉRIEUR à 15 h l'emporte ;
     *  3. à égalité complète, l'identifiant le plus élevé l'emporte.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    private function beats(array $candidate, array $current): bool
    {
        if ($candidate['distance'] !== $current['distance']) {
            return $candidate['distance'] < $current['distance'];
        }

        if ($candidate['after'] !== $current['after']) {
            return (bool) $candidate['after'];
        }

        return $candidate['id'] > $current['id'];
    }

    /**
     * Compacte une journée : conserve un relevé par cotation, supprime le reste.
     *
     * Les suppressions se font par lots bornés, chacun dans sa propre
     * transaction implicite : aucune transaction ne couvre la journée entière
     * et les verrous restent courts.
     *
     * @param  array{dry_run?: bool, batch_size?: int, chunk_size?: int, sleep_ms?: int, prune_refreshes?: bool}  $options
     * @return array<string, mixed>
     */
    public function compactDay(string $day, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $batchSize = max(100, (int) ($options['batch_size'] ?? config('cotations.compaction.batch_size', 2000)));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? config('cotations.compaction.sleep_ms', 0)));
        $pruneRefreshes = (bool) ($options['prune_refreshes'] ?? true);

        $plan = $this->planForDay($day, $options['chunk_size'] ?? null);
        [$start, $end] = $this->dayBounds($day);

        $keptIds = array_map(static fn (array $row): int => $row['id'], array_values($plan['kept']));
        $expectedDeletions = max(0, $plan['total'] - count($keptIds));

        // Garde-fou : jamais de suppression de masse sur une condition ambiguë.
        // Si la journée contient des lignes mais qu'aucune n'a été retenue, la
        // sélection est incohérente : on refuse de toucher à la journée.
        if ($plan['total'] > 0 && $keptIds === []) {
            throw new RuntimeException("Compactage annulé pour le {$day} : aucun relevé retenu alors que la journée contient {$plan['total']} ligne(s).");
        }

        $deleted = 0;

        if (! $dryRun && $expectedDeletions > 0) {
            $batches = 0;

            while (true) {
                $ids = DB::table('cotation_market_prices')
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->whereNotIn('id', $keptIds)
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                $deleted += DB::table('cotation_market_prices')->whereIn('id', $ids)->delete();

                if (++$batches > self::MAX_DELETE_BATCHES) {
                    throw new RuntimeException("Compactage interrompu pour le {$day} : trop de lots de suppression.");
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        $refreshesDeleted = $pruneRefreshes
            ? $this->pruneOrphanRefreshes($day, $plan['refresh_ids'], $batchSize, $dryRun)
            : 0;

        return [
            'day' => $day,
            'total' => $plan['total'],
            'kept' => count($keptIds),
            'kept_rows' => $plan['kept'],
            'deleted' => $dryRun ? $expectedDeletions : $deleted,
            'refreshes_deleted' => $refreshesDeleted,
            'single_refresh' => $plan['single_refresh'],
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Supprime les actualisations de la journée devenues orphelines.
     *
     * La clé étrangère `cot_price_refresh_fk` est en CASCADE : supprimer une
     * actualisation supprimerait ses cotations. On ne supprime donc QUE celles
     * qui n'ont plus aucune ligne de prix, quelle qu'en soit la journée — une
     * actualisation de 23 h 59 dont les prix ont été insérés le lendemain reste
     * protégée.
     *
     * @param  array<int, int>  $keptRefreshIds
     */
    private function pruneOrphanRefreshes(string $day, array $keptRefreshIds, int $batchSize, bool $dryRun): int
    {
        [$start, $end] = $this->dayBounds($day);

        $query = DB::table('cotation_market_refreshes')
            ->where('fetched_at', '>=', $start)
            ->where('fetched_at', '<', $end)
            ->whereNotExists(function ($sub) use ($dryRun, $start, $end): void {
                $sub->select(DB::raw(1))
                    ->from('cotation_market_prices')
                    ->whereColumn('cotation_market_prices.refresh_id', 'cotation_market_refreshes.id');

                // En simulation les lignes ne sont pas encore supprimées : on
                // ne regarde donc que les prix situés HORS de la journée, qui
                // survivront de toute façon au compactage.
                if ($dryRun) {
                    $sub->where(function ($outside) use ($start, $end): void {
                        $outside->where('cotation_market_prices.created_at', '<', $start)
                            ->orWhere('cotation_market_prices.created_at', '>=', $end)
                            ->orWhereNull('cotation_market_prices.created_at');
                    });
                }
            });

        if ($dryRun) {
            // Les actualisations dont un relevé est conservé ne deviendront pas
            // orphelines : on les retire du décompte simulé.
            return (int) (clone $query)
                ->when($keptRefreshIds !== [], fn ($q) => $q->whereNotIn('id', $keptRefreshIds))
                ->count();
        }

        $deleted = 0;
        $batches = 0;

        while (true) {
            $ids = (clone $query)->orderBy('id')->limit($batchSize)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::table('cotation_market_refreshes')->whereIn('id', $ids)->delete();

            if (++$batches > self::MAX_DELETE_BATCHES) {
                throw new RuntimeException("Nettoyage des actualisations interrompu pour le {$day} : trop de lots.");
            }
        }

        return $deleted;
    }

    /**
     * Bornes d'une journée et instant cible, prêts pour le SQL.
     *
     * Les bornes sont calculées dans le fuseau métier puis converties dans le
     * fuseau de stockage (celui dans lequel Laravel écrit ses timestamps).
     * `addDay()` sur une date localisée gère les changements d'heure : une
     * journée de 23 h ou de 25 h reste une journée civile complète.
     *
     * @return array{0: string, 1: string, 2: CarbonImmutable}
     */
    public function dayBounds(string $day): array
    {
        $business = $this->businessTimezone();
        $storage = $this->storageTimezone();

        $start = CarbonImmutable::parse($day, $business)->startOfDay();
        $end = $start->addDay();
        $target = CarbonImmutable::parse(
            $start->format('Y-m-d').' '.config('cotations.compaction.target_time', '15:00:00'),
            $business,
        );

        return [
            $start->setTimezone($storage)->format('Y-m-d H:i:s'),
            $end->setTimezone($storage)->format('Y-m-d H:i:s'),
            $target,
        ];
    }

    /**
     * Première journée présente dans l'historique, ou null si la table est vide.
     *
     * Les lignes dont `created_at` est nul sont ignorées : sans horodatage
     * fiable elles ne peuvent être rattachées à aucune journée, et le
     * compactage ne les supprimera jamais (les bornes SQL excluent NULL).
     */
    private function firstRecordedDay(): ?CarbonImmutable
    {
        $first = DB::table('cotation_market_prices')->whereNotNull('created_at')->min('created_at');

        if ($first === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $first, $this->storageTimezone())
            ->setTimezone($this->businessTimezone())
            ->startOfDay();
    }

    private function identityKey(object $row): string
    {
        $parts = [];

        foreach (self::IDENTITY_COLUMNS as $column) {
            $parts[] = $row->{$column} === null ? '' : mb_strtoupper(trim((string) $row->{$column}), 'UTF-8');
        }

        return implode('|', $parts);
    }

    private function identityLabel(object $row): string
    {
        return sprintf(
            '%s %s (récolte %s)',
            $row->product_code,
            $row->maturity_label,
            $row->harvest_year,
        );
    }

    private function businessTimezone(): string
    {
        return (string) config('cotations.compaction.timezone', 'Europe/Paris');
    }

    /**
     * Fuseau dans lequel les timestamps sont écrits et relus par Laravel.
     *
     * En production `APP_TIMEZONE=Europe/Paris` : les deux fuseaux coïncident
     * et les conversions sont neutres. Les garder distincts rend le calcul
     * correct même si l'application passait un jour en UTC.
     */
    private function storageTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}

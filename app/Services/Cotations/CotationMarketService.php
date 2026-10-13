<?php

namespace App\Services\Cotations;

use App\Models\CotationMarketPrice;
use App\Models\CotationMarketRefresh;
use App\Models\CotationManualPrice;
use App\Models\CotationSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CotationMarketService
{
    public const EURONEXT_URL = 'https://app2.jeudy-sa.fr/cours-euronext';

    private const PRODUCT_CODES = [
        'ECO' => ['name' => 'Colza', 'aliases' => ['RAPESEED', 'COLZA'], 'sort' => 10],
        'EBM' => ['name' => 'Blé', 'aliases' => ['WHEAT', 'BLE', 'BLÉ', 'MILLING WHEAT'], 'sort' => 20],
        'EMA' => ['name' => 'Maïs', 'aliases' => ['MAIZE', 'CORN', 'MAIS', 'MAÏS'], 'sort' => 30],
        'EOB' => ['name' => 'Orge', 'aliases' => ['BARLEY', 'ORGE', 'MALTING BARLEY'], 'sort' => 40],
        'EOR' => ['name' => 'Orge', 'aliases' => ['BARLEY', 'ORGE'], 'sort' => 40],
        'ERS' => ['name' => 'Seigle', 'aliases' => ['RYE', 'SEIGLE'], 'sort' => 50],
        'ETR' => ['name' => 'Triticale', 'aliases' => ['TRITICALE'], 'sort' => 60],
    ];

    private const BASE_PRODUCT_CODES = ['ECO', 'EBM', 'EMA'];

    /**
     * Registre durable des suppressions / restaurations de céréales (cotation_settings, clé
     * cereal_deleted_codes) : code => nom + périodes successives. Une restauration ferme la période
     * en cours sans rien effacer, ce qui permet plusieurs cycles et des historiques fidèles.
     * Les anciennes entrées (une seule suppression à plat) sont lues comme une période ouverte.
     *
     * @return array<string, array{name: ?string, periods: array<int, array{deleted_at: string, deleted_by: ?int, restored_at: ?string, restored_by: ?int}>}>
     */
    public function deletionRegistry(): array
    {
        if (! Schema::hasTable('cotation_settings')) {
            return [];
        }

        $note = CotationSetting::query()->where('key', 'cereal_deleted_codes')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;
        $registry = [];

        foreach (is_array($decoded) ? $decoded : [] as $code => $entry) {
            if (! is_string($code) || $code === '' || ! is_array($entry)) {
                continue;
            }

            $periods = isset($entry['periods']) && is_array($entry['periods'])
                ? $entry['periods']
                : [['deleted_at' => $entry['deleted_at'] ?? null, 'deleted_by' => $entry['deleted_by'] ?? null]];

            $clean = [];
            foreach ($periods as $period) {
                if (! is_array($period) || empty($period['deleted_at'])) {
                    continue;
                }
                $clean[] = [
                    'deleted_at' => (string) $period['deleted_at'],
                    'deleted_by' => isset($period['deleted_by']) ? (int) $period['deleted_by'] : null,
                    'restored_at' => ! empty($period['restored_at']) ? (string) $period['restored_at'] : null,
                    'restored_by' => isset($period['restored_by']) ? (int) $period['restored_by'] : null,
                ];
            }

            if ($clean !== []) {
                $registry[$code] = ['name' => $entry['name'] ?? null, 'periods' => $clean];
            }
        }

        return $registry;
    }

    /**
     * Céréales actuellement supprimées de la gestion courante (suppression logique) : code => période
     * en cours. Aucune donnée n'est effacée : lignes de cotation, cours et journal restent en base ;
     * seul ce registre écarte la céréale des vues courantes (édition, consultation, PDF, courriel).
     *
     * @return array<string, array{deleted_at: Carbon, name: ?string, deleted_by: ?int}>
     */
    public function deletedCereals(): array
    {
        $deleted = [];

        foreach ($this->deletionRegistry() as $code => $entry) {
            $last = end($entry['periods']);
            if ($last['restored_at'] === null) {
                $deleted[$code] = [
                    'deleted_at' => Carbon::parse($last['deleted_at']),
                    'name' => $entry['name'],
                    'deleted_by' => $last['deleted_by'],
                ];
            }
        }

        return $deleted;
    }

    /**
     * La céréale était-elle supprimée à cet instant ? (historique : toutes les périodes comptent,
     * donc une restauration ultérieure ne fait pas réapparaître la céréale dans le passé.)
     */
    public function wasDeletedAt(string $code, \DateTimeInterface $at): bool
    {
        foreach ($this->deletionRegistry()[$code]['periods'] ?? [] as $period) {
            $from = Carbon::parse($period['deleted_at'])->getTimestamp();
            $to = $period['restored_at'] !== null ? Carbon::parse($period['restored_at'])->getTimestamp() : PHP_INT_MAX;
            if ($from <= $at->getTimestamp() && $at->getTimestamp() < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function withoutDeleted(array $groups): array
    {
        $deleted = $this->deletedCereals();

        return $deleted === []
            ? $groups
            : array_values(array_filter($groups, static fn (array $group): bool => ! isset($deleted[(string) ($group['code'] ?? '')])));
    }

    /**
     * Codes de céréales du module : produits MATIF connus et céréales personnalisées.
     *
     * @return array<int, string>
     */
    public function knownProductCodes(): array
    {
        return array_values(array_unique([
            ...array_keys(self::PRODUCT_CODES),
            ...array_column($this->customCereals(), 'code'),
        ]));
    }

    /**
     * La récolte d'une ligne de livraison et l'échéance MATIF qu'elle référence sont indépendantes :
     * n'importe quelle échéance active de la céréale MATIF d'origine peut être choisie pour n'importe
     * quelle récolte. Le `harvest_year` d'un cours de marché (= son année d'échéance) ne sert plus que de
     * composante technique de son identité (identityHash) ; il ne filtre plus aucune option.
     */

    private const FUTURES_MONTHS = [
        'F' => ['number' => 1, 'label' => 'Janvier'],
        'G' => ['number' => 2, 'label' => 'Février'],
        'H' => ['number' => 3, 'label' => 'Mars'],
        'J' => ['number' => 4, 'label' => 'Avril'],
        'K' => ['number' => 5, 'label' => 'Mai'],
        'M' => ['number' => 6, 'label' => 'Juin'],
        'N' => ['number' => 7, 'label' => 'Juillet'],
        'Q' => ['number' => 8, 'label' => 'Août'],
        'U' => ['number' => 9, 'label' => 'Septembre'],
        'V' => ['number' => 10, 'label' => 'Octobre'],
        'X' => ['number' => 11, 'label' => 'Novembre'],
        'Z' => ['number' => 12, 'label' => 'Décembre'],
    ];

    private const MONTH_ALIASES = [
        'JAN' => ['number' => 1, 'label' => 'Janvier'],
        'JANVIER' => ['number' => 1, 'label' => 'Janvier'],
        'FEB' => ['number' => 2, 'label' => 'Février'],
        'FEV' => ['number' => 2, 'label' => 'Février'],
        'FÉV' => ['number' => 2, 'label' => 'Février'],
        'FEVRIER' => ['number' => 2, 'label' => 'Février'],
        'FÉVRIER' => ['number' => 2, 'label' => 'Février'],
        'MAR' => ['number' => 3, 'label' => 'Mars'],
        'MARS' => ['number' => 3, 'label' => 'Mars'],
        'APR' => ['number' => 4, 'label' => 'Avril'],
        'AVR' => ['number' => 4, 'label' => 'Avril'],
        'AVRIL' => ['number' => 4, 'label' => 'Avril'],
        'MAY' => ['number' => 5, 'label' => 'Mai'],
        'MAI' => ['number' => 5, 'label' => 'Mai'],
        'JUN' => ['number' => 6, 'label' => 'Juin'],
        'JUIN' => ['number' => 6, 'label' => 'Juin'],
        'JUL' => ['number' => 7, 'label' => 'Juillet'],
        'JUIL' => ['number' => 7, 'label' => 'Juillet'],
        'JUILLET' => ['number' => 7, 'label' => 'Juillet'],
        'AUG' => ['number' => 8, 'label' => 'Août'],
        'AOU' => ['number' => 8, 'label' => 'Août'],
        'AOÛ' => ['number' => 8, 'label' => 'Août'],
        'AOUT' => ['number' => 8, 'label' => 'Août'],
        'AOÛT' => ['number' => 8, 'label' => 'Août'],
        'SEP' => ['number' => 9, 'label' => 'Septembre'],
        'SEPT' => ['number' => 9, 'label' => 'Septembre'],
        'SEPTEMBRE' => ['number' => 9, 'label' => 'Septembre'],
        'OCT' => ['number' => 10, 'label' => 'Octobre'],
        'OCTOBRE' => ['number' => 10, 'label' => 'Octobre'],
        'NOV' => ['number' => 11, 'label' => 'Novembre'],
        'NOVEMBRE' => ['number' => 11, 'label' => 'Novembre'],
        'DEC' => ['number' => 12, 'label' => 'Décembre'],
        'DÉC' => ['number' => 12, 'label' => 'Décembre'],
        'DECEMBRE' => ['number' => 12, 'label' => 'Décembre'],
        'DÉCEMBRE' => ['number' => 12, 'label' => 'Décembre'],
    ];

    /**
     * Interroge la source et enregistre les cours. Ne lève pas d'exception : le résultat est la ligne
     * d'historique `cotation_market_refreshes` (is_success, error_message) et, en cas d'échec,
     * `failureKind` distingue l'indisponibilité temporaire de l'échec définitif.
     */
    public function refreshFromEuronext(): CotationMarketRefresh
    {
        // Message provisoire : il subsiste si le processus est interrompu avant la fin (arrêt du
        // conteneur, kill), ce qui laisse sinon une ligne en échec sans aucune explication.
        $refresh = CotationMarketRefresh::query()->create([
            'source_url' => self::EURONEXT_URL,
            'is_success' => false,
            'error_message' => 'Actualisation interrompue avant la fin (processus arrêté ?)',
            'fetched_at' => now(),
        ]);

        try {
            $response = $this->fetchSource();
            $refresh->http_status = $response->status();

            $body = (string) $response->body();
            $contentType = strtolower((string) $response->header('content-type', ''));
            $payload = str_contains($contentType, 'json') ? $response->json() : json_decode($body, true);
            $rows = is_array($payload)
                ? $this->extractRows($payload)
                : $this->extractHtmlRows($body);
            $prices = $this->normalizeRows($rows);

            // HTTP 200 sans aucun cours : la source répond mais ne fournit rien (constaté lors d'un
            // incident de la source). Ce n'est pas une actualisation réussie : les cotations ne
            // seraient pas mises à jour alors que la tâche paraîtrait saine.
            if ($prices === []) {
                throw new CotationSourceException('Réponse HTTP '.$response->status().' sans aucun cours exploitable', true, $response->status());
            }

            CotationMarketPrice::query()->insert(array_map(fn (array $price): array => [
                ...$price,
                'refresh_id' => $refresh->id,
                'created_at' => now(),
                'updated_at' => now(),
            ], $prices));

            $refresh->forceFill([
                'is_success' => true,
                'error_message' => null,
                'row_count' => count($prices),
            ])->save();
        } catch (CotationSourceException $exception) {
            $this->recordRefreshFailure(
                $refresh,
                $exception,
                $exception->transient ? CotationMarketRefresh::FAILURE_TRANSIENT : CotationMarketRefresh::FAILURE_DEFINITIVE,
                $exception->httpStatus,
                $exception->attempts,
            );
        } catch (Throwable $exception) {
            // Erreur technique (lecture de la réponse, base de données...) : jamais temporaire.
            $this->recordRefreshFailure($refresh, $exception, CotationMarketRefresh::FAILURE_DEFINITIVE, $refresh->http_status, 1);
        }

        return $refresh;
    }

    /**
     * Date du dernier relevé ayant réellement fourni des cours, antérieur à l'actualisation donnée.
     */
    public function lastSuccessfulRefreshAt(CotationMarketRefresh $before): ?Carbon
    {
        $at = CotationMarketRefresh::query()
            ->where('is_success', true)
            ->where('row_count', '>', 0)
            ->where('id', '<', $before->id)
            ->max('fetched_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * Récupère la réponse de la source. Les indisponibilités temporaires (connexion impossible ou
     * délai dépassé, HTTP 408/425/429/5xx) sont retentées après une courte pause, dans la limite de
     * cotations.refresh.attempts ; la durée totale reste sous la minute séparant deux exécutions.
     *
     * @throws CotationSourceException
     */
    private function fetchSource(): Response
    {
        $maxAttempts = max(1, (int) config('cotations.refresh.attempts', 3));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::connectTimeout((int) config('cotations.refresh.connect_timeout', 5))
                    ->timeout((int) config('cotations.refresh.timeout', 10))
                    ->acceptJson()
                    ->get(self::EURONEXT_URL);

                if ($response->successful()) {
                    return $response;
                }

                $status = $response->status();
                $failure = new CotationSourceException(
                    'HTTP '.$status,
                    in_array($status, [408, 425, 429], true) || $status >= 500,
                    $status,
                );
            } catch (ConnectionException $exception) {
                $failure = new CotationSourceException($exception->getMessage(), true, null, 1, $exception);
            }

            $failure->attempts = $attempt;

            if (! $failure->transient || $attempt >= $maxAttempts) {
                throw $failure;
            }

            usleep(max(0, (int) config('cotations.refresh.retry_delay_ms', 1000)) * 1000);
        }
    }

    private function recordRefreshFailure(CotationMarketRefresh $refresh, Throwable $exception, string $kind, ?int $httpStatus, int $attempts): void
    {
        $refresh->failureKind = $kind;
        $refresh->forceFill([
            'http_status' => $httpStatus,
            'error_message' => mb_substr($exception->getMessage(), 0, 4000),
            'row_count' => 0,
        ])->save();

        $context = [
            'refresh_id' => $refresh->id,
            'source_url' => self::EURONEXT_URL,
            'http_status' => $httpStatus,
            'attempts' => $attempts,
            'error' => $exception->getMessage(),
        ];

        if ($kind === CotationMarketRefresh::FAILURE_TRANSIENT) {
            Log::warning('Cotations : source des cours temporairement indisponible', $context);

            return;
        }

        Log::error('Cotations : échec définitif de l\'actualisation des cours', $context + ['exception' => $exception]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestGroups(int $leftHarvestYear, int $rightHarvestYear, bool $includeEmptyProducts = false, bool $includeCustomProducts = false, bool $includeSalePrice = false): array
    {
        $wantedYears = [$leftHarvestYear, $rightHarvestYear];
        $marketRows = $this->latestMarketRowsByIdentity();
        $configuredRows = [];
        $groups = [];

        // Source de vérité pour le nom affiché d'une céréale personnalisée :
        // cotation_custom_cereals.name prime toujours sur le product_name
        // dénormalisé dans cotation_manual_prices (qui peut être en retard
        // d'une synchronisation après un renommage).
        $customCerealNameByCode = collect($this->customCereals())
            ->pluck('name', 'code')
            ->all();

        if (Schema::hasTable('cotation_manual_prices')) {
            $manualColumns = [
                    'id',
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
                    'base',
                    'base_operation',
                    'sale_price',
                    'sale_price_operation',
                    'sort_order',
            ];

            $hasFinalPriceReference = Schema::hasColumn('cotation_manual_prices', 'final_price_reference_key');
            if ($hasFinalPriceReference) {
                $manualColumns[] = 'final_price_reference_key';
            }

            CotationManualPrice::query()
                ->select($manualColumns)
                ->whereIn('harvest_year', $wantedYears)
                ->orderBy('product_sort')
                ->orderBy('product_name')
                ->orderBy('harvest_year')
                ->orderBy('sort_order')
                ->orderBy('maturity_label')
                ->get()
                ->each(function (CotationManualPrice $manual) use (&$configuredRows, $marketRows, $hasFinalPriceReference): void {
                    $lineType = $manual->line_type === 'matif' ? 'matif' : 'custom';
                    $marketIdentity = $lineType === 'matif' ? $manual->market_identity_hash : null;
                    $market = $marketIdentity ? ($marketRows[$marketIdentity] ?? null) : null;
                    $finalPriceReferenceKey = $hasFinalPriceReference && $lineType === 'custom'
                        ? (trim((string) $manual->final_price_reference_key) ?: null)
                        : null;
                    $matif = $lineType === 'matif'
                        ? ($market['matif'] ?? null)
                        : ($manual->manual_matif !== null ? (float) $manual->manual_matif : null);

                    $configuredRows[] = [
                        'identity_hash' => $manual->identity_hash,
                        'market_identity_hash' => $marketIdentity,
                        'line_type' => $lineType,
                        'manual_id' => $manual->id,
                        'product_code' => $manual->product_code,
                        'product_name' => $manual->product_name,
                        'product_sort' => (int) $manual->product_sort,
                        'contract_code' => $market['contract_code'] ?? $manual->contract_code,
                        'display_label' => $manual->display_label,
                        'label' => $manual->maturity_label,
                        'maturity_month' => $manual->maturity_month,
                        'maturity_year' => (int) $manual->maturity_year,
                        'harvest_year' => (int) $manual->harvest_year,
                        'matif' => $matif,
                        'euronext_price' => $market['matif'] ?? null,
                        'manual_matif' => $manual->manual_matif !== null ? (float) $manual->manual_matif : null,
                        'final_price_reference_key' => $finalPriceReferenceKey,
                        'base' => $manual->base !== null ? abs((float) $manual->base) : null,
                        'base_operation' => CotationManualPrice::normalizeBaseOperation($manual->base_operation),
                        'sale_price' => $manual->sale_price !== null ? abs((float) $manual->sale_price) : null,
                        'sale_price_operation' => CotationManualPrice::normalizeSaleOperation($manual->sale_price_operation),
                        'sort' => (int) $manual->sort_order,
                        'last_seen_at' => $market['last_seen_at'] ?? null,
                        'has_euronext' => $lineType === 'matif' && $market !== null,
                    ];
            });
        }

        // Les prix des céréales supprimées restent utilisés pour résoudre les références (assembleGroups) :
        // seule leur carte est retirée de la vue courante.
        return $this->withoutDeleted($this->assembleGroups(
            $configuredRows,
            $marketRows,
            $leftHarvestYear,
            $rightHarvestYear,
            $includeEmptyProducts,
            $includeCustomProducts,
            $customCerealNameByCode,
            $includeSalePrice,
        ));
    }

    /**
     * Ligne de marché normalisée à partir d'un enregistrement de cotation_market_prices.
     *
     * @return array<string, mixed>
     */
    public function marketRowFromPrice(object $price): array
    {
        $identity = CotationManualPrice::identityHash(
            (string) $price->product_code,
            (int) $price->harvest_year,
            (int) $price->maturity_year,
            $price->maturity_month !== null ? (int) $price->maturity_month : null,
            (string) $price->maturity_label,
        );

        return [
            'identity_hash' => $identity,
            'product_code' => $price->product_code,
            'product_name' => $price->product_name,
            'product_sort' => (int) $price->product_sort,
            'contract_code' => $price->contract_code,
            'label' => $price->maturity_label,
            'maturity_label' => $price->maturity_label,
            'maturity_month' => $price->maturity_month,
            'maturity_year' => (int) $price->maturity_year,
            'harvest_year' => (int) $price->harvest_year,
            'matif' => (float) $price->price,
            'sort' => (int) $price->maturity_sort,
            'last_seen_at' => $price->created_at ? Carbon::parse($price->created_at)->toIso8601String() : null,
        ];
    }

    /**
     * Ligne configurée telle qu'elle était à une date passée, à partir de son
     * instantané d'audit (mêmes champs que le calcul courant).
     *
     * @param  array<string, mixed>  $snapshot  forme de CotationController::manualPriceSnapshot()
     * @param  array<string, array<string, mixed>>  $marketRows
     * @return array<string, mixed>
     */
    public function configuredRowFromSnapshot(array $snapshot, array $marketRows, int $productSort): array
    {
        $lineType = ($snapshot['line_type'] ?? null) === 'matif' ? 'matif' : 'custom';
        $marketIdentity = $lineType === 'matif' ? ($snapshot['market_identity_hash'] ?? null) : null;
        $market = $marketIdentity ? ($marketRows[$marketIdentity] ?? null) : null;
        $reference = $lineType === 'custom' ? (trim((string) ($snapshot['final_price_reference_key'] ?? '')) ?: null) : null;
        $manualMatif = isset($snapshot['manual_matif']) && $snapshot['manual_matif'] !== null ? (float) $snapshot['manual_matif'] : null;
        // Journaux d'audit antérieurs au renommage : la Base y était enregistrée sous « margin »
        // / « margin_operation » (« margin » désigne désormais la marge calculée, non utilisée ici).
        $isLegacySnapshot = ! array_key_exists('base', $snapshot);
        $snapshotBase = $isLegacySnapshot ? ($snapshot['margin'] ?? null) : $snapshot['base'];
        $snapshotBaseOperation = $isLegacySnapshot ? ($snapshot['margin_operation'] ?? null) : ($snapshot['base_operation'] ?? null);

        return [
            'identity_hash' => $snapshot['identity_hash'] ?? null,
            'market_identity_hash' => $marketIdentity,
            'line_type' => $lineType,
            'manual_id' => null,
            'product_code' => $snapshot['product_code'],
            'product_name' => $snapshot['product_name'],
            'product_sort' => $productSort,
            'contract_code' => $market['contract_code'] ?? null,
            'display_label' => $snapshot['display_label'] ?? null,
            'label' => $snapshot['maturity_label'] ?? '',
            'maturity_month' => $snapshot['maturity_month'] ?? null,
            'maturity_year' => (int) $snapshot['maturity_year'],
            'harvest_year' => (int) $snapshot['harvest_year'],
            'matif' => $lineType === 'matif' ? ($market['matif'] ?? null) : $manualMatif,
            'euronext_price' => $market['matif'] ?? null,
            'manual_matif' => $manualMatif,
            'final_price_reference_key' => $reference,
            'base' => $snapshotBase !== null ? abs((float) $snapshotBase) : null,
            'base_operation' => CotationManualPrice::normalizeBaseOperation($snapshotBaseOperation),
            'sort' => (int) ($snapshot['sort_order'] ?? 0),
            'last_seen_at' => $market['last_seen_at'] ?? null,
            'has_euronext' => $lineType === 'matif' && $market !== null,
        ];
    }

    /**
     * Assemble les cartes (produit → récoltes → lignes) à partir de lignes
     * configurées et de cours de marché. Partagé par l'affichage courant et par
     * la reconstitution de l'historique : une seule logique de calcul (MATIF,
     * base, prix final, références), donc un rendu identique.
     *
     * @param  array<int, array<string, mixed>>  $configuredRows
     * @param  array<string, array<string, mixed>>  $marketRows
     * @param  array<string, string>  $customCerealNameByCode
     * @return array<int, array<string, mixed>>
     */
    public function assembleGroups(array $configuredRows, array $marketRows, int $leftHarvestYear, int $rightHarvestYear, bool $includeEmptyProducts, bool $includeCustomProducts, array $customCerealNameByCode, bool $includeSalePrice = false): array
    {
        $groups = [];
        $configuredRows = $this->resolveFinalPriceReferences($configuredRows, $marketRows);

        if ($includeEmptyProducts) {
            foreach (self::BASE_PRODUCT_CODES as $code) {
                $groups[$code] ??= $this->blankGroup($code, self::PRODUCT_CODES[$code], $leftHarvestYear, $rightHarvestYear);
            }
        }

        if ($includeCustomProducts) {
            foreach ($this->customCereals() as $cereal) {
                $groups[$cereal['code']] ??= $this->blankGroup($cereal['code'], [
                    'name' => $cereal['name'],
                    'sort' => $cereal['sort'],
                    'base_product_code' => $cereal['base_product_code'],
                ], $leftHarvestYear, $rightHarvestYear);
            }
        }

        foreach ($configuredRows as $row) {
            $productKey = $row['product_code'];
            $displayName = $customCerealNameByCode[$productKey] ?? $row['product_name'];
            $groups[$productKey] ??= [
                'code' => $row['product_code'],
                'name' => $displayName,
                'sort' => $row['product_sort'],
                'harvests' => [
                    'left' => ['year' => $leftHarvestYear, 'rows' => []],
                    'right' => ['year' => $rightHarvestYear, 'rows' => []],
                ],
            ];

            $bucket = (int) $row['harvest_year'] === $leftHarvestYear ? 'left' : 'right';
            $matif = $row['matif'];
            $base = $row['base'] !== null ? abs((float) $row['base']) : null;
            $baseOperation = CotationManualPrice::normalizeBaseOperation($row['base_operation'] ?? null);
            $groups[$productKey]['harvests'][$bucket]['rows'][] = [
                'identity_hash' => $row['identity_hash'],
                'market_identity_hash' => $row['market_identity_hash'],
                'line_type' => $row['line_type'],
                'manual_id' => $row['manual_id'] ?? null,
                'code' => $row['contract_code'],
                'label' => $row['label'],
                'display_label' => $row['display_label'],
                'maturity_label' => $row['label'],
                'product_code' => $row['product_code'],
                'product_name' => $displayName,
                'product_sort' => $row['product_sort'],
                'maturity_month' => $row['maturity_month'],
                'maturity_year' => $row['maturity_year'],
                'harvest_year' => $row['harvest_year'],
                'price' => $matif,
                'matif' => $matif,
                'euronext_price' => $row['euronext_price'],
                'manual_matif' => $row['manual_matif'],
                'final_price_reference_key' => $row['final_price_reference_key'] ?? null,
                'base' => $base,
                'base_operation' => $baseOperation,
                'final_price' => $matif !== null ? CotationManualPrice::applyBase((float) $matif, $base, $baseOperation) : null,
                // Prix de vente : jamais exposé hors mode modification (consultation, export, historique).
                ...($includeSalePrice ? [
                    'sale_price' => $row['sale_price'] ?? null,
                    'sale_price_operation' => CotationManualPrice::normalizeSaleOperation($row['sale_price_operation'] ?? null),
                ] : []),
                'sort' => $row['sort'],
                'last_seen_at' => $row['last_seen_at'],
                'has_euronext' => (bool) $row['has_euronext'],
            ];
        }

        foreach ($groups as &$group) {
            foreach (['left', 'right'] as $bucket) {
                usort(
                    $group['harvests'][$bucket]['rows'],
                    static fn (array $left, array $right): int => ($left['sort'] <=> $right['sort']) ?: strcmp((string) $left['label'], (string) $right['label']),
                );
            }
        }
        unset($group);

        usort($groups, static fn (array $left, array $right): int => ($left['sort'] <=> $right['sort']) ?: strcmp((string) $left['name'], (string) $right['name']));

        return array_values($groups);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * Échéances MATIF proposées à la sélection, par céréale : celles que la source fournit réellement,
     * c'est-à-dire présentes dans la dernière actualisation réussie (les échéances échues ne le sont plus).
     * Indépendantes de l'année de récolte : les deux colonnes (récolte gauche et droite) reçoivent la même
     * liste, et une céréale personnalisée reprend celle de sa céréale MATIF d'origine.
     *
     * @return array<int, array<string, mixed>>
     */
    public function marketOptions(int $leftHarvestYear, int $rightHarvestYear): array
    {
        $rowsByProduct = [];

        foreach ($this->activeMarketRows() as $row) {
            $rowsByProduct[$row['product_code']][] = $row;
        }

        foreach ($rowsByProduct as &$rows) {
            usort($rows, static fn (array $left, array $right): int => ($left['sort'] <=> $right['sort']) ?: strcmp((string) $left['label'], (string) $right['label']));
        }
        unset($rows);

        $harvestsFor = static fn (array $rows): array => [
            'left' => ['year' => $leftHarvestYear, 'rows' => $rows],
            'right' => ['year' => $rightHarvestYear, 'rows' => $rows],
        ];

        $groups = [];

        foreach (self::BASE_PRODUCT_CODES as $code) {
            $first = $rowsByProduct[$code][0] ?? null;
            $groups[$code] = [
                'code' => $code,
                'name' => $first['product_name'] ?? self::PRODUCT_CODES[$code]['name'],
                'sort' => $first['product_sort'] ?? self::PRODUCT_CODES[$code]['sort'],
                'base_product_code' => $code,
                'harvests' => $harvestsFor($rowsByProduct[$code] ?? []),
            ];
        }

        foreach ($this->customCereals() as $cereal) {
            $groups[$cereal['code']] = [
                'code' => $cereal['code'],
                'name' => $cereal['name'],
                'sort' => $cereal['sort'],
                'base_product_code' => $cereal['base_product_code'],
                'harvests' => $harvestsFor($rowsByProduct[$cereal['base_product_code']] ?? []),
            ];
        }

        usort($groups, static fn (array $left, array $right): int => ($left['sort'] <=> $right['sort']) ?: strcmp((string) $left['name'], (string) $right['name']));

        return array_values($groups);
    }

    /**
     * Céréale MATIF d'origine d'un code de céréale : lui-même pour une céréale MATIF de base, la
     * référence choisie pour une céréale personnalisée ; null si le code est inconnu.
     */
    public function baseProductCodeFor(string $productCode): ?string
    {
        $code = mb_strtoupper(trim($productCode), 'UTF-8');

        if (in_array($code, self::BASE_PRODUCT_CODES, true)) {
            return $code;
        }

        foreach ($this->customCereals() as $cereal) {
            if ($cereal['code'] === $code) {
                return $cereal['base_product_code'];
            }
        }

        return null;
    }

    /**
     * Identités des échéances actives (proposées à la sélection) d'une céréale MATIF de base.
     *
     * @return array<int, string>
     */
    public function activeIdentityHashes(string $baseProductCode): array
    {
        return array_keys(array_filter(
            $this->activeMarketRows(),
            static fn (array $row): bool => $row['product_code'] === $baseProductCode,
        ));
    }

    /**
     * Dernière actualisation ayant réellement fourni des cours : référence de ce qui est « actif ».
     */
    private function activeRefreshId(): ?int
    {
        if (! Schema::hasTable('cotation_market_refreshes')) {
            return null;
        }

        $id = CotationMarketRefresh::query()
            ->where('is_success', true)
            ->where('row_count', '>', 0)
            ->max('id');

        return $id ? (int) $id : null;
    }

    /**
     * Cours de la dernière actualisation utile (échéances encore cotées), par identité.
     *
     * @return array<string, array<string, mixed>>
     */
    private function activeMarketRows(): array
    {
        $refreshId = $this->activeRefreshId();

        if ($refreshId === null || ! Schema::hasTable('cotation_market_prices')) {
            return [];
        }

        $rows = [];

        DB::table('cotation_market_prices')
            ->select([
                'id', 'product_code', 'product_name', 'product_sort', 'contract_code', 'maturity_label',
                'maturity_month', 'maturity_year', 'harvest_year', 'price', 'maturity_sort', 'created_at',
            ])
            ->where('refresh_id', $refreshId)
            ->whereIn('product_code', self::BASE_PRODUCT_CODES)
            ->orderBy('id')
            ->get()
            ->each(function (object $price) use (&$rows): void {
                $row = $this->marketRowFromPrice($price);
                $rows[$row['identity_hash']] ??= $row;
            });

        return $rows;
    }

    /**
     * Dernier cours connu de chaque échéance MATIF, y compris les échéances échues (qui ne sont plus
     * proposées mais restent consultables sur les lignes qui les référencent). Aucun filtre d'année.
     *
     * @return array<string, array<string, mixed>>
     */
    private function latestMarketRowsByIdentity(): array
    {
        $rows = [];

        if (! Schema::hasTable('cotation_market_prices')) {
            return $rows;
        }

        $latestIds = DB::table('cotation_market_prices')
            ->selectRaw('MAX(id) as id')
            ->whereIn('product_code', self::BASE_PRODUCT_CODES)
            ->groupBy('product_code', 'harvest_year', 'maturity_year', 'maturity_month', 'maturity_label')
            ->pluck('id')
            ->all();

        if ($latestIds === []) {
            return $rows;
        }

        DB::table('cotation_market_prices')
            ->select([
                'id',
                'product_code',
                'product_name',
                'product_sort',
                'contract_code',
                'maturity_label',
                'maturity_month',
                'maturity_year',
                'harvest_year',
                'price',
                'maturity_sort',
                'created_at',
            ])
            ->whereIn('id', $latestIds)
            ->orderBy('product_sort')
            ->orderBy('product_name')
            ->orderBy('harvest_year')
            ->orderBy('maturity_sort')
            ->get()
            ->each(function (object $price) use (&$rows): void {
                $row = $this->marketRowFromPrice($price);

                // Premier rencontré (ordre produit/récolte/échéance) conservé en cas de doublon d'identité.
                $rows[$row['identity_hash']] ??= $row;
            });

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $configuredRows
     * @param  array<string, array<string, mixed>>  $marketRows
     * @return array<int, array<string, mixed>>
     */
    private function resolveFinalPriceReferences(array $configuredRows, array $marketRows): array
    {
        $rowsByReference = [];

        foreach ($marketRows as $row) {
            $rowsByReference[$this->finalPriceReferenceKey($row)] = $row + [
                'base' => null,
                'base_operation' => CotationManualPrice::BASE_SUBTRACT,
                'final_price_reference_key' => null,
            ];
        }

        foreach ($configuredRows as $row) {
            $rowsByReference[$this->finalPriceReferenceKey($row)] = $row;
        }

        $resolvedFinalPrices = [];
        $resolveFinalPrice = function (string $referenceKey, array $stack = []) use (&$resolveFinalPrice, &$resolvedFinalPrices, $rowsByReference): ?float {
            if (array_key_exists($referenceKey, $resolvedFinalPrices)) {
                return $resolvedFinalPrices[$referenceKey];
            }

            if (in_array($referenceKey, $stack, true) || ! isset($rowsByReference[$referenceKey])) {
                return $resolvedFinalPrices[$referenceKey] = null;
            }

            $row = $rowsByReference[$referenceKey];
            $matif = $row['matif'] ?? null;
            $sourceReference = trim((string) ($row['final_price_reference_key'] ?? ''));

            if ($sourceReference !== '') {
                $matif = $resolveFinalPrice($sourceReference, [...$stack, $referenceKey]);
            }

            if ($matif === null || $matif === '') {
                return $resolvedFinalPrices[$referenceKey] = null;
            }

            return $resolvedFinalPrices[$referenceKey] = CotationManualPrice::applyBase(
                (float) $matif,
                $row['base'] ?? null,
                $row['base_operation'] ?? null,
            );
        };

        foreach ($configuredRows as &$row) {
            $sourceReference = trim((string) ($row['final_price_reference_key'] ?? ''));
            if ($sourceReference === '') {
                continue;
            }

            $row['matif'] = $resolveFinalPrice($sourceReference);
            if (isset($rowsByReference[$sourceReference])) {
                $sourceRow = $rowsByReference[$sourceReference];
                $row['label'] = $sourceRow['label'] ?? $sourceRow['maturity_label'] ?? $row['label'];
                $row['maturity_month'] = $sourceRow['maturity_month'] ?? null;
                $row['maturity_year'] = $sourceRow['maturity_year'] ?? $row['maturity_year'];
            }
        }
        unset($row);

        return $configuredRows;
    }

    /**
     * Mirrors transportReferenceKey() in resources/js/Pages/Cotations/Index.jsx.
     *
     * @param  array<string, mixed>  $row
     */
    private function finalPriceReferenceKey(array $row): string
    {
        if (! empty($row['identity_hash'])) {
            return 'identity:'.$row['identity_hash'];
        }

        if (! empty($row['manual_id'])) {
            return 'manual:'.$row['manual_id'];
        }

        return 'line:'.($row['product_code'] ?? '').':'.($row['harvest_year'] ?? '').':'.($row['display_label'] ?? $row['label'] ?? $row['maturity_label'] ?? '');
    }

    /**
     * @return array<int, array{code:string,name:string,base_product_code:string,sort:int}>
     */
    private function customCereals(): array
    {
        if (! Schema::hasTable('cotation_custom_cereals')) {
            return [];
        }

        $deleted = $this->deletedCereals();

        return DB::table('cotation_custom_cereals')
            ->select(['code', 'name', 'base_product_code', 'sort_order'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (object $cereal): array => [
                'code' => $cereal->code,
                'name' => $cereal->name,
                'base_product_code' => $cereal->base_product_code,
                'sort' => (int) $cereal->sort_order,
            ])
            ->reject(fn (array $cereal): bool => isset($deleted[$cereal['code']]))
            ->values()
            ->all();
    }

    /**
     * @param  array{name:string,sort:int,base_product_code?:string,aliases?:array<int,string>}  $config
     * @return array<string, mixed>
     */
    private function blankGroup(string $code, array $config, int $leftHarvestYear, int $rightHarvestYear): array
    {
        return [
            'code' => $code,
            'name' => $config['name'],
            'sort' => $config['sort'],
            'base_product_code' => $config['base_product_code'] ?? $code,
            'harvests' => [
                'left' => ['year' => $leftHarvestYear, 'rows' => []],
                'right' => ['year' => $rightHarvestYear, 'rows' => []],
            ],
        ];
    }

    public function lastRefresh(): ?CotationMarketRefresh
    {
        if (! Schema::hasTable('cotation_market_refreshes')) {
            return null;
        }

        return CotationMarketRefresh::query()->latest('fetched_at')->first();
    }

    /**
     * @param  array<int, array<mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $prices = [];

        foreach ($rows as $row) {
            $product = $this->resolveProduct($row);
            if (! $product) continue;

            $maturity = $this->resolveMaturity($row);
            if (! $maturity || ! $maturity['year']) continue;

            $rawPrice = $this->rowValue($row, ['price', 'prix', 'last', 'value', 'cours', 'settlement', 'close', 'dernier']);
            $price = $this->normalizePrice($rawPrice);
            if ($price === null) continue;

            $harvestYear = $this->resolveHarvestYear($maturity);

            $prices[] = [
                'product_code' => $product['code'],
                'product_name' => $product['name'],
                'product_sort' => $product['sort'],
                'contract_code' => $this->resolveContractCode($row),
                'maturity_label' => $maturity['label'],
                'maturity_month' => $maturity['month'],
                'maturity_year' => $maturity['year'],
                'harvest_year' => $harvestYear,
                'price' => $price,
                'raw_price' => $rawPrice !== null ? (string) $rawPrice : null,
                'maturity_sort' => $maturity['sort'],
                'quoted_at' => $this->parseQuotedAt($this->rowValue($row, ['updated_at', 'updated', 'time', 'heure', 'date', 'datetime', 'timestamp'])),
            ];
        }

        return $prices;
    }

    private function normalizePrice(mixed $value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '') return null;

        $normalized = str_replace(',', '.', preg_replace('/[^\d,\.\-]/', '', $raw) ?? '');
        if ($normalized === '' || ! is_numeric($normalized)) return null;

        return (float) $normalized;
    }

    private function parseQuotedAt(mixed $value): ?Carbon
    {
        $raw = trim((string) $value);
        if ($raw === '') return null;

        try {
            return Carbon::parse($raw, config('app.timezone', 'Europe/Paris'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<mixed>>
     */
    private function extractHtmlRows(string $html): array
    {
        $rows = [];
        if (trim($html) === '') return [];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//tr') ?: [] as $tr) {
            $cells = [];
            foreach ($xpath->query('.//th|.//td', $tr) ?: [] as $cell) {
                $text = trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? '');
                if ($text !== '') $cells[] = $text;
            }
            if (count($cells) < 2) continue;

            $rows[] = [
                'contract' => implode(' ', array_slice($cells, 0, max(1, count($cells) - 1))),
                'price' => $cells[count($cells) - 1] ?? null,
                'raw' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int, array<mixed>>
     */
    private function extractRows(array $payload): array
    {
        foreach (['data', 'rows', 'cours', 'quotes', 'items', 'markets'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->extractRows($payload[$key]);
            }
        }

        $rows = [];
        foreach ($payload as $key => $item) {
            if (is_array($item) && $this->looksLikeMarketRow($item)) {
                if (is_string($key) && ! is_numeric($key) && ! $this->rowValue($item, ['symbol', 'symbole', 'code', 'contract', 'contrat', 'instrument', 'mnemonic'])) {
                    $item['contract'] = $key;
                }
                $rows[] = $item;
                continue;
            }

            if (is_array($item)) {
                $nestedRows = $this->extractRows($item);
                if (is_string($key) && ! is_numeric($key)) {
                    $nestedRows = array_map(function (array $row) use ($key): array {
                        if ($this->looksLikeContractCode($key) && ! $this->rowValue($row, ['symbol', 'symbole', 'code', 'contract', 'contrat', 'instrument', 'mnemonic'])) {
                            $row['contract'] = $key;
                        } elseif (! $this->rowValue($row, ['commodity', 'cereal', 'cereale', 'céréale', 'produit', 'product', 'culture'])) {
                            $row['commodity'] = $key;
                        }

                        return $row;
                    }, $nestedRows);
                }

                $rows = [...$rows, ...$nestedRows];
                continue;
            }

            if (is_string($key) && ! is_numeric($key) && $this->looksLikeContractCode($key) && is_scalar($item)) {
                $rows[] = ['contract' => $key, 'price' => $item];
            }
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function looksLikeMarketRow(array $row): bool
    {
        $keys = array_map(static fn ($key): string => strtolower((string) $key), array_keys($row));

        return count(array_intersect($keys, [
            'prix', 'price', 'last', 'value', 'cours', 'settlement', 'close', 'dernier',
            'code', 'symbol', 'symbole', 'contract', 'contrat', 'echeance', 'échéance', 'maturity',
        ])) > 0;
    }

    /**
     * @param  array<mixed>  $row
     * @return array{code:string,name:string,sort:int}|null
     */
    private function resolveProduct(array $row): ?array
    {
        $code = $this->resolveProductCode($row);
        if ($code && isset(self::PRODUCT_CODES[$code])) {
            return ['code' => $code, ...self::PRODUCT_CODES[$code]];
        }

        $text = mb_strtoupper($this->rowSearchText($row), 'UTF-8');
        foreach (self::PRODUCT_CODES as $candidateCode => $config) {
            foreach ($config['aliases'] as $alias) {
                if (str_contains($text, mb_strtoupper($alias, 'UTF-8'))) {
                    return ['code' => $candidateCode, ...$config];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function resolveProductCode(array $row): ?string
    {
        $contract = $this->resolveContractCode($row);
        if ($contract && preg_match('/\b(E[A-Z]{2})\b/i', $contract, $matches)) {
            return strtoupper($matches[1]);
        }

        $text = $this->rowSearchText($row);
        if (preg_match('/\b(ECO|EBM|EMA|EOB|EOR|ERS|ETR)\b/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function looksLikeContractCode(string $value): bool
    {
        return (bool) preg_match('/\b(ECO|EBM|EMA|EOB|EOR|ERS|ETR)[A-Z]?\d{1,4}\b/i', $value);
    }

    /**
     * @param  array<mixed>  $row
     */
    private function resolveContractCode(array $row): ?string
    {
        $value = $this->rowValue($row, ['symbol', 'symbole', 'code', 'contract', 'contrat', 'instrument', 'isin', 'mnemonic']);
        if ($value !== null && trim((string) $value) !== '') return trim((string) $value);

        $text = $this->rowSearchText($row);
        if (preg_match('/\b(ECO|EBM|EMA|EOB|EOR|ERS|ETR)[A-Z]?\d{1,4}\b/i', $text, $matches)) {
            return strtoupper($matches[0]);
        }

        return null;
    }

    /**
     * @param  array<mixed>  $row
     * @return array{label:string,month:?int,year:?int,sort:int}|null
     */
    private function resolveMaturity(array $row): ?array
    {
        foreach ([
            $this->rowValue($row, ['maturity', 'echeance', 'échéance', 'expiry', 'expiration', 'contract', 'contrat', 'label', 'libelle', 'libellé']),
            $this->resolveContractCode($row),
            $this->rowSearchText($row),
        ] as $value) {
            $maturity = $this->parseMaturity((string) $value);
            if ($maturity) return $maturity;
        }

        return null;
    }

    /**
     * @return array{label:string,month:?int,year:?int,sort:int}|null
     */
    private function parseMaturity(string $value): ?array
    {
        $text = mb_strtoupper(trim($value), 'UTF-8');
        if ($text === '') return null;

        if (preg_match('/\b(?:ECO|EBM|EMA|EOB|EOR|ERS|ETR)?([FGHJKMNQUVXZ])\s*([2-9]\d{0,3})\b/u', $text, $matches)) {
            $month = self::FUTURES_MONTHS[$matches[1]];
            $year = $this->normalizeYearToken($matches[2]);

            return ['label' => $month['label'].' '.$year, 'month' => $month['number'], 'year' => $year, 'sort' => ($year * 100) + $month['number']];
        }

        foreach (self::MONTH_ALIASES as $alias => $month) {
            if (preg_match('/\b'.preg_quote($alias, '/').'\.?\s*(20\d{2}|[2-9]\d)\b/u', $text, $matches)) {
                $year = $this->normalizeYearToken($matches[1]);

                return ['label' => $month['label'].' '.$year, 'month' => $month['number'], 'year' => $year, 'sort' => ($year * 100) + $month['number']];
            }
        }

        return null;
    }

    private function normalizeYearToken(string $value): int
    {
        $year = (int) $value;
        if ($year < 100) return 2000 + $year;
        if ($year < 1000) return 2000 + ($year % 100);

        return $year;
    }

    /**
     * @param  array{month:?int,year:?int,label:string,sort:int}  $maturity
     */
    private function resolveHarvestYear(array $maturity): int
    {
        return (int) $maturity['year'];
    }

    private function extractYear(string $value): ?int
    {
        if (preg_match('/20\d{2}/', $value, $matches)) return (int) $matches[0];
        if (preg_match('/\b([2-9]\d)\b/', $value, $matches)) return 2000 + (int) $matches[1];

        return null;
    }

    /**
     * @param  array<mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function rowValue(array $row, array $keys): mixed
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[mb_strtolower((string) $key, 'UTF-8')] = $value;
        }

        foreach ($keys as $key) {
            $needle = mb_strtolower($key, 'UTF-8');
            if (array_key_exists($needle, $normalized) && $normalized[$needle] !== null && $normalized[$needle] !== '') {
                return is_scalar($normalized[$needle]) ? $normalized[$needle] : null;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function rowSearchText(array $row): string
    {
        $parts = [];
        foreach ($row as $value) {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            } elseif (is_array($value)) {
                foreach ($value as $nested) {
                    if (is_scalar($nested)) $parts[] = (string) $nested;
                }
            }
        }

        return implode(' ', $parts);
    }
}

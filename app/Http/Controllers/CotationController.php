<?php

namespace App\Http\Controllers;

use App\Models\CotationCustomCereal;
use App\Models\CotationFuelPriceHistory;
use App\Models\CotationManualPrice;
use App\Models\CotationSetting;
use App\Services\AuditLogService;
use App\Services\Cotations\CotationConfigHistoryService;
use App\Services\Cotations\CotationMarketHistoryService;
use App\Services\Cotations\CotationMarketService;
use App\Support\Access\AccessManager;
use App\Support\Cotations\CerealInfoSanitizer;
use App\Support\Cotations\CotationFuelCalculator;
use App\Support\Cotations\CotationPdfFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

class CotationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly CotationMarketService $marketService,
    ) {
    }

    public function index(Request $request)
    {
        try {
            $access = app(AccessManager::class);
            $user = $request->user();

            $canEditCereals = (bool) ($user && $access->can($user, 'cotations.cereals.edit'));
            $canEditFuel = (bool) ($user && $access->can($user, 'cotations.fuel.edit'));
            $canViewCereals = (bool) ($user && ($access->can($user, 'cotations.cereals.view') || $canEditCereals));
            $canViewFuel = (bool) ($user && ($access->can($user, 'cotations.fuel.view') || $canEditFuel));
            $canViewCerealHistory = (bool) ($user && $canViewCereals && $access->can($user, 'cotations.cereals.history.view'));
            $canViewFuelHistory = (bool) ($user && $access->can($user, 'cotations.fuel.history.view'));
            $canAdmin = (bool) ($user && $access->can($user, 'cotations.admin'));

            $marketData = [
                'source' => 'database',
                'source_url' => CotationMarketService::EURONEXT_URL,
                'fetched_at' => null,
                'last_refresh' => null,
                'harvest_years' => [
                    'left' => now()->year,
                    'right' => now()->year + 1,
                ],
                'leftYear' => now()->year,
                'rightYear' => now()->year + 1,
                'availableYears' => [now()->year, now()->year + 1],
                'groups' => [],
                'cereal_order' => [],
                'options' => [],
                'manualRows' => [],
                'customCereals' => [],
                'custom_cereals' => [],
                'cereal_info_html' => $this->cerealInfoConfig(),
            ];

            $fuelGrid = $this->fuelGridConfig();

            $manualSettings = $this->manualSettings();
            $displaySettings = $this->displaySettings();
            $cerealLabels = $this->cerealLabelsConfig();
            $cerealTableLabels = $this->cerealTableLabelsConfig();
            $transportGrid = $this->transportGridConfig();

            $can = [
                'viewCereals' => $canViewCereals,
                'viewFuel' => $canViewFuel,
                'editCereals' => $canEditCereals,
                'editFuel' => $canEditFuel,
                'admin' => $canAdmin,
            ];

            return Inertia::render('Cotations/Index', [
                'can' => $can,
                'market' => $marketData,
                'fuel' => $fuelGrid,
                'manualSettings' => $manualSettings,
                'displaySettings' => $displaySettings,
                'cerealLabels' => $cerealLabels,
                'cerealTableLabels' => $cerealTableLabels,
                'transportGrid' => $transportGrid,
                'fuelGrid' => $fuelGrid,
                'marketData' => $marketData,
                'permissions' => [
                    'can_view_cereals' => $canViewCereals,
                    'can_view_fuel' => $canViewFuel,
                    'can_manage' => $canEditCereals,
                    'can_manage_fuel' => $canEditFuel,
                    'can_view_cereal_history' => $canViewCerealHistory,
                    'can_view_fuel_history' => $canViewFuelHistory,
                    'can_admin' => $canAdmin,
                ],
                'routes' => [
                    'market_data' => route('cotations.market-data'),
                    'settings_update' => route('cotations.settings.update'),
                    'cereal_visibility_base' => url('/cotations/cereals'),
                    'fuel_settings_update' => route('cotations.fuel-settings.update'),
                    'fuel_history' => route('cotations.fuel-history'),
                    'cereal_history_dates' => route('cotations.cereal-history.dates'),
                    'cereal_history_series' => route('cotations.cereal-history.series'),
                    'cereal_history_base' => url('/cotations/cereal-history'),
                    'export_pdf' => route('cotations.export-pdf'),
                    'mail_draft' => route('cotations.mail-draft'),
                    'send_mail' => route('cotations.send-mail'),
                    'mail_base' => url('/cotations/mail'),
                    'export_fuel_pdf' => route('cotations.export-fuel-pdf'),
                    'admin' => route('admin.cotations.index'),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('cotations.index failed', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return response('Erreur cotations : '.$exception->getMessage(), 500);
        }
    }

    public function marketData(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $access = app(AccessManager::class);
            if (! $user || (! $access->can($user, 'cotations.cereals.view') && ! $access->can($user, 'cotations.cereals.edit'))) {
                return response()->json($this->emptyMarketDataResponse());
            }

            return response()->json([
                'ok' => true,
                ...$this->marketPayload($request),
            ]);
        } catch (Throwable $exception) {
            Log::error('cotations.market-data failed', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json($this->emptyMarketDataResponse());
        }
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        // Un formulaire resté ouvert après la suppression d'une céréale ne doit ni la ressusciter
        // ni modifier ses données conservées.
        $deletedCodes = array_keys($this->marketService->deletedCereals());
        $deletedCustomIds = $deletedCodes === [] || ! Schema::hasTable('cotation_custom_cereals')
            ? []
            : CotationCustomCereal::query()->whereIn('code', $deletedCodes)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $request->merge([
            'manual_prices' => collect($this->filledManualPriceRows($request->input('manual_prices', [])))
                ->reject(fn (array $row): bool => in_array((string) ($row['product_code'] ?? ''), $deletedCodes, true))
                ->values()
                ->all(),
            'custom_cereals' => collect($request->input('custom_cereals', []))
                ->filter(fn ($row): bool => is_array($row) && (trim((string) ($row['name'] ?? '')) !== '' || ! empty($row['id'])))
                ->reject(fn (array $row): bool => in_array((string) ($row['code'] ?? ''), $deletedCodes, true)
                    || in_array((int) ($row['id'] ?? 0), $deletedCustomIds, true))
                ->values()
                ->all(),
        ]);

        $settings = CotationSetting::query()->orderBy('section')->orderBy('sort_order')->get();
        $ids = $settings->pluck('id')->map(fn (int $id): string => (string) $id)->all();

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.id' => ['required', Rule::in($ids)],
            'settings.*.value' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'settings.*.note' => ['nullable', 'string', 'max:5000'],
            'manual_prices' => ['nullable', 'array'],
            'manual_prices.*.manual_id' => ['nullable', 'integer'],
            'manual_prices.*.identity_hash' => ['nullable', 'string', 'size:40'],
            'manual_prices.*.market_identity_hash' => ['nullable', 'string', 'size:40'],
            'manual_prices.*.line_type' => ['nullable', Rule::in(['matif', 'custom'])],
            'manual_prices.*.product_code' => ['required_with:manual_prices', 'string', 'max:16'],
            'manual_prices.*.product_name' => ['required_with:manual_prices', 'string', 'max:255'],
            'manual_prices.*.product_sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_prices.*.contract_code' => ['nullable', 'string', 'max:255'],
            'manual_prices.*.display_label' => ['nullable', 'string', 'max:255'],
            'manual_prices.*.maturity_label' => ['nullable', 'string', 'max:255'],
            'manual_prices.*.maturity_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'manual_prices.*.maturity_year' => ['required_with:manual_prices', 'integer', 'min:2020', 'max:2100'],
            'manual_prices.*.harvest_year' => ['required_with:manual_prices', 'integer', 'min:2020', 'max:2100'],
            'manual_prices.*.manual_matif' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'manual_prices.*.final_price_reference_key' => ['nullable', 'string', 'max:180'],
            'manual_prices.*.base' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'manual_prices.*.base_operation' => ['nullable', Rule::in(CotationManualPrice::BASE_OPERATIONS)],
            'manual_prices.*.sale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'manual_prices.*.sale_price_operation' => ['nullable', Rule::in(CotationManualPrice::BASE_OPERATIONS)],
            'manual_prices.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'deleted_manual_price_ids' => ['nullable', 'array'],
            'deleted_manual_price_ids.*' => ['integer', 'min:1'],
            'custom_cereals' => ['nullable', 'array'],
            'custom_cereals.*.id' => ['nullable', 'integer'],
            'custom_cereals.*.code' => ['nullable', 'string', 'max:16'],
            'custom_cereals.*.name' => ['required_with:custom_cereals', 'string', 'max:255'],
            'custom_cereals.*.base_product_code' => ['required_with:custom_cereals', Rule::in(['ECO', 'EBM', 'EMA'])],
            'custom_cereals.*.sort_order' => ['nullable', 'integer', 'min:100', 'max:9999'],
            'cereal_order' => ['nullable', 'array', 'max:100'],
            'cereal_order.*' => ['string', 'max:16'],
            'cereal_labels' => ['nullable', 'array'],
            'cereal_labels.*' => ['nullable', 'string', 'max:255'],
            'cereal_table_labels' => ['nullable', 'array'],
            'cereal_table_labels.*' => ['nullable', 'array'],
            'cereal_table_labels.*.free_text' => ['nullable', 'string', 'max:255'],
            'cereal_table_labels.*.maturity' => ['nullable', 'string', 'max:255'],
            'cereal_table_labels.*.matif' => ['nullable', 'string', 'max:255'],
            'cereal_table_labels.*.base' => ['nullable', 'string', 'max:255'],
            'cereal_table_labels.*.final_price' => ['nullable', 'string', 'max:255'],
            'cereal_info_html' => ['nullable', 'string', 'max:100000'],
            'transport_grid' => ['nullable', 'array'],
            'transport_grid.title' => ['nullable', 'string', 'max:255'],
            'transport_grid.first_column_label' => ['nullable', 'string', 'max:255'],
            'transport_grid.reference_key' => ['nullable', 'string', 'max:180'],
            'transport_grid.columns' => ['nullable', 'array', 'max:12'],
            'transport_grid.columns.*.id' => ['required_with:transport_grid.columns', 'string', 'max:80'],
            'transport_grid.columns.*.label' => ['nullable', 'string', 'max:255'],
            'transport_grid.columns.*.reference_key' => ['nullable', 'string', 'max:180'],
            'transport_grid.columns.*.base' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'transport_grid.rows' => ['nullable', 'array', 'max:30'],
            'transport_grid.rows.*.id' => ['required_with:transport_grid.rows', 'string', 'max:80'],
            'transport_grid.rows.*.label' => ['nullable', 'string', 'max:255'],
            'transport_grid.rows.*.base' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'transport_grid.cells' => ['nullable', 'array'],
            'transport_grid.cells.*.text' => ['nullable', 'string', 'max:500'],
            'transport_grid.sections' => ['nullable', 'array', 'max:20'],
            'transport_grid.sections.*.id' => ['nullable', 'string', 'max:80'],
            'transport_grid.sections.*.title' => ['nullable', 'string', 'max:255'],
            'transport_grid.sections.*.first_column_label' => ['nullable', 'string', 'max:255'],
            'transport_grid.sections.*.reference_key' => ['nullable', 'string', 'max:180'],
            'transport_grid.sections.*.columns' => ['nullable', 'array', 'max:12'],
            'transport_grid.sections.*.columns.*.id' => ['required_with:transport_grid.sections.*.columns', 'string', 'max:80'],
            'transport_grid.sections.*.columns.*.label' => ['nullable', 'string', 'max:255'],
            'transport_grid.sections.*.columns.*.reference_key' => ['nullable', 'string', 'max:180'],
            'transport_grid.sections.*.columns.*.base' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'transport_grid.sections.*.rows' => ['nullable', 'array', 'max:30'],
            'transport_grid.sections.*.rows.*.id' => ['required_with:transport_grid.sections.*.rows', 'string', 'max:80'],
            'transport_grid.sections.*.rows.*.label' => ['nullable', 'string', 'max:255'],
            'transport_grid.sections.*.rows.*.base' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'transport_grid.sections.*.cells' => ['nullable', 'array'],
            'transport_grid.sections.*.cells.*.text' => ['nullable', 'string', 'max:500'],
            ...$this->fuelGridValidationRules(),
        ], $this->cotationValidationMessages(), $this->cotationValidationAttributes());

        $this->assertSelectableMarketLines($validated['manual_prices'] ?? [], $validated['custom_cereals'] ?? []);

        $before = $settings->mapWithKeys(fn (CotationSetting $setting): array => [
            (string) $setting->id => $this->settingSnapshot($setting),
        ])->all();
        $transportGridBefore = $this->transportGridConfig();
        $fuelGridBefore = $this->fuelGridConfig();

        $byId = $settings->keyBy('id');

        foreach ($validated['settings'] as $row) {
            $setting = $byId->get((int) $row['id']);
            if (! $setting) {
                continue;
            }

            $value = array_key_exists('value', $row) && $row['value'] !== null && $row['value'] !== ''
                ? (float) $row['value']
                : null;

            if ($setting->section === 'display' && $value !== null) {
                $value = (float) max(2020, min(2100, (int) $value));
            }

            $setting->forceFill([
                'value' => $value,
                'note' => trim((string) ($row['note'] ?? '')) ?: null,
                'updated_by' => $request->user()?->id,
            ])->save();
        }

        $afterSettings = CotationSetting::query()
            ->whereIn('id', $settings->pluck('id')->all())
            ->orderBy('section')
            ->orderBy('sort_order')
            ->get();

        $after = $afterSettings->mapWithKeys(fn (CotationSetting $setting): array => [
            (string) $setting->id => $this->settingSnapshot($setting),
        ])->all();

        $deletedIds = array_values(array_unique(array_map('intval', $validated['deleted_manual_price_ids'] ?? [])));
        $customCerealChanges = $this->updateCustomCereals($validated['custom_cereals'] ?? [], $request);
        $cerealOrderChange = $this->updateCerealOrder($validated['cereal_order'] ?? null, $request);
        $cerealLabelsChange = $this->updateCerealLabels($validated['cereal_labels'] ?? null, $request);
        $cerealTableLabelsChange = $this->updateCerealTableLabels($validated['cereal_table_labels'] ?? null, $request);
        $cerealInfoChange = $this->updateCerealInfo($validated['cereal_info_html'] ?? null, $request);
        $transportGridChange = $this->updateTransportGrid($validated['transport_grid'] ?? null, $request, $transportGridBefore);
        $fuelGridChange = null;
        $manualRows = collect($validated['manual_prices'] ?? [])
            ->reject(fn (array $row): bool => isset($row['manual_id']) && in_array((int) $row['manual_id'], $deletedIds, true))
            ->values()
            ->all();
        $manualDeletes = $this->deleteManualPrices($deletedIds);
        // Le formulaire soumet manual_prices avec le product_name capturé au
        // chargement de l'édition : si une céréale personnalisée vient juste
        // d'être renommée dans la même requête (updateCustomCereals ci-dessus),
        // ce champ est encore l'ancien nom. On force le nom à jour ici pour
        // que cette sauvegarde n'écrase pas le renommage qui vient d'être fait.
        $customCerealNamesByCode = Schema::hasTable('cotation_custom_cereals')
            ? CotationCustomCereal::query()->pluck('name', 'code')->all()
            : [];
        // Prix de vente, base et marge calculée d'une ligne sont écrits ensemble (hook `saving` du modèle) ;
        // la transaction rend l'ensemble des lignes atomique.
        $manualChanges = \Illuminate\Support\Facades\DB::transaction(
            fn (): array => $this->updateManualPrices($manualRows, $request, $customCerealNamesByCode),
        );
        $hasManualChanges = $manualChanges !== [] || $manualDeletes !== [] || $customCerealChanges !== [] || $cerealOrderChange !== null || $cerealLabelsChange !== null || $cerealTableLabelsChange !== null || $cerealInfoChange !== null || $transportGridChange !== null || $fuelGridChange !== null;

        $this->auditLogService->log([
            'action' => $hasManualChanges ? 'update_cotation_settings_and_manual_prices' : 'update_cotation_settings',
            'module' => 'cotations',
            'description' => $hasManualChanges
                ? 'Mise à jour des réglages et cotations manuelles'
                : 'Mise à jour des réglages Cotations',
            'payload' => [
                'before' => $before,
                'after' => $after,
                'manual_prices' => $manualChanges,
                'deleted_manual_prices' => $manualDeletes,
                'custom_cereals' => $customCerealChanges,
                'cereal_order' => $cerealOrderChange,
                'cereal_labels' => $cerealLabelsChange,
                'cereal_table_labels' => $cerealTableLabelsChange,
                'cereal_info' => $cerealInfoChange,
                'transport_grid' => $transportGridChange,
                'fuel_grid' => $fuelGridChange,
            ],
        ]);

        return back()->with('status', 'Cotations settings updated.');
    }

    /**
     * Journées disposant de cotations dans l'historique existant
     * (cotation_market_prices), lecture seule.
     */
    public function cerealHistoryDates(Request $request): JsonResponse
    {
        $dates = app(CotationMarketHistoryService::class)->dates();

        return response()->json([
            'dates' => $dates,
            'latest' => $dates[0] ?? null,
            'oldest' => $dates[count($dates) - 1] ?? null,
        ]);
    }

    /**
     * Évolution des prix finaux par céréale sur une période (graphiques de l'historique).
     *
     * Chaque point est la cotation réellement consultable d'une journée : même
     * reconstitution que la consultation du jour (relevé de 15 h, base et réglages d'alors,
     * dernier cours connu des échéances échues). Une série = une ligne de cotation
     * (identité stable de la ligne). Aucune donnée n'est copiée ni interpolée : les journées
     * sans cotation exploitable n'ont simplement pas de point.
     */
    public function cerealHistorySeries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.required' => 'Indiquez une date de début.',
            'from.date_format' => 'La date de début est invalide.',
            'to.required' => 'Indiquez une date de fin.',
            'to.date_format' => 'La date de fin est invalide.',
            'to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ]);

        $business = (string) config('cotations.compaction.timezone', 'Europe/Paris');
        $from = \Carbon\CarbonImmutable::parse($validated['from'], $business)->startOfDay();
        $to = \Carbon\CarbonImmutable::parse($validated['to'], $business)->startOfDay();

        if ($to->greaterThan(\Carbon\CarbonImmutable::now($business)->startOfDay())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'La date de fin ne peut pas être dans le futur.']);
        }

        if ($from->diffInDays($to) > 366) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'La période ne peut pas dépasser un an (366 jours).']);
        }

        $history = app(CotationMarketHistoryService::class);
        $days = $history->availableDays($from->toDateString(), $to->toDateString());
        $days = array_filter($days, fn (string $day): bool => $day >= $from->toDateString() && $day <= $to->toDateString(), ARRAY_FILTER_USE_KEY);

        $base = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dates' => array_keys($days),
            'cereals' => [],
        ];

        if ($days === []) {
            return response()->json($base);
        }

        $rowsByRefresh = $history->marketRowsForRefreshes(array_column($days, 'refresh_id'));
        $states = app(CotationConfigHistoryService::class)->statesAt(
            array_map(fn (array $day): \Carbon\CarbonImmutable => $day['at'], $days),
            $this->currentCerealConfig(),
        );
        $contractLast = $history->contractLastQuotes();

        /** @var array<string, array<string, mixed>> $cereals */
        $cereals = [];
        $order = [];

        // Du plus récent au plus ancien : libellés et ordre de la journée la plus récente de la plage.
        foreach (array_reverse($days, true) as $date => $day) {
            $built = $this->historyGroupsFor($date, $day['at'], $rowsByRefresh[$day['refresh_id']] ?? [], $states[$date], $contractLast);

            foreach ($built['groups'] as $group) {
                $code = (string) $group['code'];
                $order[$code] ??= count($order);
                $cereals[$code] ??= ['code' => $code, 'name' => $group['name'], 'series' => []];

                foreach (['left', 'right'] as $bucket) {
                    foreach ($group['harvests'][$bucket]['rows'] as $row) {
                        if (($row['final_price'] ?? null) === null || empty($row['identity_hash'])) {
                            continue;
                        }

                        $key = (string) $row['identity_hash'];
                        $cereals[$code]['series'][$key] ??= [
                            'key' => $key,
                            'harvest_year' => (int) $row['harvest_year'],
                            'maturity_label' => $row['label'],
                            'display_label' => $row['display_label'],
                            'points' => [],
                        ];
                        $cereals[$code]['series'][$key]['points'][] = [
                            'date' => $date,
                            'price' => round((float) $row['final_price'], 2),
                            'matif' => $row['matif'] !== null ? round((float) $row['matif'], 2) : null,
                            'carried_quote_at' => isset($row['carried_quote_at']) ? substr((string) $row['carried_quote_at'], 0, 10) : null,
                        ];
                    }
                }
            }
        }

        foreach ($cereals as &$cereal) {
            foreach ($cereal['series'] as &$series) {
                usort($series['points'], fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
            }
            unset($series);
            $cereal['series'] = array_values($cereal['series']);
        }
        unset($cereal);

        uasort($cereals, fn (array $a, array $b): int => $order[$a['code']] <=> $order[$b['code']]);
        $base['cereals'] = array_values($cereals);

        return response()->json($base);
    }

    /**
     * Cotations d'une journée telles qu'à la vue d'origine, reconstituées depuis
     * l'historique existant : cours MATIF du relevé de 15 h
     * (CotationMarketHistoryService) + réglages d'alors (marges, libellés,
     * textes…) rétablis depuis le journal d'audit (CotationConfigHistoryService).
     * 404 avec message explicite si la journée est indisponible.
     */
    public function cerealHistoryShow(Request $request, string $date): JsonResponse
    {
        $history = app(CotationMarketHistoryService::class);
        $selection = $history->selectForDate($date);

        if ($selection['status'] !== CotationMarketHistoryService::STATUS_OK) {
            return response()->json([
                'status' => $selection['status'],
                'message' => $selection['status'] === CotationMarketHistoryService::STATUS_NO_TARGET
                    ? "Le relevé de 15 h n'est pas disponible pour cette journée."
                    : "Cette version historique n'est plus disponible.",
            ], 404);
        }

        $state = app(CotationConfigHistoryService::class)->configAt($selection['recorded_at'], $this->currentCerealConfig());

        $built = $this->historyGroupsFor($date, $selection['recorded_at'], $selection['market_rows'], $state, null);
        $groups = $built['groups'];
        $left = $built['left'];
        $right = $built['right'];
        $carriedLines = $built['carried_lines'];
        $state = $built['state'];

        $infoUnreliable = in_array('cereal_info_truncated', $state['warnings'], true);

        $warnings = $this->missingQuoteWarnings($groups, $history, $selection['recorded_at']);

        return response()->json(['version' => [
            'business_date' => $date,
            'recorded_at' => $selection['recorded_at']->toIso8601String(),
            'target_time' => $history->target($date)->format('H:i'),
            'market' => [
                'groups' => $groups,
                'harvest_years' => ['left' => $left, 'right' => $right],
                'cereal_order' => $this->normalizeCerealOrder($state['cereal_order'], $groups),
                'cereal_labels' => $state['cereal_labels'],
                'cereal_table_labels' => $state['cereal_table_labels'],
                'cereal_info_html' => $infoUnreliable ? '' : CerealInfoSanitizer::sanitize($state['cereal_info']),
                'options' => [],
                'custom_cereals' => [],
                'fetched_at' => $selection['recorded_at']->toIso8601String(),
            ],
            'transport_grid' => $state['transport_grid'],
            'warnings' => array_values(array_filter([
                $infoUnreliable ? "Le texte d'information de cette date n'a pas pu être reconstitué (journal tronqué à 4 000 caractères)." : null,
                $carriedLines > 0 ? $carriedLines.' ligne(s) reprennent le dernier cours connu d\'une échéance échue (date indiquée sous le cours) : ce n\'est pas une nouvelle cotation du contrat.' : null,
                ...$warnings,
            ])),
        ]]);
    }

    /**
     * Cartes (produit → récoltes → lignes) d'une journée historique : cours du relevé de 15 h
     * (+ dernier cours connu des échéances échues) et réglages d'alors, calculés par la même
     * méthode que l'affichage courant. Partagé par la consultation d'une journée et par les graphiques.
     *
     * @param  array<string, array<string, mixed>>  $marketRows  cours du relevé retenu
     * @param  array<string, mixed>  $state  réglages d'alors (CotationConfigHistoryService)
     * @param  array<string, array{row:array<string,mixed>, last_any:string}>|null  $contractLast  dernier cours par contrat (null = lecture ici)
     * @return array{groups: array<int, array<string, mixed>>, left:int, right:int, carried_lines:int, state: array<string, mixed>}
     */
    private function historyGroupsFor(string $date, \Carbon\CarbonImmutable $recordedAt, array $marketRows, array $state, ?array $contractLast): array
    {
        $history = app(CotationMarketHistoryService::class);

        // Lignes créées depuis la date demandée et jamais passées par le journal : absentes à l'époque.
        $state['manual_lines'] = array_filter(
            $state['manual_lines'],
            fn (array $line): bool => ! isset($line['_created_at']) || $line['_created_at'] <= $recordedAt->getTimestamp(),
        );

        $left = (int) $state['harvest_years']['left'];
        $right = (int) $state['harvest_years']['right'];

        // Échéances échues conservées : dernier cours connu du MÊME contrat, au plus tard à 15 h ce jour-là
        // (règle de l'affichage actuel, bornée à la date consultée) ; un contrat actif sans cours n'est pas comblé.
        $wantedHashes = collect($state['manual_lines'])
            ->filter(fn (array $line): bool => ($line['line_type'] ?? null) === 'matif'
                && in_array((int) ($line['harvest_year'] ?? 0), [$left, $right], true)
                && ! empty($line['market_identity_hash'])
                && ! isset($marketRows[$line['market_identity_hash']]))
            ->pluck('market_identity_hash')
            ->unique()
            ->values()
            ->all();
        $carried = $wantedHashes === []
            ? []
            : $history->carriedFrom($contractLast ?? $history->contractLastQuotes($wantedHashes), $date, $wantedHashes);
        $marketRows += $carried;
        $soldProductSorts = collect($marketRows)->pluck('product_sort', 'product_code')->all();
        $customSorts = collect($state['custom_cereals'])->pluck('sort_order', 'code')->all();

        $configuredRows = collect($state['manual_lines'])
            ->filter(fn (array $line): bool => in_array((int) ($line['harvest_year'] ?? 0), [$left, $right], true))
            ->map(fn (array $line): array => $this->marketService->configuredRowFromSnapshot(
                $line,
                $marketRows,
                (int) ($soldProductSorts[$line['product_code']] ?? $customSorts[$line['product_code']] ?? 999),
            ))
            ->values()
            ->all();

        $groups = $this->marketService->assembleGroups(
            $configuredRows,
            $marketRows,
            $left,
            $right,
            false,
            false,
            collect($state['custom_cereals'])->pluck('name', 'code')->all(),
        );
        $carriedLines = 0;
        foreach ($groups as &$group) {
            foreach (['left', 'right'] as $bucket) {
                foreach ($group['harvests'][$bucket]['rows'] as &$row) {
                    $source = $carried[$row['market_identity_hash'] ?? ''] ?? null;
                    if ($source !== null && ($row['matif'] ?? null) !== null) {
                        $row['carried_quote_at'] = $source['carried_quote_at'];
                        $carriedLines++;
                    }
                }
                unset($row);
            }
        }
        unset($group);

        // Consultation de l'historique : une céréale masquée aujourd'hui n'est pas montrée. Une céréale
        // supprimée reste visible pour les journées antérieures à sa suppression.
        $groups = $this->withoutHiddenCereals($groups, $this->hiddenCerealCodes());
        $groups = array_values(array_filter($groups, fn (array $group): bool => ! $this->marketService->wasDeletedAt((string) ($group['code'] ?? ''), $recordedAt)));
        $groups = $this->applyCerealOrder($groups, $state['cereal_order']);
        $groups = $this->applyCerealLabels($groups, $state['cereal_labels']);

        return ['groups' => $groups, 'left' => $left, 'right' => $right, 'carried_lines' => $carriedLines, 'state' => $state];
    }

    /**
     * Explique, ligne par ligne, pourquoi un cours MATIF manque dans le relevé de 15 h,
     * d'après les enregistrements réels (jamais d'affirmation « non coté » sans preuve).
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, string>
     */
    private function missingQuoteWarnings(array $groups, CotationMarketHistoryService $history, \Carbon\CarbonImmutable $at): array
    {
        $missing = [];
        foreach ($groups as $group) {
            foreach (['left', 'right'] as $bucket) {
                foreach ($group['harvests'][$bucket]['rows'] ?? [] as $row) {
                    if (($row['matif'] ?? null) === null && ($row['final_price'] ?? null) === null
                        && (($row['line_type'] ?? null) === 'matif' || ! empty($row['final_price_reference_key']))) {
                        $missing[] = [$group['name'], $row];
                    }
                }
            }
        }

        if ($missing === []) {
            return [];
        }

        $availability = $history->contractAvailability($at);
        $format = static fn (?\Carbon\CarbonImmutable $date): string => $date
            ? $date->setTimezone((string) config('cotations.compaction.timezone', 'Europe/Paris'))->format('d/m/Y \à H:i')
            : '';
        $lines = ['Pas de cours MATIF dans le relevé de 15 h pour '.count($missing).' ligne(s) — aucune valeur d\'une autre heure ou d\'une autre date n\'est affichée :'];

        foreach ($missing as [$name, $row]) {
            $label = sprintf('%s — %s (%s)', $name, $row['label'], $row['display_label'] ?: 'sans libellé');

            if (($row['line_type'] ?? null) !== 'matif') {
                $lines[] = $label.' : prix lié à une autre ligne qui n\'a pas de cours à cette date.';
                continue;
            }

            $info = $availability[$row['market_identity_hash'] ?? ''] ?? null;
            $lastBefore = $info['last_before'] ?? null;
            $lastAny = $info['last_any'] ?? null;

            if ($info === null || $lastAny === null) {
                $detail = 'aucun cours enregistré pour ce contrat dans l\'historique.';
            } elseif ($lastBefore === null) {
                $detail = 'aucun cours enregistré avant cette date (premier cours le '.$format($lastAny).' ou plus tard).';
            } elseif ($lastAny->lessThanOrEqualTo($at)) {
                $detail = 'dernier cours enregistré le '.$format($lastBefore).', aucun cours enregistré depuis (le contrat ne figure plus dans les données, vraisemblablement échu).';
            } else {
                $detail = 'des cours existent à d\'autres moments (dernier avant ce relevé : '.$format($lastBefore).') mais aucun dans ce relevé.';
            }

            $lines[] = $label.' : '.$detail;
        }

        return $lines;
    }

    /**
     * État actuel des réglages de cotation, point de départ de la
     * reconstitution historique (voir CotationConfigHistoryService).
     *
     * @return array<string, mixed>
     */
    private function currentCerealConfig(): array
    {
        $lines = [];
        if (Schema::hasTable('cotation_manual_prices')) {
            CotationManualPrice::query()->get()->each(function (CotationManualPrice $manual) use (&$lines): void {
                $lines[$manual->identity_hash] = $this->manualPriceSnapshot($manual) + [
                    '_created_at' => $manual->created_at?->getTimestamp(),
                ];
            });
        }

        $customs = [];
        if (Schema::hasTable('cotation_custom_cereals')) {
            CotationCustomCereal::query()->get()->each(function (CotationCustomCereal $cereal) use (&$customs): void {
                $customs[$cereal->code] = $this->customCerealSnapshot($cereal);
            });
        }

        $display = $this->displaySettings();

        return [
            'manual_lines' => $lines,
            'custom_cereals' => $customs,
            'cereal_order' => $this->cerealOrderConfig(),
            'cereal_labels' => $this->cerealLabelsConfig(),
            'cereal_table_labels' => $this->cerealTableLabelsConfig(),
            'cereal_info' => $this->cerealInfoConfig(),
            'transport_grid' => $this->transportGridConfig(),
            'harvest_years' => [
                'left' => (int) $display['harvest_left_year'],
                'right' => (int) $display['harvest_right_year'],
            ],
        ];
    }

    public function updateFuelSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            $this->fuelGridValidationRules(required: true),
            $this->cotationValidationMessages(),
            $this->cotationValidationAttributes(),
        );
        $before = $this->fuelGridConfig();
        $fuelGridChange = $this->updateFuelGrid($validated['fuel_grid'] ?? null, $request, $before);

        $this->auditLogService->log([
            'action' => 'update_cotation_fuel_settings',
            'module' => 'cotations',
            'description' => 'Mise à jour des prix carburant',
            'payload' => [
                'fuel_grid' => $fuelGridChange,
            ],
        ]);

        return back()->with('status', 'Cotations fuel settings updated.');
    }

    /**
     * Liste des versions historiques de la grille carburant (lecture seule),
     * la plus récente en premier. N'affecte jamais la configuration courante
     * ni l'export PDF, qui continuent d'utiliser fuelGridConfig().
     */
    public function fuelHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);

        if (! $user || ! $access->can($user, 'cotations.fuel.history.view')) {
            return response()->json(['versions' => []], 403);
        }

        $versions = CotationFuelPriceHistory::query()
            ->with('createdBy:id,name,first_name,last_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (CotationFuelPriceHistory $entry): array => [
                'id' => $entry->id,
                'created_at' => $entry->created_at?->toIso8601String(),
                'created_by_name' => $this->userLabel($entry->createdBy),
                'fuel_grid' => $this->normalizeFuelGrid($entry->fuel_grid ?? []),
            ])
            ->values()
            ->all();

        return response()->json(['versions' => $versions]);
    }

    /**
     * Rend le PDF de l'export des cotations (contenu binaire). Source unique
     * pour le bouton « Export PDF » et pour la pièce jointe du courriel : les
     * deux PDF viennent exactement des mêmes données et de la même vue.
     */
    public function renderExportPdf(): string
    {
        $displaySettings = $this->displaySettings();
        $leftYear = (int) ($displaySettings['harvest_left_year'] ?? now()->year);
        $rightYear = (int) ($displaySettings['harvest_right_year'] ?? now()->year + 1);

        $cerealOrder = $this->cerealOrderConfig();
        // Les céréales masquées sont écartées ici, côté serveur : nom, récoltes, échéances et prix.
        $groups = $this->applyCerealOrder(
            $this->withoutHiddenCereals(
                $this->marketService->latestGroups($leftYear, $rightYear, false, false),
                $this->hiddenCerealCodes(),
            ),
            $cerealOrder,
        );
        $groups = $this->applyCerealLabels($groups, $this->cerealLabelsConfig());
        $groups = array_values(array_filter($groups, static function (array $group): bool {
            return count($group['harvests']['left']['rows'] ?? []) > 0
                || count($group['harvests']['right']['rows'] ?? []) > 0;
        }));

        $finalPriceByKey = [];
        foreach ($groups as $group) {
            foreach (['left', 'right'] as $bucket) {
                foreach ($group['harvests'][$bucket]['rows'] ?? [] as $row) {
                    $finalPriceByKey[CotationPdfFormatter::referenceKey($row)] = $row['final_price'] ?? null;
                }
            }
        }

        $viewData = [
            'cerealHarvestTables' => $this->buildCerealHarvestTables($groups, ['left' => $leftYear, 'right' => $rightYear], $this->cerealTableLabelsConfig()),
            'transportGrid' => $this->transportGridConfig(),
            'fuelGrid' => CotationFuelCalculator::compute($this->fuelGridConfig()),
            'finalPriceByKey' => $finalPriceByKey,
            'generatedAt' => now(),
            'lastRefreshAt' => $this->marketService->lastRefresh()?->fetched_at,
            'cerealInfoHtml' => $this->cerealInfoConfig(),
            'logoPath' => public_path('Logo Jeudy.png'),
            'pdfOrientation' => 'landscape',
        ];

        $pdf = Pdf::loadView('cotations.pdf', $viewData)
            ->setPaper('a4', 'landscape');

        return $pdf->output();
    }

    public function exportPdf(Request $request)
    {
        $access = app(AccessManager::class);
        $user = $request->user();
        abort_unless($user && $access->can($user, 'cotations.cereals.edit'), 403);

        try {
            $output = $this->renderExportPdf();

            return response($output, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    CotationPdfFormatter::exportFilename(),
                ),
            ]);
        } catch (Throwable $exception) {
            Log::error('cotations.export-pdf failed', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'user_id' => $user?->id,
            ]);

            return response('Erreur export PDF : '.$exception->getMessage(), 500);
        }
    }

    /**
     * Export PDF dédié, contenant uniquement la page carburant. Réutilise la
     * même vue partielle (cotations.partials.fuel-page) que l'export complet
     * pour garantir un rendu strictement identique, sans dupliquer la mise
     * en forme ni les calculs.
     */
    public function exportFuelPdf(Request $request)
    {
        $access = app(AccessManager::class);
        $user = $request->user();
        abort_unless($user && $access->can($user, 'cotations.fuel.edit'), 403);

        try {
            $pdf = Pdf::loadView('cotations.fuel-pdf', [
                'fuelGrid' => CotationFuelCalculator::compute($this->fuelGridConfig()),
                'generatedAt' => now(),
                'pdfOrientation' => 'landscape',
            ])->setPaper('a4', 'landscape');

            $filename = 'prix-carburant-'.now()->format('Y-m-d-His').'.pdf';

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        } catch (Throwable $exception) {
            Log::error('cotations.export-fuel-pdf failed', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'user_id' => $user?->id,
            ]);

            return response('Erreur export PDF : '.$exception->getMessage(), 500);
        }
    }

    /**
     * Pré-calcule, pour la page céréales du PDF, un tableau croisé par récolte
     * où chaque échéance devient une colonne (regroupées par céréale), avec
     * uniquement 3 lignes de données (Matif/Base/Prix final). La vue Blade
     * n'a plus qu'à boucler sur des données déjà aplaties, sans
     * déréférencement d'array ni ternaire complexe dans le template.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<string, int>  $harvestYears
     * @return array<string, array<string, mixed>>
     */
    private function buildCerealHarvestTables(array $groups, array $harvestYears, array $tableLabels = []): array
    {
        $tables = [];
        $stubWidth = 6;
        $labelsByCode = $this->normalizeCerealTableLabels($tableLabels);

        foreach (['left', 'right'] as $bucket) {
            $cerealGroups = [];

            foreach ($groups as $group) {
                $sourceRows = $group['harvests'][$bucket]['rows'] ?? [];
                if ($sourceRows === []) {
                    continue;
                }

                $columns = [];
                foreach ($sourceRows as $row) {
                    $columns[] = [
                        'free_text' => (string) ($row['display_label'] ?? ''),
                        'label' => $this->maturityShortLabel((string) ($row['maturity_label'] ?: ($row['label'] ?: 'Échéance'))),
                        'matif' => CotationPdfFormatter::price($row['matif'] ?? null),
                        'base' => CotationPdfFormatter::base($row['base'] ?? null, $row['base_operation'] ?? null),
                        'final_price' => CotationPdfFormatter::price($row['final_price'] ?? null),
                    ];
                }

                $cerealGroups[] = [
                    'name' => (string) ($group['name'] ?? 'Céréale'),
                    'labels' => $labelsByCode[(string) ($group['code'] ?? '')] ?? $this->defaultCerealTableLabels(),
                    'columns' => $columns,
                ];
            }

            $totalColumns = 0;
            foreach ($cerealGroups as $cerealGroup) {
                $totalColumns += count($cerealGroup['columns']);
            }

            $tables[$bucket] = [
                'year' => $harvestYears[$bucket] ?? '',
                'cereal_groups' => $cerealGroups,
                'labels' => $this->defaultCerealTableLabels(),
                'stub_width' => $stubWidth,
                'column_width' => $totalColumns > 0 ? (100 - $stubWidth) / $totalColumns : 0,
                'has_data' => $totalColumns > 0,
            ];
        }

        return $tables;
    }

    private function maturityShortLabel(string $value): string
    {
        $label = trim($value);
        if ($label === '') {
            return '';
        }
        if (mb_strtolower($label, 'UTF-8') === 'option personnalisée') {
            return '';
        }

        $replacements = [
            '/\bjanvier\b/iu' => 'janv',
            '/\bfévrier\b/iu' => 'fev',
            '/\bfevrier\b/iu' => 'fev',
            '/\bmars\b/iu' => 'mars',
            '/\bavril\b/iu' => 'avr',
            '/\bmai\b/iu' => 'mai',
            '/\bjuin\b/iu' => 'juin',
            '/\bjuillet\b/iu' => 'juil',
            '/\baoût\b/iu' => 'aout',
            '/\baout\b/iu' => 'aout',
            '/\bseptembre\b/iu' => 'sept',
            '/\boctobre\b/iu' => 'oct',
            '/\bnovembre\b/iu' => 'nov',
            '/\bdécembre\b/iu' => 'dec',
            '/\bdecembre\b/iu' => 'dec',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $label = preg_replace($pattern, $replacement, $label) ?? $label;
        }

        $label = mb_strtolower($label, 'UTF-8');
        $label = preg_replace('/\s*[-\/]\s*/u', ' - ', $label) ?? $label;
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

        return trim($label);
    }

    /**
     * @return array<string, mixed>
     */
    private function marketPayload(?Request $request = null): array
    {
        $displaySettings = $this->displaySettings();
        $lastRefresh = $this->marketService->lastRefresh();
        $access = app(AccessManager::class);
        $user = $request?->user();
        $canManage = (bool) ($user && $access->can($user, 'cotations.cereals.edit'));
        // Le droit de modifier ne suffit pas : seul le mode édition réellement demandé (?edit=1) donne
        // accès aux céréales masquées, aux lignes vides et au prix de vente. Consultation = même
        // filtre pour tous, administrateurs compris.
        $editMode = $canManage && $request?->boolean('edit') === true;
        $cerealOrder = $this->cerealOrderConfig();
        $cerealLabels = $this->cerealLabelsConfig();
        $cerealTableLabels = $this->cerealTableLabelsConfig();
        $hiddenCodes = $this->hiddenCerealCodes();
        $groups = $this->marketService->latestGroups(
            (int) ($displaySettings['harvest_left_year'] ?? now()->year),
            (int) ($displaySettings['harvest_right_year'] ?? now()->year + 1),
            $editMode,
            false,
            $editMode,
        );
        $options = $this->marketService->marketOptions(
            (int) ($displaySettings['harvest_left_year'] ?? now()->year),
            (int) ($displaySettings['harvest_right_year'] ?? now()->year + 1),
        );
        $customCereals = $this->customCerealsPayload();
        // Consultation : les céréales masquées ne quittent pas le serveur (cartes, options MATIF,
        // céréales personnalisées). Le mode édition reçoit tout, avec la liste des codes masqués.
        if (! $editMode) {
            $groups = $this->withoutHiddenCereals($groups, $hiddenCodes);
            $options = $this->withoutHiddenCereals($options, $hiddenCodes);
            $customCereals = array_values(array_filter(
                $customCereals,
                fn (array $cereal): bool => ! in_array((string) ($cereal['code'] ?? ''), $hiddenCodes, true),
            ));
        }
        $groups = $this->applyCerealLabels($this->applyCerealOrder($groups, $cerealOrder), $cerealLabels);
        $options = $this->applyCerealLabels($options, $cerealLabels);

        return [
            'source' => 'database',
            'source_url' => CotationMarketService::EURONEXT_URL,
            'fetched_at' => $lastRefresh?->fetched_at?->toIso8601String(),
            'last_refresh' => $lastRefresh ? [
                'id' => $lastRefresh->id,
                'is_success' => (bool) $lastRefresh->is_success,
                'row_count' => (int) $lastRefresh->row_count,
                'error_message' => $lastRefresh->error_message,
                'fetched_at' => $lastRefresh->fetched_at?->toIso8601String(),
            ] : null,
            'harvest_years' => [
                'left' => $displaySettings['harvest_left_year'] ?? now()->year,
                'right' => $displaySettings['harvest_right_year'] ?? now()->year + 1,
            ],
            'groups' => $groups,
            'cereal_order' => $this->normalizeCerealOrder($cerealOrder, $groups),
            'options' => $options,
            'custom_cereals' => $customCereals,
            'hidden_cereal_codes' => $editMode ? $hiddenCodes : [],
            'deleted_cereals' => $editMode ? $this->deletedCerealsList() : [],
            'cereal_labels' => $cerealLabels,
            'cereal_table_labels' => $cerealTableLabels,
            'cereal_info_html' => $this->cerealInfoConfig(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeMarketPayload(?Request $request = null): array
    {
        try {
            return $this->marketPayload($request);
        } catch (Throwable) {
            return $this->emptyMarketPayload();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMarketPayload(): array
    {
        return [
            'source' => 'database',
            'source_url' => CotationMarketService::EURONEXT_URL,
            'fetched_at' => null,
            'last_refresh' => null,
            'harvest_years' => [
                'left' => now()->year,
                'right' => now()->year + 1,
            ],
            'groups' => [],
            'cereal_order' => [],
            'options' => [],
            'custom_cereals' => [],
            'cereal_labels' => $this->defaultCerealLabels(),
            'cereal_table_labels' => [],
            'cereal_info_html' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMarketDataResponse(): array
    {
        $year = now()->year;

        return [
            'ok' => true,
            'groups' => [],
            'leftYear' => $year,
            'rightYear' => $year + 1,
            'availableYears' => [$year, $year + 1],
            'manualRows' => [],
            'customCereals' => [],
            'custom_cereals' => [],
            'cereal_order' => [],
            'cereal_labels' => $this->defaultCerealLabels(),
            'cereal_table_labels' => [],
            'cereal_info_html' => '',
            'lastRefresh' => null,
            'last_refresh' => null,
            'fetched_at' => null,
            'harvest_years' => [
                'left' => $year,
                'right' => $year + 1,
            ],
            'options' => [],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function displaySettings(): array
    {
        $currentYear = (int) now(config('app.timezone', 'Europe/Paris'))->format('Y');
        if (! Schema::hasTable('cotation_settings')) {
            return [
                'harvest_left_year' => $currentYear,
                'harvest_right_year' => $currentYear + 1,
            ];
        }

        $values = CotationSetting::query()
            ->where('section', 'display')
            ->pluck('value', 'key')
            ->all();

        return [
            'harvest_left_year' => (int) ($values['harvest_left_year'] ?? $currentYear),
            'harvest_right_year' => (int) ($values['harvest_right_year'] ?? $currentYear + 1),
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function manualSettings(): array
    {
        if (! Schema::hasTable('cotation_settings')) {
            return [];
        }

        return CotationSetting::query()
            ->select(['id', 'section', 'key', 'label', 'value', 'unit', 'note', 'sort_order', 'updated_at'])
            ->whereIn('section', ['transport', 'fuel', 'display'])
            ->orderBy('section')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('section')
            ->map(fn ($rows) => $rows->map(fn (CotationSetting $setting): array => [
                'id' => (int) $setting->id,
                'section' => (string) $setting->section,
                'key' => (string) $setting->key,
                'label' => (string) $setting->label,
                'value' => $setting->value !== null ? (float) $setting->value : null,
                'unit' => $setting->unit,
                'note' => $setting->note,
                'sort_order' => (int) $setting->sort_order,
                'updated_by_name' => null,
                'updated_at' => $setting->updated_at?->toIso8601String(),
            ])->values()->all())
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transportGridConfig(): array
    {
        if (! Schema::hasTable('cotation_settings')) {
            return $this->normalizeTransportGrid([]);
        }

        $note = CotationSetting::query()->where('key', 'transport_grid_config')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;
        $config = is_array($decoded) ? $decoded : [];

        return $this->normalizeTransportGrid($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function fuelGridConfig(): array
    {
        if (! Schema::hasTable('cotation_settings')) {
            return $this->normalizeFuelGrid([]);
        }

        $note = CotationSetting::query()->where('key', 'fuel_grid_config')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;
        $config = is_array($decoded) ? $decoded : [];

        return $this->normalizeFuelGrid($config);
    }

    /**
     * @return array<int, string>
     */
    private function cerealOrderConfig(): array
    {
        if (! Schema::hasTable('cotation_settings')) {
            return [];
        }

        $note = CotationSetting::query()->where('key', 'cereal_display_order')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;

        return $this->normalizeCerealOrder(is_array($decoded) ? $decoded : []);
    }

    /**
     * @return array<string, string>
     */
    private function defaultCerealLabels(): array
    {
        return [
            'ECO' => 'Colza',
            'EBM' => 'Blé',
            'EMA' => 'Maïs',
            'EOB' => 'Orge',
            'EOR' => 'Orge',
            'ERS' => 'Seigle',
            'ETR' => 'Triticale',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function cerealLabelsConfig(): array
    {
        if (! Schema::hasTable('cotation_settings')) {
            return $this->defaultCerealLabels();
        }

        $note = CotationSetting::query()->where('key', 'cereal_display_labels')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;

        return $this->normalizeCerealLabels(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<string, string>
     */
    private function normalizeCerealLabels(array $labels): array
    {
        $defaults = $this->defaultCerealLabels();
        $normalized = $defaults;

        foreach ($defaults as $code => $defaultLabel) {
            $label = trim((string) ($labels[$code] ?? ''));
            $normalized[$code] = $label !== '' ? $label : $defaultLabel;
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function defaultCerealTableLabels(): array
    {
        return [
            'free_text' => 'Livraison',
            'maturity' => 'Échéance',
            'matif' => 'MATIF',
            'base' => 'Base',
            'final_price' => 'Prix final',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function cerealTableLabelsConfig(): array
    {
        if (! Schema::hasTable('cotation_settings')) {
            return [];
        }

        $note = CotationSetting::query()->where('key', 'cereal_table_labels')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;

        return $this->normalizeCerealTableLabels(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<string, array<string, string>>
     */
    private function normalizeCerealTableLabels(array $labels): array
    {
        $normalized = [];

        foreach ($labels as $code => $groupLabels) {
            if (! is_array($groupLabels)) {
                continue;
            }

            $code = mb_strtoupper(trim((string) $code), 'UTF-8');
            if ($code === '') {
                continue;
            }

            $normalized[$code] = $this->normalizeCerealTableLabelSet($groupLabels);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<string, string>
     */
    private function normalizeCerealTableLabelSet(array $labels): array
    {
        $defaults = $this->defaultCerealTableLabels();
        $normalized = $defaults;

        foreach ($defaults as $key => $defaultLabel) {
            $label = trim((string) ($labels[$key] ?? ''));
            $normalized[$key] = $label !== '' ? $label : $defaultLabel;
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<string, string>  $labels
     * @return array<int, array<string, mixed>>
     */
    private function applyCerealLabels(array $groups, array $labels): array
    {
        return array_map(static function (array $group) use ($labels): array {
            $code = (string) ($group['code'] ?? '');
            if (isset($labels[$code])) {
                $group['original_name'] = $group['original_name'] ?? $group['name'] ?? $labels[$code];
                $group['name'] = $labels[$code];
            }

            foreach (['left', 'right'] as $bucket) {
                foreach (($group['harvests'][$bucket]['rows'] ?? []) as $index => $row) {
                    if (isset($labels[$code])) {
                        $group['harvests'][$bucket]['rows'][$index]['product_name'] = $labels[$code];
                    }
                }
            }

            return $group;
        }, $groups);
    }

    /**
     * @param  array<string, mixed>|null  $labels
     * @return array<string, mixed>|null
     */
    private function updateCerealLabels(?array $labels, Request $request): ?array
    {
        if ($labels === null || ! Schema::hasTable('cotation_settings')) {
            return null;
        }

        $before = $this->cerealLabelsConfig();
        $normalized = $this->normalizeCerealLabels($labels);
        $setting = CotationSetting::query()->firstOrNew(['key' => 'cereal_display_labels']);
        $setting->forceFill([
            'section' => 'display',
            'label' => 'Libellés céréales MATIF',
            'unit' => null,
            'value' => null,
            'note' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
            'sort_order' => 35,
            'updated_by' => $request->user()?->id,
        ])->save();

        $after = $this->cerealLabelsConfig();

        return $before !== $after ? ['before' => $before, 'after' => $after] : null;
    }

    /**
     * @param  array<string, mixed>|null  $labels
     * @return array<string, mixed>|null
     */
    private function updateCerealTableLabels(?array $labels, Request $request): ?array
    {
        if ($labels === null || ! Schema::hasTable('cotation_settings')) {
            return null;
        }

        $before = $this->cerealTableLabelsConfig();
        $normalized = $this->normalizeCerealTableLabels($labels);
        $setting = CotationSetting::query()->firstOrNew(['key' => 'cereal_table_labels']);
        $setting->forceFill([
            'section' => 'display',
            'label' => 'Libellés colonnes céréales',
            'unit' => null,
            'value' => null,
            'note' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
            'sort_order' => 36,
            'updated_by' => $request->user()?->id,
        ])->save();

        $after = $this->cerealTableLabelsConfig();

        return $before !== $after ? ['before' => $before, 'after' => $after] : null;
    }

    /**
     * @param  array<int, mixed>  $order
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, string>
     */
    private function normalizeCerealOrder(array $order, array $groups = []): array
    {
        $knownCodes = collect($groups)
            ->pluck('code')
            ->filter()
            ->map(fn (mixed $code): string => (string) $code)
            ->values()
            ->all();
        $knownLookup = array_fill_keys($knownCodes, true);
        $normalized = collect($order)
            ->map(fn (mixed $code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '' && ($knownCodes === [] || isset($knownLookup[$code])))
            ->unique()
            ->values()
            ->all();

        foreach ($knownCodes as $code) {
            if (! in_array($code, $normalized, true)) {
                $normalized[] = $code;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, string>  $order
     * @return array<int, array<string, mixed>>
     */
    private function applyCerealOrder(array $groups, array $order): array
    {
        $normalizedOrder = $this->normalizeCerealOrder($order, $groups);
        if ($normalizedOrder === []) {
            return $groups;
        }

        $orderIndex = array_flip($normalizedOrder);
        usort($groups, static function (array $left, array $right) use ($orderIndex): int {
            return (($orderIndex[$left['code'] ?? ''] ?? 9999) <=> ($orderIndex[$right['code'] ?? ''] ?? 9999))
                ?: (($left['sort'] ?? 999) <=> ($right['sort'] ?? 999))
                ?: strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return array_values($groups);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>|null
     */
    private function updateTransportGrid(?array $payload, Request $request, array $before): ?array
    {
        if ($payload === null) {
            return null;
        }

        $setting = CotationSetting::query()->firstOrNew(['key' => 'transport_grid_config']);
        $setting->forceFill([
            'section' => 'transport_grid',
            'label' => 'Tableau prix des transports',
            'value' => null,
            'unit' => null,
            'note' => json_encode($this->normalizeTransportGrid($payload), JSON_UNESCAPED_UNICODE),
            'sort_order' => 10,
            'updated_by' => $request->user()?->id,
        ])->save();

        $after = $this->transportGridConfig();

        return $before !== $after ? [
            'before' => $before,
            'after' => $after,
        ] : null;
    }

    /**
     * @param  array<int, string>|null  $order
     * @return array<string, mixed>|null
     */
    private function updateCerealOrder(?array $order, Request $request): ?array
    {
        if ($order === null || ! Schema::hasTable('cotation_settings')) {
            return null;
        }

        $before = $this->cerealOrderConfig();
        $normalized = $this->normalizeCerealOrder($order);
        // Le formulaire ne contient pas les céréales supprimées : on garde leur place dans l'ordre
        // enregistré pour qu'une restauration les remette à leur emplacement d'origine.
        foreach (array_keys($this->marketService->deletedCereals()) as $deletedCode) {
            $position = array_search($deletedCode, $before, true);
            if ($position !== false && ! in_array($deletedCode, $normalized, true)) {
                array_splice($normalized, min($position, count($normalized)), 0, [$deletedCode]);
            }
        }
        $setting = CotationSetting::query()->firstOrNew(['key' => 'cereal_display_order']);
        $setting->forceFill([
            'section' => 'display',
            'label' => 'Ordre des céréales',
            'unit' => null,
            'value' => null,
            'note' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
            'sort_order' => 30,
            'updated_by' => $request->user()?->id,
        ])->save();

        $after = $this->cerealOrderConfig();

        return $before !== $after ? [
            'before' => $before,
            'after' => $after,
        ] : null;
    }

    /**
     * Codes des céréales masquées en consultation et dans les PDF (partagé entre tous les
     * utilisateurs). Stocké comme l'ordre des céréales : les produits MATIF n'ont pas de table propre.
     *
     * @return array<int, string>
     */
    private function hiddenCerealCodes(): array
    {
        if (! Schema::hasTable('cotation_settings')) {
            return [];
        }

        $note = CotationSetting::query()->where('key', 'cereal_hidden_codes')->value('note');
        $decoded = $note ? json_decode((string) $note, true) : null;

        return collect(is_array($decoded) ? $decoded : [])
            ->map(fn (mixed $code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, string>  $hiddenCodes
     * @return array<int, array<string, mixed>>
     */
    private function withoutHiddenCereals(array $groups, array $hiddenCodes): array
    {
        if ($hiddenCodes === []) {
            return $groups;
        }

        return array_values(array_filter(
            $groups,
            static fn (array $group): bool => ! in_array((string) ($group['code'] ?? ''), $hiddenCodes, true),
        ));
    }

    /**
     * Affiche ou masque une céréale (état explicite, donc idempotent : un double clic ne peut pas
     * laisser un état incohérent). N'altère aucune donnée de cotation.
     */
    public function updateCerealVisibility(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'visible' => ['required', 'boolean'],
        ], [
            'visible.required' => 'Indiquez si la céréale doit être affichée.',
            'visible.boolean' => 'La visibilité demandée est invalide.',
        ]);

        $code = trim($code);
        if (isset($this->marketService->deletedCereals()[$code])) {
            return response()->json(['message' => 'Cette céréale a été supprimée.'], 404);
        }
        if (! in_array($code, $this->marketService->knownProductCodes(), true)
            && ! CotationManualPrice::query()->where('product_code', $code)->exists()) {
            return response()->json(['message' => 'Céréale introuvable : enregistrez d’abord les modifications en cours.'], 404);
        }

        $visible = (bool) $validated['visible'];

        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($code, $visible, $request): array {
            // Verrou sur la ligne de réglage : deux requêtes simultanées s'appliquent l'une après l'autre.
            $setting = CotationSetting::query()->where('key', 'cereal_hidden_codes')->lockForUpdate()->first()
                ?? new CotationSetting(['key' => 'cereal_hidden_codes']);
            $before = $this->hiddenCerealCodes();
            $after = $visible
                ? array_values(array_diff($before, [$code]))
                : array_values(array_unique([...$before, $code]));

            if ($before !== $after || ! $setting->exists) {
                $setting->forceFill([
                    'section' => 'cereal_visibility',
                    'label' => 'Céréales masquées',
                    'unit' => null,
                    'value' => null,
                    'note' => json_encode($after, JSON_UNESCAPED_UNICODE),
                    'sort_order' => 31,
                    'updated_by' => $request->user()?->id,
                ])->save();
            }

            return ['before' => $before, 'after' => $after];
        });

        if ($result['before'] !== $result['after']) {
            $name = $this->cerealLabelsConfig()[$code]
                ?? CotationCustomCereal::query()->where('code', $code)->value('name')
                ?? $code;

            $this->auditLogService->log([
                'action' => 'update_cotation_cereal_visibility',
                'module' => 'cotations',
                'description' => sprintf('Céréale « %s » %s', $name, $visible ? 'rendue visible' : 'masquée'),
                'payload' => [
                    'cereal_code' => $code,
                    'cereal_name' => $name,
                    'before' => ['visible' => ! $visible],
                    'after' => ['visible' => $visible],
                ],
            ]);
        }

        return response()->json([
            'ok' => true,
            'code' => $code,
            'visible' => $visible,
            'hidden_cereal_codes' => $result['after'],
        ]);
    }

    /**
     * Supprime une céréale de la gestion courante (suppression logique, réversible en base).
     * Cotations, cours, journal d'audit et historique sont conservés ; la céréale quitte l'édition,
     * la consultation, le PDF et le courriel. Les céréales qui en dépendent ne sont pas touchées.
     */
    public function destroyCereal(Request $request, string $code): JsonResponse
    {
        $code = trim($code);
        $isCustom = Schema::hasTable('cotation_custom_cereals') && CotationCustomCereal::query()->where('code', $code)->exists();
        $knownCode = $isCustom
            || in_array($code, $this->marketService->knownProductCodes(), true)
            || CotationManualPrice::query()->where('product_code', $code)->exists();

        if (! $knownCode) {
            return response()->json(['message' => 'Céréale introuvable : enregistrez d’abord les modifications en cours.'], 404);
        }

        $name = CotationCustomCereal::query()->where('code', $code)->value('name')
            ?? $this->cerealLabelsConfig()[$code]
            ?? $code;

        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($code, $name, $request): array {
            $setting = CotationSetting::query()->where('key', 'cereal_deleted_codes')->lockForUpdate()->first()
                ?? new CotationSetting(['key' => 'cereal_deleted_codes']);
            $registry = $this->marketService->deletionRegistry();

            // Déjà supprimée (double clic, deux administrateurs) : succès sans nouvelle écriture.
            $current = $this->marketService->deletedCereals();
            if (isset($current[$code])) {
                return ['already' => true, 'deleted_at' => $current[$code]['deleted_at']];
            }

            // Nouvelle période de suppression ; les périodes passées (et leurs restaurations) sont conservées.
            $deletedAt = now();
            $entries = $registry;
            $entries[$code] = [
                'name' => $name,
                'periods' => [
                    ...($registry[$code]['periods'] ?? []),
                    ['deleted_at' => $deletedAt->toIso8601String(), 'deleted_by' => $request->user()?->id, 'restored_at' => null, 'restored_by' => null],
                ],
            ];

            $setting->forceFill([
                'section' => 'cereal_visibility',
                'label' => 'Céréales supprimées',
                'unit' => null,
                'value' => null,
                'note' => json_encode($entries, JSON_UNESCAPED_UNICODE),
                'sort_order' => 32,
                'updated_by' => $request->user()?->id,
            ])->save();

            return ['already' => false, 'deleted_at' => $deletedAt];
        });

        if (! $result['already']) {
            $lines = CotationManualPrice::query()->where('product_code', $code);
            $dependents = Schema::hasTable('cotation_custom_cereals')
                ? CotationCustomCereal::query()->where('base_product_code', $code)->pluck('code')->all()
                : [];

            $this->auditLogService->log([
                'action' => 'delete_cotation_cereal',
                'module' => 'cotations',
                'description' => sprintf('Céréale « %s » supprimée (historique conservé)', $name),
                'payload' => [
                    'cereal_code' => $code,
                    'cereal_name' => $name,
                    'cereal_type' => $isCustom ? 'custom' : 'matif',
                    'deleted_at' => $result['deleted_at']->toIso8601String(),
                    'preserved_manual_lines' => (clone $lines)->count(),
                    'preserved_manual_line_ids' => (clone $lines)->pluck('id')->all(),
                    'dependent_custom_cereals' => $dependents,
                    'soft_delete' => true,
                ],
            ]);
        }

        return response()->json([
            'ok' => true,
            'code' => $code,
            'already_deleted' => $result['already'],
            'deleted_cereals' => $this->deletedCerealsList(),
        ]);
    }

    /**
     * Restaure une céréale supprimée : même code, mêmes lignes, même ordre, même visibilité.
     * Ferme la période de suppression en cours (elle reste dans le registre pour l'historique).
     */
    public function restoreCereal(Request $request, string $code): JsonResponse
    {
        $code = trim($code);
        if (! isset($this->marketService->deletionRegistry()[$code])) {
            return response()->json(['message' => 'Cette céréale n’a jamais été supprimée.'], 404);
        }

        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($code, $request): array {
            $setting = CotationSetting::query()->where('key', 'cereal_deleted_codes')->lockForUpdate()->first();
            $registry = $this->marketService->deletionRegistry();
            $periods = $registry[$code]['periods'] ?? [];
            $last = $periods === [] ? null : $periods[array_key_last($periods)];

            // Déjà restaurée (double clic, deux administrateurs) : succès sans nouvelle écriture.
            if ($setting === null || $last === null || $last['restored_at'] !== null) {
                return ['already' => true, 'period' => $last];
            }

            $restoredAt = now();
            $periods[array_key_last($periods)]['restored_at'] = $restoredAt->toIso8601String();
            $periods[array_key_last($periods)]['restored_by'] = $request->user()?->id;
            $registry[$code]['periods'] = $periods;

            $setting->forceFill([
                'note' => json_encode($registry, JSON_UNESCAPED_UNICODE),
                'updated_by' => $request->user()?->id,
            ])->save();

            return ['already' => false, 'period' => $periods[array_key_last($periods)]];
        });

        if (! $result['already']) {
            $isCustom = Schema::hasTable('cotation_custom_cereals') && CotationCustomCereal::query()->where('code', $code)->exists();
            $name = CotationCustomCereal::query()->where('code', $code)->value('name')
                ?? $this->cerealLabelsConfig()[$code]
                ?? $this->marketService->deletionRegistry()[$code]['name']
                ?? $code;

            $this->auditLogService->log([
                'action' => 'restore_cotation_cereal',
                'module' => 'cotations',
                'description' => sprintf('Céréale « %s » restaurée', $name),
                'payload' => [
                    'cereal_code' => $code,
                    'cereal_name' => $name,
                    'cereal_type' => $isCustom ? 'custom' : 'matif',
                    'before' => ['deleted' => true, 'deleted_at' => $result['period']['deleted_at'], 'deleted_by' => $result['period']['deleted_by']],
                    'after' => ['deleted' => false, 'restored_at' => $result['period']['restored_at']],
                    'hidden' => in_array($code, $this->hiddenCerealCodes(), true),
                    'preserved_manual_lines' => CotationManualPrice::query()->where('product_code', $code)->count(),
                ],
            ]);
        }

        return response()->json([
            'ok' => true,
            'code' => $code,
            'already_restored' => $result['already'],
            'deleted_cereals' => $this->deletedCerealsList(),
        ]);
    }

    /**
     * Céréales actuellement supprimées, de la plus récente à la plus ancienne (édition uniquement).
     *
     * @return array<int, array{code: string, name: string, type: string, deleted_at: string, deleted_by_name: ?string}>
     */
    private function deletedCerealsList(): array
    {
        $deleted = $this->marketService->deletedCereals();
        if ($deleted === []) {
            return [];
        }

        $customs = Schema::hasTable('cotation_custom_cereals')
            ? CotationCustomCereal::query()->whereIn('code', array_keys($deleted))->pluck('name', 'code')->all()
            : [];
        $users = \App\Models\User::query()
            ->whereIn('id', array_filter(array_column($deleted, 'deleted_by')))
            ->get()
            ->keyBy('id');

        return collect($deleted)
            ->map(fn (array $entry, string $code): array => [
                'code' => $code,
                'name' => (string) ($customs[$code] ?? $entry['name'] ?? $code),
                'type' => isset($customs[$code]) ? 'custom' : 'matif',
                'deleted_at' => $entry['deleted_at']->toIso8601String(),
                // Jamais inventé : null quand l'utilisateur est inconnu (anciens enregistrements).
                'deleted_by_name' => $entry['deleted_by'] ? $this->userLabel($users->get($entry['deleted_by'])) : null,
            ])
            ->sortByDesc('deleted_at')
            ->values()
            ->all();
    }

    private function cerealInfoConfig(): string
    {
        if (! Schema::hasTable('cotation_settings')) {
            return '';
        }

        $note = CotationSetting::query()->where('key', 'cereal_info_html')->value('note');

        return CerealInfoSanitizer::sanitize($note);
    }

    private function updateCerealInfo(?string $html, Request $request): ?array
    {
        if ($html === null || ! Schema::hasTable('cotation_settings')) {
            return null;
        }

        $before = $this->cerealInfoConfig();
        $sanitized = CerealInfoSanitizer::sanitize($html);

        $setting = CotationSetting::query()->firstOrNew(['key' => 'cereal_info_html']);
        $setting->forceFill([
            'section' => 'cereal_info',
            'label' => 'Information cotations céréales',
            'value' => null,
            'unit' => null,
            'note' => $sanitized !== '' ? $sanitized : null,
            'sort_order' => 5,
            'updated_by' => $request->user()?->id,
        ])->save();

        $after = $this->cerealInfoConfig();

        return $before !== $after ? [
            'before' => $before,
            'after' => $after,
        ] : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>|null
     */
    private function updateFuelGrid(?array $payload, Request $request, array $before): ?array
    {
        if ($payload === null) {
            return null;
        }

        $setting = CotationSetting::query()->firstOrNew(['key' => 'fuel_grid_config']);
        $setting->forceFill([
            'section' => 'fuel_grid',
            'label' => 'Tableau prix carburant',
            'value' => null,
            'unit' => null,
            'note' => json_encode($this->normalizeFuelGrid($payload), JSON_UNESCAPED_UNICODE),
            'sort_order' => 10,
            'updated_by' => $request->user()?->id,
        ])->save();

        $after = $this->fuelGridConfig();

        if ($before === $after) {
            return null;
        }

        CotationFuelPriceHistory::query()->create([
            'fuel_grid' => $after,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return [
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fuelGridValidationRules(bool $required = false): array
    {
        return [
            'fuel_grid' => [$required ? 'required' : 'nullable', 'array'],
            'fuel_grid.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fuel_grid.sections' => ['nullable', 'array', 'max:10'],
            'fuel_grid.sections.*.id' => ['nullable', 'string', 'max:80'],
            'fuel_grid.sections.*.label' => ['nullable', 'string', 'max:255'],
            'fuel_grid.sections.*.rows' => ['nullable', 'array', 'max:30'],
            'fuel_grid.sections.*.rows.*.id' => ['nullable', 'string', 'max:80'],
            'fuel_grid.sections.*.rows.*.tranche' => ['nullable', 'string', 'max:255'],
            'fuel_grid.sections.*.rows.*.ht' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.sections.*.rows.*.gap' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'fuel_grid.sections.*.rows.*.text' => ['nullable', 'string', 'max:500'],
            'fuel_grid.gnr_tax' => ['nullable', 'array'],
            'fuel_grid.gnr_tax.ht' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.gnr_tax.ttc' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.gazole' => ['nullable', 'array'],
            'fuel_grid.gazole.id' => ['nullable', 'string', 'max:80'],
            'fuel_grid.gazole.label' => ['nullable', 'string', 'max:255'],
            'fuel_grid.gazole.tranche' => ['nullable', 'string', 'max:255'],
            'fuel_grid.gazole.ht' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.gazole.ttc' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.gazole.gap' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'fuel_grid.gazole.text' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function cotationValidationMessages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'required_with' => 'Le champ :attribute est obligatoire.',
            'array' => 'Le champ :attribute doit être une liste valide.',
            'string' => 'Le champ :attribute doit être un texte.',
            'numeric' => 'Le champ :attribute doit être un nombre.',
            'integer' => 'Le champ :attribute doit être un nombre entier.',
            'in' => 'La valeur sélectionnée pour :attribute est invalide.',
            'size.string' => 'Le champ :attribute doit contenir exactement :size caractères.',
            'max.string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
            'max.array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
            'max.numeric' => 'Le champ :attribute ne peut pas être supérieur à :max.',
            'min.numeric' => 'Le champ :attribute doit être au moins égal à :min.',
            'min.integer' => 'Le champ :attribute doit être au moins égal à :min.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function cotationValidationAttributes(): array
    {
        return [
            'settings.*.note' => 'note',
            'manual_prices.*.product_name' => 'nom de la céréale',
            'manual_prices.*.contract_code' => 'code contrat',
            'manual_prices.*.display_label' => 'livraison',
            'manual_prices.*.maturity_label' => 'libellé d’échéance',
            'custom_cereals.*.name' => 'nom de la céréale personnalisée',
            'cereal_labels.*' => 'nom de céréale',
            'cereal_table_labels.*.free_text' => 'libellé Livraison',
            'cereal_table_labels.*.maturity' => 'libellé Échéance',
            'cereal_table_labels.*.matif' => 'libellé MATIF',
            'cereal_table_labels.*.base' => 'libellé Base',
            'cereal_table_labels.*.final_price' => 'libellé Prix final',
            'cereal_info_html' => 'bloc Information',
            'transport_grid.title' => 'titre du tableau transport',
            'transport_grid.first_column_label' => 'première colonne transport',
            'transport_grid.columns.*.label' => 'colonne transport',
            'transport_grid.rows.*.label' => 'ligne transport',
            'transport_grid.cells.*.text' => 'texte du tableau transport',
            'transport_grid.sections.*.title' => 'titre de section transport',
            'transport_grid.sections.*.first_column_label' => 'première colonne transport',
            'transport_grid.sections.*.columns.*.label' => 'colonne transport',
            'transport_grid.sections.*.rows.*.label' => 'ligne transport',
            'transport_grid.sections.*.cells.*.text' => 'texte du tableau transport',
            'fuel_grid.sections.*.label' => 'titre carburant',
            'fuel_grid.sections.*.rows.*.tranche' => 'libellé carburant',
            'fuel_grid.sections.*.rows.*.text' => 'texte carburant',
            'fuel_grid.gazole.label' => 'titre gazole',
            'fuel_grid.gazole.tranche' => 'libellé gazole',
            'fuel_grid.gazole.text' => 'texte gazole',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeTransportGrid(array $config): array
    {
        $rawSections = collect($config['sections'] ?? [])
            ->filter(fn ($section): bool => is_array($section))
            ->take(20)
            ->values()
            ->all();
        $sections = collect($rawSections !== [] ? $rawSections : [$config])
            ->map(fn (array $section, int $index): array => $this->normalizeTransportSection($section, $index))
            ->values()
            ->all();
        $firstSection = $sections[0] ?? $this->normalizeTransportSection([], 0);

        return [
            ...$firstSection,
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeTransportSection(array $config, int $sectionIndex): array
    {
        $title = trim((string) ($config['title'] ?? ''));
        $firstColumnLabel = trim((string) ($config['first_column_label'] ?? ''));
        $columns = collect($config['columns'] ?? [])
            ->filter(fn ($row): bool => is_array($row))
            ->take(12)
            ->values()
            ->map(fn (array $column, int $index): array => [
                'id' => trim((string) ($column['id'] ?? '')) ?: 'col_'.($index + 1),
                'label' => trim((string) ($column['label'] ?? '')) ?: 'Colonne '.($index + 1),
                'reference_key' => trim((string) ($column['reference_key'] ?? $config['reference_key'] ?? '')),
                'base' => $this->nullableDecimal($column['base'] ?? null) ?? 0.0,
            ])
            ->all();

        $rows = collect($config['rows'] ?? [])
            ->filter(fn ($row): bool => is_array($row))
            ->take(30)
            ->values()
            ->map(fn (array $row, int $index): array => [
                'id' => trim((string) ($row['id'] ?? '')) ?: 'row_'.($index + 1),
                'label' => trim((string) ($row['label'] ?? '')) ?: 'Ligne '.($index + 1),
                'base' => $this->nullableDecimal($row['base'] ?? null) ?? 0.0,
            ])
            ->all();

        if ($columns === []) {
            $columns = [['id' => 'col_1', 'label' => 'Colonne 1', 'base' => 0.0]];
        }

        if ($rows === []) {
            $rows = [['id' => 'row_1', 'label' => 'Ligne 1', 'base' => 0.0]];
        }

        $cells = collect($config['cells'] ?? [])
            ->filter(fn ($cell, $key): bool => is_string($key) && is_array($cell))
            ->map(fn (array $cell): array => [
                'text' => trim((string) ($cell['text'] ?? '')),
            ])
            ->all();

        return [
            'id' => trim((string) ($config['id'] ?? '')) ?: 'transport_'.($sectionIndex + 1),
            'title' => $title !== '' ? $title : 'PRIX DES TRANSPORTS',
            'first_column_label' => $firstColumnLabel !== '' ? $firstColumnLabel : 'TRANSPORT',
            'reference_key' => trim((string) ($config['reference_key'] ?? '')),
            'columns' => $columns,
            'rows' => $rows,
            'cells' => $cells,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeFuelGrid(array $config): array
    {
        $defaultSections = [
            ['id' => 'fuel_grand_froid', 'label' => 'FUEL GRAND FROID'],
            ['id' => 'gnr_agri', 'label' => 'GNR AGRI Enregistré'],
            ['id' => 'gnr_taxe', 'label' => 'GNR Taxé'],
        ];

        $sections = collect($config['sections'] ?? $defaultSections)
            ->filter(fn ($section): bool => is_array($section))
            ->take(10)
            ->values()
            ->map(function (array $section, int $index) use ($defaultSections): array {
                $defaultRows = [
                    ['id' => 'row_1', 'tranche' => '0 à 999 L', 'ht' => null, 'gap' => 0, 'text' => ''],
                    ['id' => 'row_2', 'tranche' => '1 000 à 1 999 L', 'ht' => null, 'gap' => 0, 'text' => ''],
                    ['id' => 'row_3', 'tranche' => '2 000 L et +', 'ht' => null, 'gap' => 0, 'text' => ''],
                ];
                $rows = collect($section['rows'] ?? $defaultRows)
                    ->filter(fn ($row): bool => is_array($row))
                    ->take(30)
                    ->values()
                    ->map(fn (array $row, int $rowIndex): array => [
                        'id' => trim((string) ($row['id'] ?? '')) ?: 'row_'.($rowIndex + 1),
                        'tranche' => trim((string) ($row['tranche'] ?? '')) ?: 'Tranche '.($rowIndex + 1),
                        'ht' => $this->nullableDecimal($row['ht'] ?? null),
                        'gap' => $this->nullableDecimal($row['gap'] ?? null) ?? 0.0,
                        'text' => trim((string) ($row['text'] ?? '')),
                    ])
                    ->all();

                return [
                    'id' => trim((string) ($section['id'] ?? '')) ?: ($defaultSections[$index]['id'] ?? 'section_'.($index + 1)),
                    'label' => trim((string) ($section['label'] ?? '')) ?: ($defaultSections[$index]['label'] ?? 'Section '.($index + 1)),
                    'rows' => $rows !== [] ? $rows : $defaultRows,
                ];
            })
            ->all();

        $gnrTax = is_array($config['gnr_tax'] ?? null) ? $config['gnr_tax'] : [];
        $gazole = is_array($config['gazole'] ?? null) ? $config['gazole'] : [];

        return [
            'vat_rate' => $this->nullableDecimal($config['vat_rate'] ?? null) ?? 20.0,
            'sections' => $sections,
            'gnr_tax' => [
                'ht' => $this->nullableDecimal($gnrTax['ht'] ?? null),
                'ttc' => $this->nullableDecimal($gnrTax['ttc'] ?? null),
            ],
            'gazole' => [
                'id' => trim((string) ($gazole['id'] ?? '')) ?: 'gazole',
                'label' => trim((string) ($gazole['label'] ?? '')) ?: 'GAZOLE',
                'tranche' => trim((string) ($gazole['tranche'] ?? '')) ?: 'GAZOLE',
                'ttc' => $this->nullableDecimal($gazole['ttc'] ?? $gazole['ht'] ?? null),
                'gap' => $this->nullableDecimal($gazole['gap'] ?? null) ?? 0.0,
                'text' => trim((string) ($gazole['text'] ?? '')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingSnapshot(CotationSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'section' => $setting->section,
            'key' => $setting->key,
            'label' => $setting->label,
            'value' => $setting->value !== null ? (float) $setting->value : null,
            'unit' => $setting->unit,
            'note' => $setting->note,
            'sort_order' => $setting->sort_order,
        ];
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function filledManualPriceRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->filter(function (array $row): bool {
                return $this->hasDecimalInput($row['manual_matif'] ?? null)
                    || $this->hasDecimalInput($row['final_price_reference_key'] ?? null)
                    || $this->hasDecimalInput($row['base'] ?? null)
                    || $this->hasDecimalInput($row['sale_price'] ?? null)
                    || $this->hasDecimalInput($row['manual_id'] ?? null)
                    || $this->hasDecimalInput($row['display_label'] ?? null)
                    || $this->hasDecimalInput($row['maturity_label'] ?? null);
            })
            ->values()
            ->all();
    }

    /**
     * Une échéance MATIF ne peut être choisie que si elle est encore proposée par la source et qu'elle
     * appartient à la céréale MATIF d'origine de la ligne : jamais en fonction de l'année de récolte.
     * Une échéance déjà enregistrée sur la ligne (échue, ou d'une ancienne référence) reste acceptée
     * telle quelle : seule une nouvelle sélection est contrôlée. Exécuté avant toute écriture.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $customCereals
     */
    private function assertSelectableMarketLines(array $rows, array $customCereals): void
    {
        $submittedBases = [];
        foreach ($customCereals as $cereal) {
            if (! empty($cereal['code']) && ! empty($cereal['base_product_code'])) {
                $submittedBases[mb_strtoupper((string) $cereal['code'], 'UTF-8')] = (string) $cereal['base_product_code'];
            }
        }

        $errors = [];

        foreach ($rows as $index => $row) {
            $hash = ($row['line_type'] ?? '') === 'matif' ? trim((string) ($row['market_identity_hash'] ?? '')) : '';
            if ($hash === '') {
                continue;
            }

            $manual = $this->hasDecimalInput($row['manual_id'] ?? null)
                ? CotationManualPrice::query()->find((int) $row['manual_id'])
                : null;
            if ($manual && $manual->market_identity_hash === $hash) {
                continue;
            }

            $productCode = mb_strtoupper(trim((string) $row['product_code']), 'UTF-8');
            $base = $submittedBases[$productCode] ?? $this->marketService->baseProductCodeFor($productCode);
            // Céréale inconnue (personnalisée créée dans cette requête sans code transmis) : rien à comparer.
            if ($base === null) {
                continue;
            }

            if (! in_array($hash, $this->marketService->activeIdentityHashes($base), true)) {
                $errors["manual_prices.$index.market_identity_hash"] = 'Cette échéance MATIF n\'est plus proposée ou ne correspond pas à la céréale MATIF d\'origine.';
            }
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $customCerealNamesByCode
     * @return array<int, array<string, mixed>>
     */
    private function updateManualPrices(array $rows, Request $request, array $customCerealNamesByCode = []): array
    {
        if ($rows === []) {
            return [];
        }

        $changes = [];

        foreach ($rows as $row) {
            $lineType = ($row['line_type'] ?? '') === 'matif' ? 'matif' : 'custom';
            $productCode = mb_strtoupper(trim((string) $row['product_code']), 'UTF-8');
            $productName = $customCerealNamesByCode[$productCode] ?? trim((string) $row['product_name']);
            $maturityMonth = array_key_exists('maturity_month', $row) && $row['maturity_month'] !== null && $row['maturity_month'] !== ''
                ? (int) $row['maturity_month']
                : null;
            $displayLabel = trim((string) ($row['display_label'] ?? ''));
            $maturityLabel = trim((string) ($row['maturity_label'] ?? ''));
            if ($maturityLabel === '') {
                $maturityLabel = $lineType === 'custom' ? 'Option personnalisée' : 'Échéance personnalisée';
            }
            $marketIdentityHash = $lineType === 'matif' && $this->hasDecimalInput($row['market_identity_hash'] ?? null)
                ? (string) $row['market_identity_hash']
                : null;

            $identityHash = $this->hasDecimalInput($row['identity_hash'] ?? null)
                ? (string) $row['identity_hash']
                : sha1(uniqid('cotation-line-', true).'-'.random_int(1, PHP_INT_MAX));

            $manual = $this->hasDecimalInput($row['manual_id'] ?? null)
                ? CotationManualPrice::query()->find((int) $row['manual_id'])
                : null;

            if (! $manual) {
                $manual = new CotationManualPrice(['identity_hash' => $identityHash]);
            }

            $before = $manual->exists ? $this->manualPriceSnapshot($manual) : null;

            $values = [
                'identity_hash' => $manual->identity_hash ?: $identityHash,
                'market_identity_hash' => $marketIdentityHash,
                'line_type' => $lineType,
                'product_code' => $productCode,
                'product_name' => $productName,
                'product_sort' => (int) ($row['product_sort'] ?? 999),
                'contract_code' => $lineType === 'matif' ? (trim((string) ($row['contract_code'] ?? '')) ?: null) : null,
                'display_label' => $displayLabel ?: null,
                'maturity_label' => $maturityLabel,
                'maturity_month' => $lineType === 'matif' ? $maturityMonth : null,
                'maturity_year' => (int) $row['maturity_year'],
                'harvest_year' => (int) $row['harvest_year'],
                'manual_matif' => $lineType === 'custom' ? $this->nullableDecimal($row['manual_matif'] ?? null) : null,
                'base' => $this->nullablePositiveInteger($row['base'] ?? null),
                // Champ absent (ancien client) : on conserve le signe déjà enregistré.
                'base_operation' => CotationManualPrice::normalizeBaseOperation(
                    $row['base_operation'] ?? $manual->base_operation,
                ),
                // Champs absents (ancien client) : on conserve les valeurs déjà enregistrées.
                'sale_price' => array_key_exists('sale_price', $row)
                    ? $this->nullableDecimal($row['sale_price'])
                    : $manual->sale_price,
                'sale_price_operation' => CotationManualPrice::normalizeSaleOperation(
                    $row['sale_price_operation'] ?? $manual->sale_price_operation,
                ),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'updated_by' => $request->user()?->id,
            ];

            if (Schema::hasColumn('cotation_manual_prices', 'final_price_reference_key')) {
                $values['final_price_reference_key'] = $lineType === 'custom'
                    ? (trim((string) ($row['final_price_reference_key'] ?? '')) ?: null)
                    : null;
            }

            $manual->forceFill($values)->save();

            $after = $this->manualPriceSnapshot($manual->refresh());

            if ($before !== $after) {
                $changes[] = [
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function updateCustomCereals(array $rows, Request $request): array
    {
        if (! Schema::hasTable('cotation_custom_cereals')) {
            return [];
        }

        if ($rows === []) {
            return [];
        }

        $changes = [];

        foreach ($rows as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $cereal = isset($row['id']) && $row['id']
                ? CotationCustomCereal::query()->find((int) $row['id'])
                : null;

            if (! $cereal) {
                $code = trim((string) ($row['code'] ?? ''));
                if ($code === '') {
                    $code = 'C'.substr(sha1($name.'-'.microtime(true).'-'.$index), 0, 12);
                }

                $cereal = new CotationCustomCereal(['code' => mb_strtoupper($code, 'UTF-8')]);
            }

            $before = $cereal->exists ? $this->customCerealSnapshot($cereal) : null;
            $previousName = $before['name'] ?? null;

            $cereal->forceFill([
                'name' => $name,
                'base_product_code' => (string) $row['base_product_code'],
                'sort_order' => (int) ($row['sort_order'] ?? 100 + $index),
                'updated_by' => $request->user()?->id,
            ])->save();

            if ($previousName !== null && $previousName !== $name) {
                // La céréale personnalisée a été renommée : product_name est
                // dénormalisé sur chaque échéance déjà enregistrée, donc on
                // resynchronise toutes les lignes existantes via product_code
                // (clé stable, jamais modifiée par un renommage).
                $updated = CotationManualPrice::query()
                    ->where('product_code', $cereal->code)
                    ->update(['product_name' => $name]);

                Log::info('custom cereal product_name sync', [
                    'code' => $cereal->code,
                    'previous_name' => $previousName,
                    'name' => $name,
                    'updated_rows' => $updated,
                ]);
            }

            $after = $this->customCerealSnapshot($cereal->refresh());

            if ($before !== $after) {
                $changes[] = [
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        return $changes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customCerealsPayload(): array
    {
        if (! Schema::hasTable('cotation_custom_cereals')) {
            return [];
        }

        return CotationCustomCereal::query()
            ->select(['id', 'code', 'name', 'base_product_code', 'sort_order'])
            ->whereNotIn('code', array_keys($this->marketService->deletedCereals()))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CotationCustomCereal $cereal): array => $this->customCerealSnapshot($cereal))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function customCerealSnapshot(CotationCustomCereal $cereal): array
    {
        return [
            'id' => $cereal->id,
            'code' => $cereal->code,
            'name' => $cereal->name,
            'base_product_code' => $cereal->base_product_code,
            'sort_order' => $cereal->sort_order,
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function deleteManualPrices(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $deleted = [];

        CotationManualPrice::query()
            ->whereIn('id', $ids)
            ->get()
            ->each(function (CotationManualPrice $manual) use (&$deleted): void {
                $deleted[] = $this->manualPriceSnapshot($manual);
                $manual->delete();
            });

        return $deleted;
    }

    /**
     * @return array<string, mixed>
     */
    private function manualPriceSnapshot(CotationManualPrice $manual): array
    {
        return [
            'identity_hash' => $manual->identity_hash,
            'market_identity_hash' => $manual->market_identity_hash,
            'line_type' => $manual->line_type,
            'product_code' => $manual->product_code,
            'product_name' => $manual->product_name,
            'display_label' => $manual->display_label,
            'maturity_label' => $manual->maturity_label,
            'maturity_month' => $manual->maturity_month,
            'maturity_year' => $manual->maturity_year,
            'harvest_year' => $manual->harvest_year,
            'manual_matif' => $manual->manual_matif !== null ? (float) $manual->manual_matif : null,
            'final_price_reference_key' => Schema::hasColumn('cotation_manual_prices', 'final_price_reference_key') ? $manual->final_price_reference_key : null,
            'base' => $manual->base !== null ? (float) $manual->base : null,
            'base_operation' => CotationManualPrice::normalizeBaseOperation($manual->base_operation),
            'sale_price' => $manual->sale_price !== null ? (float) $manual->sale_price : null,
            'sale_price_operation' => CotationManualPrice::normalizeSaleOperation($manual->sale_price_operation),
            'margin' => $manual->margin !== null ? (float) $manual->margin : null,
            'sort_order' => $manual->sort_order,
        ];
    }

    private function hasDecimalInput(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if (! $this->hasDecimalInput($value)) {
            return null;
        }

        return (float) str_replace(',', '.', (string) $value);
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if (! $this->hasDecimalInput($value)) {
            return null;
        }

        return abs((int) $value);
    }

    private function userLabel(mixed $user): ?string
    {
        if (! $user) {
            return null;
        }

        $fullName = trim((string) ($user->first_name ?? '').' '.(string) ($user->last_name ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        $name = trim((string) ($user->name ?? ''));

        return $name !== '' ? $name : null;
    }
}

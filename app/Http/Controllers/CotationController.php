<?php

namespace App\Http\Controllers;

use App\Models\CotationCustomCereal;
use App\Models\CotationFuelPriceHistory;
use App\Models\CotationManualPrice;
use App\Models\CotationSetting;
use App\Services\AuditLogService;
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
                'transportGrid' => $transportGrid,
                'fuelGrid' => $fuelGrid,
                'marketData' => $marketData,
                'permissions' => [
                    'can_view_cereals' => $canViewCereals,
                    'can_view_fuel' => $canViewFuel,
                    'can_manage' => $canEditCereals,
                    'can_manage_fuel' => $canEditFuel,
                    'can_view_fuel_history' => $canViewFuelHistory,
                    'can_admin' => $canAdmin,
                ],
                'routes' => [
                    'market_data' => route('cotations.market-data'),
                    'settings_update' => route('cotations.settings.update'),
                    'fuel_settings_update' => route('cotations.fuel-settings.update'),
                    'fuel_history' => route('cotations.fuel-history'),
                    'export_pdf' => route('cotations.export-pdf'),
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
        $request->merge([
            'manual_prices' => $this->filledManualPriceRows($request->input('manual_prices', [])),
            'custom_cereals' => collect($request->input('custom_cereals', []))
                ->filter(fn ($row): bool => is_array($row) && (trim((string) ($row['name'] ?? '')) !== '' || ! empty($row['id'])))
                ->values()
                ->all(),
        ]);

        $settings = CotationSetting::query()->orderBy('section')->orderBy('sort_order')->get();
        $ids = $settings->pluck('id')->map(fn (int $id): string => (string) $id)->all();

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.id' => ['required', Rule::in($ids)],
            'settings.*.value' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'settings.*.note' => ['nullable', 'string', 'max:1000'],
            'manual_prices' => ['nullable', 'array'],
            'manual_prices.*.manual_id' => ['nullable', 'integer'],
            'manual_prices.*.identity_hash' => ['nullable', 'string', 'size:40'],
            'manual_prices.*.market_identity_hash' => ['nullable', 'string', 'size:40'],
            'manual_prices.*.line_type' => ['nullable', Rule::in(['matif', 'custom'])],
            'manual_prices.*.product_code' => ['required_with:manual_prices', 'string', 'max:16'],
            'manual_prices.*.product_name' => ['required_with:manual_prices', 'string', 'max:80'],
            'manual_prices.*.product_sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_prices.*.contract_code' => ['nullable', 'string', 'max:80'],
            'manual_prices.*.display_label' => ['nullable', 'string', 'max:80'],
            'manual_prices.*.maturity_label' => ['nullable', 'string', 'max:80'],
            'manual_prices.*.maturity_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'manual_prices.*.maturity_year' => ['required_with:manual_prices', 'integer', 'min:2020', 'max:2100'],
            'manual_prices.*.harvest_year' => ['required_with:manual_prices', 'integer', 'min:2020', 'max:2100'],
            'manual_prices.*.manual_matif' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'manual_prices.*.margin' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'manual_prices.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'deleted_manual_price_ids' => ['nullable', 'array'],
            'deleted_manual_price_ids.*' => ['integer', 'min:1'],
            'custom_cereals' => ['nullable', 'array'],
            'custom_cereals.*.id' => ['nullable', 'integer'],
            'custom_cereals.*.code' => ['nullable', 'string', 'max:16'],
            'custom_cereals.*.name' => ['required_with:custom_cereals', 'string', 'max:80'],
            'custom_cereals.*.base_product_code' => ['required_with:custom_cereals', Rule::in(['ECO', 'EBM', 'EMA'])],
            'custom_cereals.*.sort_order' => ['nullable', 'integer', 'min:100', 'max:9999'],
            'cereal_order' => ['nullable', 'array', 'max:100'],
            'cereal_order.*' => ['string', 'max:16'],
            'cereal_info_html' => ['nullable', 'string', 'max:20000'],
            'transport_grid' => ['nullable', 'array'],
            'transport_grid.title' => ['nullable', 'string', 'max:120'],
            'transport_grid.first_column_label' => ['nullable', 'string', 'max:80'],
            'transport_grid.reference_key' => ['nullable', 'string', 'max:180'],
            'transport_grid.columns' => ['nullable', 'array', 'max:12'],
            'transport_grid.columns.*.id' => ['required_with:transport_grid.columns', 'string', 'max:80'],
            'transport_grid.columns.*.label' => ['nullable', 'string', 'max:80'],
            'transport_grid.columns.*.reference_key' => ['nullable', 'string', 'max:180'],
            'transport_grid.columns.*.base' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'transport_grid.rows' => ['nullable', 'array', 'max:30'],
            'transport_grid.rows.*.id' => ['required_with:transport_grid.rows', 'string', 'max:80'],
            'transport_grid.rows.*.label' => ['nullable', 'string', 'max:80'],
            'transport_grid.rows.*.base' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'transport_grid.cells' => ['nullable', 'array'],
            'transport_grid.cells.*.text' => ['nullable', 'string', 'max:120'],
            'transport_grid.sections' => ['nullable', 'array', 'max:20'],
            'transport_grid.sections.*.id' => ['nullable', 'string', 'max:80'],
            'transport_grid.sections.*.title' => ['nullable', 'string', 'max:120'],
            'transport_grid.sections.*.first_column_label' => ['nullable', 'string', 'max:80'],
            'transport_grid.sections.*.reference_key' => ['nullable', 'string', 'max:180'],
            'transport_grid.sections.*.columns' => ['nullable', 'array', 'max:12'],
            'transport_grid.sections.*.columns.*.id' => ['required_with:transport_grid.sections.*.columns', 'string', 'max:80'],
            'transport_grid.sections.*.columns.*.label' => ['nullable', 'string', 'max:80'],
            'transport_grid.sections.*.columns.*.reference_key' => ['nullable', 'string', 'max:180'],
            'transport_grid.sections.*.columns.*.base' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'transport_grid.sections.*.rows' => ['nullable', 'array', 'max:30'],
            'transport_grid.sections.*.rows.*.id' => ['required_with:transport_grid.sections.*.rows', 'string', 'max:80'],
            'transport_grid.sections.*.rows.*.label' => ['nullable', 'string', 'max:80'],
            'transport_grid.sections.*.rows.*.base' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'transport_grid.sections.*.cells' => ['nullable', 'array'],
            'transport_grid.sections.*.cells.*.text' => ['nullable', 'string', 'max:120'],
            ...$this->fuelGridValidationRules(),
        ]);

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
        $cerealInfoChange = $this->updateCerealInfo($validated['cereal_info_html'] ?? null, $request);
        $transportGridChange = $this->updateTransportGrid($validated['transport_grid'] ?? null, $request, $transportGridBefore);
        $fuelGridChange = null;
        $manualRows = collect($validated['manual_prices'] ?? [])
            ->reject(fn (array $row): bool => isset($row['manual_id']) && in_array((int) $row['manual_id'], $deletedIds, true))
            ->values()
            ->all();
        $manualDeletes = $this->deleteManualPrices($deletedIds);
        $manualChanges = $this->updateManualPrices($manualRows, $request);
        $hasManualChanges = $manualChanges !== [] || $manualDeletes !== [] || $customCerealChanges !== [] || $cerealOrderChange !== null || $cerealInfoChange !== null || $transportGridChange !== null || $fuelGridChange !== null;

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
                'cereal_info' => $cerealInfoChange,
                'transport_grid' => $transportGridChange,
                'fuel_grid' => $fuelGridChange,
            ],
        ]);

        return back()->with('status', 'Cotations settings updated.');
    }

    public function updateFuelSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->fuelGridValidationRules(required: true));
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

    public function exportPdf(Request $request)
    {
        $access = app(AccessManager::class);
        $user = $request->user();
        abort_unless($user && $access->can($user, 'cotations.cereals.edit'), 403);

        try {
            $displaySettings = $this->displaySettings();
            $leftYear = (int) ($displaySettings['harvest_left_year'] ?? now()->year);
            $rightYear = (int) ($displaySettings['harvest_right_year'] ?? now()->year + 1);

            $cerealOrder = $this->cerealOrderConfig();
            $groups = $this->applyCerealOrder(
                $this->marketService->latestGroups($leftYear, $rightYear, false, false),
                $cerealOrder,
            );
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
                'cerealHarvestTables' => $this->buildCerealHarvestTables($groups, ['left' => $leftYear, 'right' => $rightYear]),
                'transportGrid' => $this->transportGridConfig(),
                'fuelGrid' => CotationFuelCalculator::compute($this->fuelGridConfig()),
                'finalPriceByKey' => $finalPriceByKey,
                'generatedAt' => now(),
            ];

            // Page 1 = céréales + transport (le transport garde sa mise en
            // forme normale, jamais resserrée), page 2 = carburant : 2 pages
            // au total dans le cas standard. On ne resserre que la grille
            // céréales (densité 0 -> 2) pour qu'elle tienne sur une seule
            // page ; si malgré tout le contenu déborde (beaucoup de
            // céréales/transport), on garde simplement le rendu le plus
            // compact obtenu et le surplus se reporte naturellement sur une
            // page supplémentaire.
            $pdf = null;
            $bestPageCount = null;
            foreach ([0, 1, 2] as $density) {
                $candidate = Pdf::loadView('cotations.pdf', [...$viewData, 'cerealDensity' => $density])
                    ->setPaper('a4', 'landscape');
                $candidate->render();
                $pageCount = $candidate->getDomPDF()->getCanvas()->get_page_count();

                if ($bestPageCount === null || $pageCount < $bestPageCount) {
                    $pdf = $candidate;
                    $bestPageCount = $pageCount;
                }

                if ($pageCount <= 2) {
                    break;
                }
            }

            return $pdf->download('cotations-'.now()->format('Y-m-d-His').'.pdf');
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
            ])->setPaper('a4', 'landscape');

            return $pdf->download('prix-carburant-'.now()->format('Y-m-d-His').'.pdf');
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
    private function buildCerealHarvestTables(array $groups, array $harvestYears): array
    {
        $tables = [];
        $stubWidth = 6;

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
                        'label' => (string) ($row['label'] ?: 'Échéance'),
                        'matif' => CotationPdfFormatter::price($row['matif'] ?? null),
                        'margin' => CotationPdfFormatter::margin($row['margin'] ?? null),
                        'final_price' => CotationPdfFormatter::price($row['final_price'] ?? null),
                    ];
                }

                $cerealGroups[] = [
                    'name' => (string) ($group['name'] ?? 'Céréale'),
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
                'stub_width' => $stubWidth,
                'column_width' => $totalColumns > 0 ? (100 - $stubWidth) / $totalColumns : 0,
                'has_data' => $totalColumns > 0,
            ];
        }

        return $tables;
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
        $cerealOrder = $this->cerealOrderConfig();
        $groups = $this->applyCerealOrder($this->marketService->latestGroups(
            (int) ($displaySettings['harvest_left_year'] ?? now()->year),
            (int) ($displaySettings['harvest_right_year'] ?? now()->year + 1),
            $canManage,
        ), $cerealOrder);

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
            'options' => $this->marketService->marketOptions(
                (int) ($displaySettings['harvest_left_year'] ?? now()->year),
                (int) ($displaySettings['harvest_right_year'] ?? now()->year + 1),
            ),
            'custom_cereals' => $this->customCerealsPayload(),
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
            'fuel_grid.sections.*.label' => ['nullable', 'string', 'max:120'],
            'fuel_grid.sections.*.rows' => ['nullable', 'array', 'max:30'],
            'fuel_grid.sections.*.rows.*.id' => ['nullable', 'string', 'max:80'],
            'fuel_grid.sections.*.rows.*.tranche' => ['nullable', 'string', 'max:120'],
            'fuel_grid.sections.*.rows.*.ht' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.sections.*.rows.*.gap' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'fuel_grid.sections.*.rows.*.text' => ['nullable', 'string', 'max:120'],
            'fuel_grid.gnr_tax' => ['nullable', 'array'],
            'fuel_grid.gnr_tax.ht' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.gnr_tax.ttc' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.gazole' => ['nullable', 'array'],
            'fuel_grid.gazole.id' => ['nullable', 'string', 'max:80'],
            'fuel_grid.gazole.label' => ['nullable', 'string', 'max:120'],
            'fuel_grid.gazole.tranche' => ['nullable', 'string', 'max:120'],
            'fuel_grid.gazole.ht' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.gazole.ttc' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'fuel_grid.gazole.gap' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'fuel_grid.gazole.text' => ['nullable', 'string', 'max:120'],
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
                    || $this->hasDecimalInput($row['margin'] ?? null)
                    || $this->hasDecimalInput($row['manual_id'] ?? null)
                    || $this->hasDecimalInput($row['display_label'] ?? null)
                    || $this->hasDecimalInput($row['maturity_label'] ?? null);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function updateManualPrices(array $rows, Request $request): array
    {
        if ($rows === []) {
            return [];
        }

        $changes = [];

        foreach ($rows as $row) {
            $lineType = ($row['line_type'] ?? '') === 'matif' ? 'matif' : 'custom';
            $maturityMonth = array_key_exists('maturity_month', $row) && $row['maturity_month'] !== null && $row['maturity_month'] !== ''
                ? (int) $row['maturity_month']
                : null;
            $displayLabel = trim((string) ($row['display_label'] ?? ''));
            $maturityLabel = trim((string) ($row['maturity_label'] ?? '')) ?: $displayLabel;
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

            $manual->forceFill([
                'identity_hash' => $manual->identity_hash ?: $identityHash,
                'market_identity_hash' => $marketIdentityHash,
                'line_type' => $lineType,
                'product_code' => mb_strtoupper(trim((string) $row['product_code']), 'UTF-8'),
                'product_name' => trim((string) $row['product_name']),
                'product_sort' => (int) ($row['product_sort'] ?? 999),
                'contract_code' => $lineType === 'matif' ? (trim((string) ($row['contract_code'] ?? '')) ?: null) : null,
                'display_label' => $displayLabel ?: null,
                'maturity_label' => $maturityLabel ?: 'Échéance personnalisée',
                'maturity_month' => $lineType === 'matif' ? $maturityMonth : null,
                'maturity_year' => (int) $row['maturity_year'],
                'harvest_year' => (int) $row['harvest_year'],
                'manual_matif' => $lineType === 'custom' ? $this->nullableDecimal($row['manual_matif'] ?? null) : null,
                'margin' => $this->nullablePositiveInteger($row['margin'] ?? null),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'updated_by' => $request->user()?->id,
            ])->save();

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

            $cereal->forceFill([
                'name' => $name,
                'base_product_code' => (string) $row['base_product_code'],
                'sort_order' => (int) ($row['sort_order'] ?? 100 + $index),
                'updated_by' => $request->user()?->id,
            ])->save();

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

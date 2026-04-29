<?php

namespace App\Http\Controllers;

use App\Models\AprevoirTask;
use App\Models\Depot;
use App\Models\Transporter;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Access\AccessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    private const MIN_QUERY_LENGTH = 2;
    private const LIMIT_PER_CATEGORY = 10;
    private const MIN_FUZZY_QUERY_LENGTH = 3;
    private const FUZZY_TRIGGER_MAX_EXACT_RESULTS = 2;
    private const FUZZY_POOL_LIMIT = 150;
    private const FUZZY_SCORE_THRESHOLD = 0.78;

    private const MATCH_EXACT_POINTS = 100;
    private const MATCH_PREFIX_POINTS = 75;
    private const MATCH_CONTAINS_POINTS = 50;
    private const MATCH_FUZZY_POINTS = 25;
    private const EXACT_SOURCE_BONUS = 300;

    public function __construct(
        private readonly AccessManager $accessManager,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $terms = $this->extractTerms($query);
        $normalizedQuery = $this->normalizeText($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH || $terms === []) {
            return response()->json([]);
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json([]);
        }

        $results = [];
        $results = [...$results, ...$this->searchUsers($terms, $normalizedQuery)];

        if ($this->canAccessTaskData($actor)) {
            $results = [...$results, ...$this->searchVehicles($terms, $normalizedQuery)];
            $results = [...$results, ...$this->searchTransporters($terms, $normalizedQuery)];
            $results = [...$results, ...$this->searchDepots($terms, $normalizedQuery)];
        }

        if ($this->canAccessAprevoir($actor)) {
            $results = [...$results, ...$this->searchAprevoirTasks($terms, $normalizedQuery)];
        }

        usort($results, function (array $a, array $b): int {
            $scoreA = (int) ($a['score'] ?? 0);
            $scoreB = (int) ($b['score'] ?? 0);
            if ($scoreA === $scoreB) {
                return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            }

            return $scoreB <=> $scoreA;
        });

        return response()->json(array_map(
            fn (array $item): array => [
                'type' => (string) ($item['type'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
            ],
            array_values($results),
        ));
    }

    private function searchUsers(array $terms, string $normalizedQuery): array
    {
        $exact = User::query()
            ->with('sector:id,name')
            ->select(['id', 'name', 'first_name', 'last_name', 'email', 'sector_id', 'phone', 'mobile_phone', 'internal_number', 'directory_phones'])
            ->where(fn ($builder) => $this->applyUsersTermsFilter($builder, $terms))
            ->orderByRaw("COALESCE(NULLIF(last_name, ''), NULLIF(name, ''), email) asc")
            ->orderByRaw("COALESCE(NULLIF(first_name, ''), '') asc")
            ->limit(self::LIMIT_PER_CATEGORY)
            ->get()
            ->map(function (User $user) use ($terms): array {
                $firstName = trim((string) $user->first_name);
                $lastName = trim((string) $user->last_name);
                $fullName = trim($firstName.' '.$lastName);
                $fallbackName = trim((string) $user->name);
                $label = $fullName !== '' ? $fullName : ($fallbackName !== '' ? $fallbackName : ('Utilisateur #'.$user->id));
                $sectorName = trim((string) ($user->sector?->name ?? ''));

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'user',
                        [
                            ['value' => (string) $user->first_name, 'weight' => 30],
                            ['value' => (string) $user->last_name, 'weight' => 30],
                            ['value' => (string) $user->name, 'weight' => 28],
                            ['value' => (string) $user->email, 'weight' => 14],
                            ['value' => (string) $user->phone, 'weight' => 16, 'phone' => true],
                            ['value' => (string) $user->mobile_phone, 'weight' => 16, 'phone' => true],
                            ['value' => (string) $user->internal_number, 'weight' => 12, 'phone' => true],
                            ['value' => is_array($user->directory_phones) ? json_encode($user->directory_phones) : (string) $user->directory_phones, 'weight' => 10, 'phone' => true],
                        ],
                        false
                    ),
                    'type' => 'user',
                    'label' => $label,
                    'description' => $sectorName !== '' ? 'Utilisateur / '.$sectorName : 'Utilisateur',
                    'url' => route('directory.show', $user),
                ];
            })
            ->values()
            ->all();

        if (! $this->shouldApplyFuzzy($normalizedQuery, count($exact))) {
            return $exact;
        }

        $exactKeys = array_fill_keys(array_map(fn (array $item): string => $this->resultKey($item), $exact), true);

        $fuzzy = User::query()
            ->with('sector:id,name')
            ->select(['id', 'name', 'first_name', 'last_name', 'email', 'sector_id', 'phone', 'mobile_phone', 'internal_number', 'directory_phones'])
            ->orderByRaw("COALESCE(NULLIF(last_name, ''), NULLIF(name, ''), email) asc")
            ->orderByRaw("COALESCE(NULLIF(first_name, ''), '') asc")
            ->limit(self::FUZZY_POOL_LIMIT)
            ->get()
            ->map(function (User $user) use ($terms): ?array {
                $searchText = implode(' ', array_filter([
                    (string) $user->name,
                    (string) $user->first_name,
                    (string) $user->last_name,
                    (string) $user->email,
                    (string) $user->phone,
                    (string) $user->mobile_phone,
                    (string) $user->internal_number,
                    is_array($user->directory_phones) ? json_encode($user->directory_phones) : (string) $user->directory_phones,
                ], static fn (string $value): bool => trim($value) !== ''));

                if ($this->fuzzyTermsScore($terms, $searchText) < self::FUZZY_SCORE_THRESHOLD) {
                    return null;
                }

                $firstName = trim((string) $user->first_name);
                $lastName = trim((string) $user->last_name);
                $fullName = trim($firstName.' '.$lastName);
                $fallbackName = trim((string) $user->name);
                $label = $fullName !== '' ? $fullName : ($fallbackName !== '' ? $fallbackName : ('Utilisateur #'.$user->id));
                $sectorName = trim((string) ($user->sector?->name ?? ''));

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'user',
                        [
                            ['value' => (string) $user->first_name, 'weight' => 30],
                            ['value' => (string) $user->last_name, 'weight' => 30],
                            ['value' => (string) $user->name, 'weight' => 28],
                            ['value' => (string) $user->email, 'weight' => 14],
                            ['value' => (string) $user->phone, 'weight' => 16, 'phone' => true],
                            ['value' => (string) $user->mobile_phone, 'weight' => 16, 'phone' => true],
                            ['value' => (string) $user->internal_number, 'weight' => 12, 'phone' => true],
                            ['value' => is_array($user->directory_phones) ? json_encode($user->directory_phones) : (string) $user->directory_phones, 'weight' => 10, 'phone' => true],
                        ],
                        true
                    ),
                    'type' => 'user',
                    'label' => $label,
                    'description' => $sectorName !== '' ? 'Utilisateur / '.$sectorName : 'Utilisateur',
                    'url' => route('directory.show', $user),
                ];
            })
            ->filter()
            ->reject(fn (array $item): bool => isset($exactKeys[$this->resultKey($item)]))
            ->values()
            ->all();

        return $this->mergeCategoryResults($exact, $fuzzy);
    }

    private function searchVehicles(array $terms, string $normalizedQuery): array
    {
        $exact = Vehicle::query()
            ->select(['id', 'name', 'registration'])
            ->where(fn ($builder) => $this->applyTermsFilter($builder, $terms, ['name', 'registration']))
            ->orderBy('name')
            ->orderBy('registration')
            ->limit(self::LIMIT_PER_CATEGORY)
            ->get()
            ->map(function (Vehicle $vehicle) use ($terms): array {
                $name = trim((string) $vehicle->name);
                $registration = trim((string) $vehicle->registration);
                $label = $name !== '' ? $name : ($registration !== '' ? $registration : ('Véhicule #'.$vehicle->id));
                $description = $registration !== '' ? 'Véhicule / '.$registration : 'Véhicule';

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'vehicle',
                        [
                            ['value' => (string) $vehicle->name, 'weight' => 24],
                            ['value' => (string) $vehicle->registration, 'weight' => 20],
                        ],
                        false
                    ),
                    'type' => 'vehicle',
                    'label' => $label,
                    'description' => $description,
                    'url' => route('task.data.index', ['section' => 'camions']),
                ];
            })
            ->values()
            ->all();

        if (! $this->shouldApplyFuzzy($normalizedQuery, count($exact))) {
            return $exact;
        }

        $exactKeys = array_fill_keys(array_map(fn (array $item): string => $this->resultKey($item), $exact), true);

        $fuzzy = Vehicle::query()
            ->select(['id', 'name', 'registration'])
            ->orderBy('name')
            ->orderBy('registration')
            ->limit(self::FUZZY_POOL_LIMIT)
            ->get()
            ->map(function (Vehicle $vehicle) use ($terms): ?array {
                $searchText = implode(' ', array_filter([
                    (string) $vehicle->name,
                    (string) $vehicle->registration,
                ], static fn (string $value): bool => trim($value) !== ''));

                if ($this->fuzzyTermsScore($terms, $searchText) < self::FUZZY_SCORE_THRESHOLD) {
                    return null;
                }

                $name = trim((string) $vehicle->name);
                $registration = trim((string) $vehicle->registration);
                $label = $name !== '' ? $name : ($registration !== '' ? $registration : ('Véhicule #'.$vehicle->id));
                $description = $registration !== '' ? 'Véhicule / '.$registration : 'Véhicule';

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'vehicle',
                        [
                            ['value' => (string) $vehicle->name, 'weight' => 24],
                            ['value' => (string) $vehicle->registration, 'weight' => 20],
                        ],
                        true
                    ),
                    'type' => 'vehicle',
                    'label' => $label,
                    'description' => $description,
                    'url' => route('task.data.index', ['section' => 'camions']),
                ];
            })
            ->filter()
            ->reject(fn (array $item): bool => isset($exactKeys[$this->resultKey($item)]))
            ->values()
            ->all();

        return $this->mergeCategoryResults($exact, $fuzzy);
    }

    private function searchTransporters(array $terms, string $normalizedQuery): array
    {
        $exact = Transporter::query()
            ->select(['id', 'first_name', 'last_name', 'company_name'])
            ->where(fn ($builder) => $this->applyTermsFilter($builder, $terms, ['first_name', 'last_name', 'company_name']))
            ->orderByRaw("COALESCE(NULLIF(last_name, ''), NULLIF(company_name, ''), '') asc")
            ->orderByRaw("COALESCE(NULLIF(first_name, ''), '') asc")
            ->limit(self::LIMIT_PER_CATEGORY)
            ->get()
            ->map(function (Transporter $transporter) use ($terms): array {
                $firstName = trim((string) $transporter->first_name);
                $lastName = trim((string) $transporter->last_name);
                $company = trim((string) $transporter->company_name);
                $fullName = trim($firstName.' '.$lastName);
                $label = $fullName !== '' ? $fullName : ($company !== '' ? $company : ('Transporteur #'.$transporter->id));
                $description = $company !== '' ? 'Transporteur / '.$company : 'Transporteur';

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'transporter',
                        [
                            ['value' => (string) $transporter->first_name, 'weight' => 30],
                            ['value' => (string) $transporter->last_name, 'weight' => 30],
                            ['value' => (string) $transporter->company_name, 'weight' => 24],
                        ],
                        false
                    ),
                    'type' => 'transporter',
                    'label' => $label,
                    'description' => $description,
                    'url' => route('task.data.index', ['section' => 'transporters']),
                ];
            })
            ->values()
            ->all();

        if (! $this->shouldApplyFuzzy($normalizedQuery, count($exact))) {
            return $exact;
        }

        $exactKeys = array_fill_keys(array_map(fn (array $item): string => $this->resultKey($item), $exact), true);

        $fuzzy = Transporter::query()
            ->select(['id', 'first_name', 'last_name', 'company_name'])
            ->orderByRaw("COALESCE(NULLIF(last_name, ''), NULLIF(company_name, ''), '') asc")
            ->orderByRaw("COALESCE(NULLIF(first_name, ''), '') asc")
            ->limit(self::FUZZY_POOL_LIMIT)
            ->get()
            ->map(function (Transporter $transporter) use ($terms): ?array {
                $searchText = implode(' ', array_filter([
                    (string) $transporter->first_name,
                    (string) $transporter->last_name,
                    (string) $transporter->company_name,
                ], static fn (string $value): bool => trim($value) !== ''));

                if ($this->fuzzyTermsScore($terms, $searchText) < self::FUZZY_SCORE_THRESHOLD) {
                    return null;
                }

                $firstName = trim((string) $transporter->first_name);
                $lastName = trim((string) $transporter->last_name);
                $company = trim((string) $transporter->company_name);
                $fullName = trim($firstName.' '.$lastName);
                $label = $fullName !== '' ? $fullName : ($company !== '' ? $company : ('Transporteur #'.$transporter->id));
                $description = $company !== '' ? 'Transporteur / '.$company : 'Transporteur';

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'transporter',
                        [
                            ['value' => (string) $transporter->first_name, 'weight' => 30],
                            ['value' => (string) $transporter->last_name, 'weight' => 30],
                            ['value' => (string) $transporter->company_name, 'weight' => 24],
                        ],
                        true
                    ),
                    'type' => 'transporter',
                    'label' => $label,
                    'description' => $description,
                    'url' => route('task.data.index', ['section' => 'transporters']),
                ];
            })
            ->filter()
            ->reject(fn (array $item): bool => isset($exactKeys[$this->resultKey($item)]))
            ->values()
            ->all();

        return $this->mergeCategoryResults($exact, $fuzzy);
    }

    private function searchDepots(array $terms, string $normalizedQuery): array
    {
        $exact = Depot::query()
            ->select(['id', 'name'])
            ->where(fn ($builder) => $this->applyTermsFilter($builder, $terms, ['name']))
            ->orderBy('name')
            ->limit(self::LIMIT_PER_CATEGORY)
            ->get()
            ->map(function (Depot $depot) use ($terms): array {
                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'depot',
                        [
                            ['value' => (string) $depot->name, 'weight' => 24],
                        ],
                        false
                    ),
                    'type' => 'depot',
                    'label' => trim((string) $depot->name) !== '' ? trim((string) $depot->name) : ('Dépôt #'.$depot->id),
                    'description' => 'Dépôt',
                    'url' => route('task.data.index', ['section' => 'depots']),
                ];
            })
            ->values()
            ->all();

        if (! $this->shouldApplyFuzzy($normalizedQuery, count($exact))) {
            return $exact;
        }

        $exactKeys = array_fill_keys(array_map(fn (array $item): string => $this->resultKey($item), $exact), true);

        $fuzzy = Depot::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->limit(self::FUZZY_POOL_LIMIT)
            ->get()
            ->map(function (Depot $depot) use ($terms): ?array {
                if ($this->fuzzyTermsScore($terms, (string) $depot->name) < self::FUZZY_SCORE_THRESHOLD) {
                    return null;
                }

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'depot',
                        [
                            ['value' => (string) $depot->name, 'weight' => 24],
                        ],
                        true
                    ),
                    'type' => 'depot',
                    'label' => trim((string) $depot->name) !== '' ? trim((string) $depot->name) : ('Dépôt #'.$depot->id),
                    'description' => 'Dépôt',
                    'url' => route('task.data.index', ['section' => 'depots']),
                ];
            })
            ->filter()
            ->reject(fn (array $item): bool => isset($exactKeys[$this->resultKey($item)]))
            ->values()
            ->all();

        return $this->mergeCategoryResults($exact, $fuzzy);
    }

    private function searchAprevoirTasks(array $terms, string $normalizedQuery): array
    {
        $exact = AprevoirTask::query()
            ->select(['id', 'task', 'comment'])
            ->where(fn ($builder) => $this->applyTermsFilter($builder, $terms, ['task', 'comment']))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_CATEGORY)
            ->get()
            ->map(function (AprevoirTask $task) use ($terms): array {
                $taskLabel = trim((string) $task->task);
                $comment = trim((string) $task->comment);
                $label = $taskLabel !== '' ? $taskLabel : ('Tâche #'.$task->id);
                $description = $comment !== '' ? 'À prévoir / '.$this->truncate($comment, 100) : 'À prévoir';

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'task',
                        [
                            ['value' => (string) $task->task, 'weight' => 18],
                            ['value' => (string) $task->comment, 'weight' => 6],
                        ],
                        false
                    ),
                    'type' => 'task',
                    'label' => $label,
                    'description' => $description,
                    'url' => route('a_prevoir.index', ['focus_task_id' => $task->id]),
                ];
            })
            ->values()
            ->all();

        if (! $this->shouldApplyFuzzy($normalizedQuery, count($exact))) {
            return $exact;
        }

        $exactKeys = array_fill_keys(array_map(fn (array $item): string => $this->resultKey($item), $exact), true);

        $fuzzy = AprevoirTask::query()
            ->select(['id', 'task', 'comment', 'date'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::FUZZY_POOL_LIMIT)
            ->get()
            ->map(function (AprevoirTask $task) use ($terms): ?array {
                $searchText = implode(' ', array_filter([
                    (string) $task->task,
                    (string) $task->comment,
                ], static fn (string $value): bool => trim($value) !== ''));

                if ($this->fuzzyTermsScore($terms, $searchText) < self::FUZZY_SCORE_THRESHOLD) {
                    return null;
                }

                $taskLabel = trim((string) $task->task);
                $comment = trim((string) $task->comment);
                $label = $taskLabel !== '' ? $taskLabel : ('Tâche #'.$task->id);
                $description = $comment !== '' ? 'À prévoir / '.$this->truncate($comment, 100) : 'À prévoir';

                return [
                    'score' => $this->computeRankingScore(
                        $terms,
                        'task',
                        [
                            ['value' => (string) $task->task, 'weight' => 18],
                            ['value' => (string) $task->comment, 'weight' => 6],
                        ],
                        true
                    ),
                    'type' => 'task',
                    'label' => $label,
                    'description' => $description,
                    'url' => route('a_prevoir.index', ['focus_task_id' => $task->id]),
                ];
            })
            ->filter()
            ->reject(fn (array $item): bool => isset($exactKeys[$this->resultKey($item)]))
            ->values()
            ->all();

        return $this->mergeCategoryResults($exact, $fuzzy);
    }

    private function canAccessTaskData(User $user): bool
    {
        return $this->accessManager->can($user, 'task.data.view');
    }

    private function canAccessAprevoir(User $user): bool
    {
        return $this->accessManager->can($user, 'a_prevoir.view');
    }

    private function likePattern(string $query): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
    }

    private function extractTerms(string $query): array
    {
        $normalized = $this->normalizeText($query);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $normalized) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function applyTermsFilter($builder, array $terms, array $columns): void
    {
        foreach ($terms as $term) {
            $normalizedTerm = $this->normalizeText($term);
            if ($normalizedTerm === '') {
                continue;
            }

            $pattern = $this->likePattern($normalizedTerm);

            $builder->where(function ($termBuilder) use ($columns, $pattern): void {
                foreach ($columns as $index => $column) {
                    if ($index === 0) {
                        $termBuilder->whereRaw($this->normalizedTextExpression($column).' LIKE ?', [$pattern]);
                        continue;
                    }
                    $termBuilder->orWhereRaw($this->normalizedTextExpression($column).' LIKE ?', [$pattern]);
                }
            });
        }
    }

    private function applyUsersTermsFilter($builder, array $terms): void
    {
        $textColumns = ['name', 'first_name', 'last_name', 'email'];
        $phoneColumns = ['phone', 'mobile_phone', 'internal_number', 'directory_phones'];

        foreach ($terms as $term) {
            $normalizedTerm = $this->normalizeText($term);
            if ($normalizedTerm === '') {
                continue;
            }

            $textPattern = $this->likePattern($normalizedTerm);
            $normalizedPhoneTerm = $this->normalizePhoneTerm($term);

            $builder->where(function ($termBuilder) use ($textColumns, $textPattern, $phoneColumns, $normalizedPhoneTerm): void {
                foreach ($textColumns as $index => $column) {
                    if ($index === 0) {
                        $termBuilder->whereRaw($this->normalizedTextExpression($column).' LIKE ?', [$textPattern]);
                        continue;
                    }
                    $termBuilder->orWhereRaw($this->normalizedTextExpression($column).' LIKE ?', [$textPattern]);
                }

                if ($normalizedPhoneTerm === '') {
                    return;
                }

                $phonePattern = $this->likePattern($normalizedPhoneTerm);
                foreach ($phoneColumns as $column) {
                    $termBuilder->orWhereRaw($this->normalizedPhoneExpression($column).' LIKE ?', [$phonePattern]);
                }
            });
        }
    }

    private function normalizePhoneTerm(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    private function normalizedPhoneExpression(string $column): string
    {
        $expression = "LOWER(COALESCE($column, ''))";
        $charactersToStrip = [' ', '.', '-', '(', ')', '/', '+'];
        foreach ($charactersToStrip as $character) {
            $expression = "REPLACE($expression, '$character', '')";
        }

        return $expression;
    }

    private function normalizeText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';

        return strtr($normalized, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c',
            'ď' => 'd', 'đ' => 'd',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
            'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
            'ĥ' => 'h',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
            'ĵ' => 'j',
            'ķ' => 'k',
            'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
            'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
            'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
            'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's',
            'ß' => 'ss',
            'ť' => 't', 'ţ' => 't', 'ŧ' => 't',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
            'ẃ' => 'w',
            'ẍ' => 'x',
            'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
            'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
            'œ' => 'oe',
            'æ' => 'ae',
        ]);
    }

    private function normalizedTextExpression(string $column): string
    {
        $expression = "LOWER(COALESCE($column, ''))";
        $replacements = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c',
            'ď' => 'd', 'đ' => 'd',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
            'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
            'ĥ' => 'h',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
            'ĵ' => 'j',
            'ķ' => 'k',
            'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
            'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
            'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
            'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's',
            'ß' => 'ss',
            'ť' => 't', 'ţ' => 't', 'ŧ' => 't',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
            'ẃ' => 'w',
            'ẍ' => 'x',
            'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
            'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
            'œ' => 'oe',
            'æ' => 'ae',
        ];

        foreach ($replacements as $from => $to) {
            $expression = "REPLACE($expression, '$from', '$to')";
        }

        return "TRIM($expression)";
    }

    private function shouldApplyFuzzy(string $normalizedQuery, int $exactCount): bool
    {
        return mb_strlen($normalizedQuery) >= self::MIN_FUZZY_QUERY_LENGTH
            && $exactCount <= self::FUZZY_TRIGGER_MAX_EXACT_RESULTS;
    }

    private function fuzzyTermsScore(array $terms, string $candidate): float
    {
        $normalizedCandidate = $this->normalizeText($candidate);
        if ($normalizedCandidate === '' || $terms === []) {
            return 0.0;
        }

        $candidateTokens = preg_split('/[^a-z0-9]+/u', $normalizedCandidate) ?: [];
        $candidateTokens = array_values(array_filter($candidateTokens, static fn (string $token): bool => $token !== ''));
        if ($candidateTokens === []) {
            $candidateTokens = [$normalizedCandidate];
        }

        $scores = [];
        foreach ($terms as $term) {
            $normalizedTerm = $this->normalizeText($term);
            if ($normalizedTerm === '') {
                continue;
            }

            $best = $this->fuzzySimilarity($normalizedTerm, $normalizedCandidate);
            foreach ($candidateTokens as $token) {
                $tokenScore = $this->fuzzySimilarity($normalizedTerm, $token);
                if ($tokenScore > $best) {
                    $best = $tokenScore;
                }
            }

            $scores[] = $best;
        }

        return $scores === [] ? 0.0 : array_sum($scores) / count($scores);
    }

    private function fuzzySimilarity(string $needle, string $haystack): float
    {
        if ($needle === '' || $haystack === '') {
            return 0.0;
        }
        if ($needle === $haystack) {
            return 1.0;
        }
        if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
            return 0.92;
        }

        $distance = levenshtein($needle, $haystack);
        $maxLength = max(strlen($needle), strlen($haystack));
        $levenshteinScore = $maxLength > 0 ? max(0.0, 1 - ($distance / $maxLength)) : 0.0;

        similar_text($needle, $haystack, $percent);
        $similarTextScore = max(0.0, min(1.0, $percent / 100));

        return max($levenshteinScore, $similarTextScore);
    }

    private function mergeCategoryResults(array $exact, array $fuzzy): array
    {
        $merged = [];
        $seen = [];

        foreach ([$exact, $fuzzy] as $source) {
            foreach ($source as $item) {
                $key = $this->resultKey($item);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $merged[] = $item;
            }
        }

        usort($merged, function (array $a, array $b): int {
            $scoreA = (int) ($a['score'] ?? 0);
            $scoreB = (int) ($b['score'] ?? 0);
            if ($scoreA === $scoreB) {
                return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            }

            return $scoreB <=> $scoreA;
        });

        return array_slice($merged, 0, self::LIMIT_PER_CATEGORY);
    }

    private function resultKey(array $item): string
    {
        return implode('|', [
            (string) ($item['type'] ?? ''),
            (string) ($item['label'] ?? ''),
            (string) ($item['description'] ?? ''),
            (string) ($item['url'] ?? ''),
        ]);
    }

    private function computeRankingScore(array $terms, string $type, array $fields, bool $isFuzzy): int
    {
        $score = $this->typeWeight($type);
        if (! $isFuzzy) {
            $score += self::EXACT_SOURCE_BONUS;
        }

        foreach ($terms as $term) {
            $normalizedTerm = $this->normalizeText($term);
            if ($normalizedTerm === '') {
                continue;
            }

            $bestTermScore = 0;
            foreach ($fields as $field) {
                $fieldValue = (string) ($field['value'] ?? '');
                $fieldWeight = (int) ($field['weight'] ?? 0);
                $isPhoneField = (bool) ($field['phone'] ?? false);

                $baseScore = $this->fieldMatchBaseScore($normalizedTerm, $fieldValue, $isPhoneField, $isFuzzy);
                if ($baseScore <= 0) {
                    continue;
                }

                $candidateScore = $baseScore + $fieldWeight;
                if ($candidateScore > $bestTermScore) {
                    $bestTermScore = $candidateScore;
                }
            }

            $score += $bestTermScore;
        }

        return $score;
    }

    private function fieldMatchBaseScore(string $normalizedTerm, string $rawValue, bool $isPhoneField, bool $allowFuzzy): int
    {
        $normalizedValue = $isPhoneField
            ? $this->normalizePhoneTerm($rawValue)
            : $this->normalizeText($rawValue);

        if ($normalizedTerm === '' || $normalizedValue === '') {
            return 0;
        }

        if ($isPhoneField) {
            $needle = $this->normalizePhoneTerm($normalizedTerm);
            if ($needle === '') {
                return 0;
            }

            if ($normalizedValue === $needle) {
                return self::MATCH_EXACT_POINTS;
            }
            if (str_starts_with($normalizedValue, $needle)) {
                return self::MATCH_PREFIX_POINTS;
            }
            if (str_contains($normalizedValue, $needle)) {
                return self::MATCH_CONTAINS_POINTS;
            }
            if ($allowFuzzy && $this->fuzzySimilarity($needle, $normalizedValue) >= self::FUZZY_SCORE_THRESHOLD) {
                return self::MATCH_FUZZY_POINTS;
            }

            return 0;
        }

        if ($normalizedValue === $normalizedTerm) {
            return self::MATCH_EXACT_POINTS;
        }
        if ($this->startsWithTerm($normalizedValue, $normalizedTerm)) {
            return self::MATCH_PREFIX_POINTS;
        }
        if (str_contains($normalizedValue, $normalizedTerm)) {
            return self::MATCH_CONTAINS_POINTS;
        }
        if ($allowFuzzy && $this->bestFuzzySimilarity($normalizedTerm, $normalizedValue) >= self::FUZZY_SCORE_THRESHOLD) {
            return self::MATCH_FUZZY_POINTS;
        }

        return 0;
    }

    private function startsWithTerm(string $normalizedValue, string $normalizedTerm): bool
    {
        if (str_starts_with($normalizedValue, $normalizedTerm)) {
            return true;
        }

        $tokens = preg_split('/[^a-z0-9]+/u', $normalizedValue) ?: [];
        foreach ($tokens as $token) {
            if ($token !== '' && str_starts_with($token, $normalizedTerm)) {
                return true;
            }
        }

        return false;
    }

    private function bestFuzzySimilarity(string $normalizedTerm, string $normalizedValue): float
    {
        $best = $this->fuzzySimilarity($normalizedTerm, $normalizedValue);
        $tokens = preg_split('/[^a-z0-9]+/u', $normalizedValue) ?: [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $tokenScore = $this->fuzzySimilarity($normalizedTerm, $token);
            if ($tokenScore > $best) {
                $best = $tokenScore;
            }
        }

        return $best;
    }

    private function typeWeight(string $type): int
    {
        return match ($type) {
            'user' => 30,
            'transporter' => 25,
            'vehicle' => 20,
            'depot' => 15,
            'task' => 10,
            default => 0,
        };
    }

    private function truncate(string $value, int $maxLength): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (mb_strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $maxLength - 1)).'…';
    }
}

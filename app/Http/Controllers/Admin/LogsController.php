<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LogsController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100, 150, 200])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = AuditLog::query()->with('user:id,name,first_name,last_name,email');

        if (! empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }

        if (! empty($validated['module'])) {
            $query->where('module', (string) $validated['module']);
        }

        if (! empty($validated['action'])) {
            $query->where('action', (string) $validated['action']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $validated['date_to']);
        }

        $logs = $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (AuditLog $log): array {
                $payload = is_array($log->payload) ? $log->payload : null;
                $before = is_array($payload['before'] ?? null) ? $payload['before'] : null;
                $after = is_array($payload['after'] ?? null) ? $payload['after'] : null;
                $changes = $this->extractChanges($before, $after);
                $taskId = $this->resolveTaskId($payload);

                return [
                    'id' => $log->id,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'created_at_label' => $log->created_at?->format('d/m/Y H:i:s'),
                    'user_id' => $log->user_id,
                    'user_name' => $log->user_name,
                    'action' => $log->action,
                    'module' => $log->module,
                    'description' => $log->description,
                    'description_display' => $this->buildReadableDescription($log, $payload, $changes),
                    'route' => $log->route,
                    'method' => $log->method,
                    'url' => $log->url,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'payload' => $payload,
                    'changes' => $changes,
                    'task_id' => $taskId,
                    'task_href' => $taskId ? route('a_prevoir.index', ['focus_task_id' => $taskId]) : null,
                ];
            });

        $userIds = AuditLog::query()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'last_name', 'email'])
            ->map(function (User $user): array {
                $fullName = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));

                return [
                    'id' => $user->id,
                    'name' => $fullName !== '' ? $fullName : ($user->name ?: $user->email),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Admin/Logs/Index', [
            'logs' => $logs,
            'filters' => [
                'user_id' => ! empty($validated['user_id']) ? (int) $validated['user_id'] : null,
                'module' => $validated['module'] ?? '',
                'action' => $validated['action'] ?? '',
                'date_from' => $validated['date_from'] ?? '',
                'date_to' => $validated['date_to'] ?? '',
                'per_page' => $perPage,
            ],
            'options' => [
                'users' => $users,
                'modules' => AuditLog::query()->select('module')->distinct()->orderBy('module')->pluck('module')->all(),
                'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action')->all(),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @param  array<int,array{field:string,label:string,before:string,after:string}>  $changes
     */
    private function buildReadableDescription(AuditLog $log, ?array $payload, array $changes): string
    {
        $segments = [];
        $base = trim((string) ($log->description ?? ''));

        if ($base !== '') {
            $segments[] = $base;
        } else {
            $segments[] = $this->actionLabel((string) $log->action);
        }

        if ($log->action === 'point_task' && is_array($payload)) {
            if (array_key_exists('before_pointed', $payload) && array_key_exists('after_pointed', $payload)) {
                $segments[] = sprintf(
                    'Etat: %s → %s',
                    ((bool) $payload['before_pointed']) ? 'pointé' : 'non pointé',
                    ((bool) $payload['after_pointed']) ? 'pointé' : 'non pointé',
                );
            } elseif (array_key_exists('pointed', $payload)) {
                $segments[] = (bool) $payload['pointed'] ? 'Etat: pointé' : 'Etat: non pointé';
            }
        }

        if ($changes !== []) {
            $segments[] = 'Champs modifiés: '.implode(', ', array_map(
                static fn (array $change): string => $change['label'],
                $changes
            ));

            $inlineChanges = array_slice(array_map(
                static fn (array $change): string => sprintf(
                    '%s: %s → %s',
                    $change['label'],
                    $change['before'],
                    $change['after']
                ),
                $changes
            ), 0, 2);

            if ($inlineChanges !== []) {
                $segments[] = implode(' ; ', $inlineChanges);
            }
        }

        return implode(' | ', $segments);
    }

    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     * @return array<int,array{field:string,label:string,before:string,after:string}>
     */
    private function extractChanges(?array $before, ?array $after): array
    {
        if (! is_array($before) || ! is_array($after)) {
            return [];
        }

        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        $ignored = [
            'created_at',
            'updated_at',
            'pointed_at',
            'updated_from_source_at',
            'sms_sent_at',
        ];

        $changes = [];

        foreach ($keys as $key) {
            if (! is_string($key) || in_array($key, $ignored, true)) {
                continue;
            }

            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if ($this->valuesEqual($beforeValue, $afterValue)) {
                continue;
            }

            // Keep the summary readable: only scalar changes are listed.
            if (! $this->isScalarLike($beforeValue) || ! $this->isScalarLike($afterValue)) {
                continue;
            }

            $changes[] = [
                'field' => $key,
                'label' => $this->fieldLabel($key),
                'before' => $this->formatScalar($beforeValue),
                'after' => $this->formatScalar($afterValue),
            ];
        }

        return $changes;
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            === json_encode($right, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param  array<string,mixed>|null  $payload
     */
    private function resolveTaskId(?array $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        $direct = (int) ($payload['task_id'] ?? 0);
        if ($direct > 0) {
            return $direct;
        }

        $beforeId = (int) ($payload['before']['id'] ?? 0);
        if ($beforeId > 0) {
            return $beforeId;
        }

        $afterId = (int) ($payload['after']['id'] ?? 0);
        if ($afterId > 0) {
            return $afterId;
        }

        return null;
    }

    private function isScalarLike(mixed $value): bool
    {
        return is_scalar($value) || $value === null;
    }

    private function formatScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }

        if ($value === null) {
            return 'Vide';
        }

        $text = trim((string) $value);

        if ($text === '') {
            return 'Vide';
        }

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'...' : $text;
    }

    private function fieldLabel(string $field): string
    {
        $map = [
            'task' => 'Tâche',
            'comment' => 'Commentaire',
            'date' => 'Date',
            'fin_date' => 'Date de fin',
            'pointed' => 'Pointé',
            'assignee_type' => 'Type affectation',
            'assignee_id' => 'Affectation',
            'vehicle_id' => 'Camion',
            'remorque_id' => 'Remorque',
            'name' => 'Nom',
            'email' => 'Email',
            'role' => 'Rôle',
            'sector_id' => 'Secteur',
            'scope' => 'Périmètre',
            'match_type' => 'Type de match',
            'pattern' => 'Pattern',
            'text_color' => 'Couleur texte',
            'bg_color' => 'Couleur fond',
            'priority' => 'Ordre',
            'is_active' => 'Actif',
            'applies_to_a_prevoir' => 'Cible À Prévoir',
            'applies_to_ldt' => 'Cible LDT',
            'description' => 'Description',
        ];

        if (isset($map[$field])) {
            return $map[$field];
        }

        return ucfirst(str_replace('_', ' ', $field));
    }

    private function actionLabel(string $action): string
    {
        $map = [
            'login' => 'Connexion réussie',
            'login_failed' => 'Échec de connexion',
            'logout' => 'Déconnexion',
            'reset_password' => 'Réinitialisation du mot de passe',
            'view_page' => 'Consultation de page',
            'create_task' => 'Création de tâche',
            'update_task' => 'Mise à jour de tâche',
            'delete_task' => 'Suppression de tâche',
            'point_task' => 'Pointage de tâche',
            'reorder_task' => 'Réordonnancement de tâches',
            'generate_ldt' => 'Génération de projection LDT',
            'export_ldt' => 'Export LDT',
            'create_rule' => 'Création de règle',
            'update_rule' => 'Mise à jour de règle',
            'delete_rule' => 'Suppression de règle',
            'update_user' => 'Mise à jour utilisateur',
            'update_sector' => 'Mise à jour secteur',
            'permission_change' => 'Changement de permissions',
            'error' => 'Erreur système',
        ];

        return $map[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }
}

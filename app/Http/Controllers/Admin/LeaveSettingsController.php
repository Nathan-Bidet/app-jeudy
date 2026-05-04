<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveAllowedCreator;
use App\Models\LeaveAllowedCreatorPair;
use App\Models\LeaveHrUser;
use App\Models\LeaveSectorValidator;
use App\Models\LeaveUserValidator;
use App\Models\LeaveType;
use App\Models\LeaveTypeUserVisibility;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaveSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $users = User::query()
            ->with('sector:id,name')
            ->orderByRaw('COALESCE(last_name, name) asc')
            ->orderByRaw('COALESCE(first_name, name) asc')
            ->get(['id', 'name', 'first_name', 'last_name', 'email'])
            ->map(function (User $user): array {
                $fullName = trim(
                    collect([$user->first_name, $user->last_name])
                        ->filter()
                        ->implode(' ')
                );

                return [
                    'id' => (int) $user->id,
                    'label' => $fullName !== '' ? $fullName : ($user->name ?: $user->email),
                    'sector_labels' => collect([$user->sector?->name])
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $validatorsByUser = LeaveUserValidator::query()
            ->pluck('validator_user_id', 'target_user_id')
            ->map(fn ($validatorId) => (int) $validatorId)
            ->all();

        $hrUserIds = LeaveHrUser::query()
            ->pluck('user_id')
            ->map(fn ($userId) => (int) $userId)
            ->values()
            ->all();

        $allowedCreatorPairs = LeaveAllowedCreatorPair::query()
            ->get(['creator_user_id', 'target_user_id']);
        $hasAllowedCreatorPairConfig = $allowedCreatorPairs->isNotEmpty();
        $allowedCreatorTargetsByCreator = $allowedCreatorPairs
            ->groupBy('creator_user_id')
            ->map(fn ($rows) => $rows
                ->pluck('target_user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all()
            )
            ->toArray();

        $leaveTypes = LeaveType::query()
            ->with('userVisibilities:user_id,leave_type_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'max_days', 'sort_order', 'is_active'])
            ->map(fn (LeaveType $leaveType): array => [
                'id' => (int) $leaveType->id,
                'name' => $leaveType->name,
                'max_days' => $leaveType->max_days !== null ? (int) $leaveType->max_days : null,
                'sort_order' => (int) $leaveType->sort_order,
                'is_active' => (bool) $leaveType->is_active,
                'visibility_mode' => $leaveType->userVisibilities->isEmpty() ? 'all' : 'selected',
                'visible_user_ids' => $leaveType->userVisibilities
                    ->pluck('user_id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Leaves/Index', [
            'users' => $users,
            'validatorsByUser' => $validatorsByUser,
            'hrUserIds' => $hrUserIds,
            'allowedCreatorTargetsByCreator' => $allowedCreatorTargetsByCreator,
            'hasAllowedCreatorPairConfig' => $hasAllowedCreatorPairConfig,
            'leaveTypes' => $leaveTypes,
        ]);
    }

    public function updateUserValidators(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'validators' => ['required', 'array'],
            'validators.*.target_user_id' => ['required', 'integer', 'exists:users,id'],
            'validators.*.validator_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        foreach ($validated['validators'] as $row) {
            $targetUserId = (int) $row['target_user_id'];
            $validatorUserId = $row['validator_user_id'] ?? null;

            if ($validatorUserId === null || $validatorUserId === '') {
                LeaveUserValidator::query()
                    ->where('target_user_id', $targetUserId)
                    ->delete();

                continue;
            }

            LeaveUserValidator::query()->updateOrCreate(
                ['target_user_id' => $targetUserId],
                ['validator_user_id' => (int) $validatorUserId],
            );
        }

        return back()->with('success', 'Valideurs par utilisateur enregistrés.');
    }

    public function updateValidators(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'validators' => ['required', 'array'],
            'validators.*.sector_id' => ['required', 'integer', 'exists:sectors,id'],
            'validators.*.validator_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        foreach ($validated['validators'] as $row) {
            $sectorId = (int) $row['sector_id'];
            $validatorUserId = $row['validator_user_id'] ?? null;

            if ($validatorUserId === null || $validatorUserId === '') {
                LeaveSectorValidator::query()
                    ->where('sector_id', $sectorId)
                    ->delete();

                continue;
            }

            LeaveSectorValidator::query()->updateOrCreate(
                ['sector_id' => $sectorId],
                ['validator_user_id' => (int) $validatorUserId],
            );
        }

        return back()->with('success', 'Valideurs par secteur enregistrés.');
    }

    public function updateHr(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'hr_user_ids' => ['required', 'array'],
            'hr_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $hrUserIds = collect($validated['hr_user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        LeaveHrUser::query()
            ->whereNotIn('user_id', $hrUserIds)
            ->delete();

        foreach ($hrUserIds as $userId) {
            LeaveHrUser::query()->firstOrCreate(['user_id' => $userId]);
        }

        return back()->with('success', 'Référents RH enregistrés.');
    }

    public function updateAllowedCreators(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'allowed_creator_user_ids' => ['required', 'array'],
            'allowed_creator_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $allowedIds = collect($validated['allowed_creator_user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($allowedIds === []) {
            LeaveAllowedCreator::query()->delete();

            return back()->with('success', 'Utilisateurs autorisés enregistrés.');
        }

        LeaveAllowedCreator::query()
            ->whereNotIn('user_id', $allowedIds)
            ->delete();

        foreach ($allowedIds as $userId) {
            LeaveAllowedCreator::query()->firstOrCreate(['user_id' => $userId]);
        }

        return back()->with('success', 'Utilisateurs autorisés enregistrés.');
    }

    public function updateAllowedCreatorPairs(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'creator_permissions' => ['required', 'array'],
            'creator_permissions.*.creator_user_id' => ['required', 'integer', 'exists:users,id'],
            'creator_permissions.*.is_enabled' => ['required', 'boolean'],
            'creator_permissions.*.target_user_ids' => ['nullable', 'array'],
            'creator_permissions.*.target_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $pairs = collect($validated['creator_permissions'] ?? [])
            ->flatMap(function (array $row): array {
                $creatorId = (int) $row['creator_user_id'];
                $isEnabled = (bool) ($row['is_enabled'] ?? false);

                if (! $isEnabled) {
                    return [];
                }

                $targetIds = collect($row['target_user_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id !== $creatorId)
                    ->values()
                    ->all();

                return array_map(
                    static fn (int $targetId): array => [
                        'creator_user_id' => $creatorId,
                        'target_user_id' => $targetId,
                    ],
                    $targetIds,
                );
            })
            ->unique(fn (array $pair): string => $pair['creator_user_id'].'-'.$pair['target_user_id'])
            ->values()
            ->all();

        DB::transaction(function () use ($pairs): void {
            LeaveAllowedCreatorPair::query()->delete();

            if ($pairs !== []) {
                LeaveAllowedCreatorPair::query()->insert(
                    array_map(static function (array $pair): array {
                        return [
                            ...$pair,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }, $pairs)
                );
            }
        });

        return back()->with('success', 'Autorisations de création pour autrui enregistrées.');
    }

    public function storeType(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'max_days' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['required', 'integer'],
            'is_unlimited' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'visibility_mode' => ['required', 'string', 'in:all,selected'],
            'visible_user_ids' => ['nullable', 'array'],
            'visible_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $isUnlimited = (bool) ($validated['is_unlimited'] ?? false);
        $maxDays = $validated['max_days'] ?? null;
        if (! $isUnlimited && $maxDays === null) {
            return back()
                ->withErrors(['max_days' => 'La durée max est requise si "Sans limite" n\'est pas coché.'])
                ->withInput();
        }

        $leaveType = LeaveType::query()->create([
            'name' => trim((string) $validated['name']),
            'max_days' => $isUnlimited ? null : (int) $maxDays,
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
        ]);

        $visibleUserIds = collect($validated['visible_user_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (($validated['visibility_mode'] ?? 'all') === 'selected' && $visibleUserIds !== []) {
            LeaveTypeUserVisibility::query()->insert(
                array_map(
                    static fn (int $userId): array => [
                        'leave_type_id' => (int) $leaveType->id,
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    $visibleUserIds
                )
            );
        }

        return back()->with('success', 'Type de congé ajouté.');
    }

    public function updateType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'max_days' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['required', 'integer'],
            'is_unlimited' => ['nullable', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'visibility_mode' => ['required', 'string', 'in:all,selected'],
            'visible_user_ids' => ['nullable', 'array'],
            'visible_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $isUnlimited = (bool) ($validated['is_unlimited'] ?? false);
        $maxDays = $validated['max_days'] ?? null;
        if (! $isUnlimited && $maxDays === null) {
            return back()
                ->withErrors(['max_days' => 'La durée max est requise si "Sans limite" n\'est pas coché.'])
                ->withInput();
        }

        $leaveType->update([
            'name' => trim((string) $validated['name']),
            'max_days' => $isUnlimited ? null : (int) $maxDays,
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => (bool) $validated['is_active'],
        ]);

        $visibleUserIds = collect($validated['visible_user_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        LeaveTypeUserVisibility::query()
            ->where('leave_type_id', (int) $leaveType->id)
            ->delete();

        if (($validated['visibility_mode'] ?? 'all') === 'selected' && $visibleUserIds !== []) {
            LeaveTypeUserVisibility::query()->insert(
                array_map(
                    static fn (int $userId): array => [
                        'leave_type_id' => (int) $leaveType->id,
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    $visibleUserIds
                )
            );
        }

        return back()->with('success', 'Type de congé mis à jour.');
    }
}

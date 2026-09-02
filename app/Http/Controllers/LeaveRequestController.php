<?php

namespace App\Http\Controllers;

use App\Models\LeaveAllowedCreatorPair;
use App\Models\LeaveRequest;
use App\Models\LeaveSectorValidator;
use App\Models\LeaveType;
use App\Models\LeaveUserValidator;
use App\Models\User;
use App\Notifications\LeaveRequestApprovedNotification;
use App\Notifications\LeaveRequestFirstLevelApprovedNotification;
use App\Notifications\LeaveRequestModificationAcceptedNotification;
use App\Notifications\LeaveRequestModificationProposedNotification;
use App\Notifications\LeaveRequestModificationRefusedNotification;
use App\Notifications\LeaveRequestRefusedNotification;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Jobs\SendWebPushNotificationJob;
use App\Services\AuditLogService;
use App\Services\Validation\TwoStepValidationService;
use App\Services\Validation\ValidationGroupService;
use App\Support\Validation\ValidationStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly ValidationGroupService $validationGroups,
        private readonly TwoStepValidationService $twoStepValidation,
    ) {
    }

    /**
     * Valideur d'avant les groupes, pour un utilisateur qui n'en a pas encore.
     *
     * La cascade est celle qui existait : réglage par utilisateur, puis par
     * secteur, puis un administrateur en dernier recours. Elle n'est consultée
     * que si le groupe ne fournit pas de Valideur 1 — les utilisateurs déjà
     * rattachés à un groupe ne la voient jamais.
     */
    private function legacyLeaveValidatorFor(User $targetUser): ?User
    {
        $validatorUserId = LeaveUserValidator::query()
            ->where('target_user_id', (int) $targetUser->id)
            ->value('validator_user_id');

        if (! $validatorUserId && $targetUser->sector_id) {
            $validatorUserId = LeaveSectorValidator::query()
                ->where('sector_id', (int) $targetUser->sector_id)
                ->value('validator_user_id');
        }

        $validator = $validatorUserId ? User::query()->find($validatorUserId) : null;

        if ($validator && ! $validator->is_active) {
            $validator = null;
        }

        if ($validator) {
            return $validator;
        }

        return $this->firstActiveAdmin();
    }

    /**
     * Premier administrateur actif, ou null s'il n'y en a pas.
     *
     * La portée `role()` de Spatie lève une exception quand le rôle n'existe
     * pas encore : ce dernier recours ne doit jamais faire échouer la
     * soumission d'une demande, il passe donc par une jointure ordinaire.
     */
    private function firstActiveAdmin(): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
            ->orderByRaw('COALESCE(last_name, name) asc')
            ->orderByRaw('COALESCE(first_name, name) asc')
            ->first();
    }

    /**
     * Trace une demande partie sans groupe de validation.
     *
     * La demande n'est pas bloquée : elle suit la cascade historique et reste
     * traitable, au pire par un administrateur. Mais elle ne bénéficie pas de
     * la double validation, et l'administration doit pouvoir s'en apercevoir
     * autrement qu'en la découvrant par hasard.
     */
    private function warnWhenNoValidationGroup(LeaveRequest $leaveRequest, User $targetUser): void
    {
        if ($leaveRequest->validation_group_id !== null) {
            return;
        }

        $this->auditLogService->log([
            'action' => 'leave_request_without_validation_group',
            'module' => 'leaves',
            'description' => sprintf(
                '%s n\'appartient à aucun groupe de validation : sa demande de congé suit le circuit à un seul niveau',
                $this->userLabel($targetUser),
            ),
            'payload' => [
                'leave_request_id' => (int) $leaveRequest->id,
                'target_user_id' => (int) $targetUser->id,
                'fallback_validator_id' => $leaveRequest->validator_1_id ? (int) $leaveRequest->validator_1_id : null,
            ],
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $allowedTargetIds = $this->resolveAllowedTargetIds($user);
        $canRequestForOthers = collect($allowedTargetIds)
            ->contains(fn ($id) => (int) $id !== (int) $user->id);
        $isAdmin = (bool) $user?->hasRole('admin');

        $users = $canRequestForOthers
            ? User::query()
                ->whereIn('id', $allowedTargetIds)
                ->where('is_active', true)
                ->orderByRaw('COALESCE(last_name, name) asc')
                ->orderByRaw('COALESCE(first_name, name) asc')
                ->get(['id', 'name', 'first_name', 'last_name', 'email'])
                ->map(function (User $candidate) {
                    $fullName = trim(
                        collect([$candidate->first_name, $candidate->last_name])
                            ->filter()
                            ->implode(' ')
                    );

                    return [
                        'id' => $candidate->id,
                        'label' => $fullName !== '' ? $fullName : ($candidate->name ?: $candidate->email),
                    ];
                })
                ->values()
                ->all()
            : [[
                'id' => $user->id,
                'label' => trim(
                    collect([$user->first_name, $user->last_name])
                        ->filter()
                        ->implode(' ')
                ) ?: ($user->name ?: $user->email),
            ]];

        $viewerId = (int) $user->id;
        $formatLeaveRequest = static function (LeaveRequest $leaveRequest) use ($viewerId, $isAdmin): array {
            $target = $leaveRequest->target;
            $proposedBy = $leaveRequest->proposedBy;
            $leaveType = $leaveRequest->leaveType;
            $targetLabel = trim(
                collect([$target?->first_name, $target?->last_name])
                    ->filter()
                    ->implode(' ')
            );

            return [
                'id' => $leaveRequest->id,
                'target_user_id' => (int) $leaveRequest->target_user_id,
                'target_label' => $targetLabel !== '' ? $targetLabel : ($target?->name ?: $target?->email),
                'leave_type_label' => $leaveType?->name,
                'start_at' => $leaveRequest->start_at?->toDateString(),
                'end_at' => $leaveRequest->end_at?->toDateString(),
                'status' => $leaveRequest->status,
                'message' => $leaveRequest->message,
                'proposed_start_at' => $leaveRequest->proposed_start_at?->toDateString(),
                'proposed_end_at' => $leaveRequest->proposed_end_at?->toDateString(),
                'proposed_start_portion' => $leaveRequest->proposed_start_portion,
                'proposed_end_portion' => $leaveRequest->proposed_end_portion,
                'proposed_custom_start_time' => $leaveRequest->proposed_custom_start_time,
                'proposed_custom_end_time' => $leaveRequest->proposed_custom_end_time,
                'proposed_message' => $leaveRequest->proposed_message,
                'proposed_by_user' => $proposedBy ? [
                    'id' => (int) $proposedBy->id,
                    'first_name' => $proposedBy->first_name,
                    'name' => $proposedBy->name,
                ] : null,

                // Étape du circuit, pour que la carte annonce « 1/2 » ou
                // « 2/2 » et montre au second valideur que le premier a déjà
                // donné son accord.
                'status_label' => $leaveRequest->validationStatusLabel(),
                'has_second_level' => $leaveRequest->hasSecondValidationLevel(),
                'validation_level' => $leaveRequest->currentValidationLevel(),
                'validator_1_label' => $leaveRequest->validator_1_label,
                'validator_2_label' => $leaveRequest->validator_2_label,
                'validator_1_decided_at' => $leaveRequest->validator_1_decided_at?->toIso8601String(),
                'validator_2_decided_at' => $leaveRequest->validator_2_decided_at?->toIso8601String(),
                'validation_group_name' => $leaveRequest->validation_group_name,

                // Vrai seulement si c'est à CE lecteur d'agir maintenant : les
                // boutons d'action ne s'affichent pas ailleurs.
                'awaiting_my_decision' => $isAdmin
                    ? $leaveRequest->currentValidationLevel() !== null
                    : $leaveRequest->currentValidatorId() === $viewerId,
            ];
        };

        $myLeaveRequests = LeaveRequest::query()
            ->with([
                'target:id,name,first_name,last_name,email',
                'leaveType:id,name',
                'proposedBy:id,name,first_name,last_name,email',
            ])
            ->where('requester_user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map($formatLeaveRequest)
            ->values()
            ->all();

        $leaveRequestsToValidateQuery = LeaveRequest::query()
            ->with([
                'target:id,name,first_name,last_name,email',
                'leaveType:id,name',
                'proposedBy:id,name,first_name,last_name,email',
            ]);

        if (! $isAdmin) {
            // Un valideur voit les demandes des groupes dont il est valideur,
            // et rien d'autre : être Valideur 1 quelque part ne donne aucune
            // visibilité sur les membres d'un autre groupe. L'historique de ses
            // propres décisions reste inclus, d'où `validatedBy` plutôt que la
            // seule file d'attente.
            $leaveRequestsToValidateQuery->validatedBy($user);
        }

        $leaveRequestsToValidate = $leaveRequestsToValidateQuery
            ->orderByDesc('created_at')
            ->get()
            ->map($formatLeaveRequest)
            ->values()
            ->all();

        // Compteur de la file : uniquement ce qui attend une décision de CET
        // utilisateur, au niveau qui est le sien. Une demande validée au
        // premier niveau disparaît du compteur du Valideur 1 et apparaît dans
        // celui du Valideur 2 — jamais dans les deux à la fois.
        $pendingValidationCount = $isAdmin
            ? LeaveRequest::query()->whereIn('status', ValidationStage::OPEN)->count()
            : LeaveRequest::query()->awaitingDecisionBy($user)->count();

        $canValidateRequests = $isAdmin || count($leaveRequestsToValidate) > 0;

        $leaveTypes = LeaveType::query()
            ->where('is_active', true)
            ->visibleForUser((int) $user->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'max_days'])
            ->map(fn (LeaveType $leaveType): array => [
                'id' => (int) $leaveType->id,
                'label' => $leaveType->name,
                'max_days' => (int) $leaveType->max_days,
            ])
            ->values()
            ->all();

        $highlightId = $request->query('highlight') ? (int) $request->query('highlight') : null;

        return Inertia::render('Leaves/Index', [
            'users' => $users,
            'leaveTypes' => $leaveTypes,
            'defaultTargetUserId' => $user->id,
            'canRequestForOthers' => $canRequestForOthers,
            'myLeaveRequests' => $myLeaveRequests,
            'leaveRequestsToValidate' => $leaveRequestsToValidate,
            'canValidateRequests' => $canValidateRequests,
            'pendingValidationCount' => $pendingValidationCount,
            'canDeleteLeaveRequests' => $isAdmin,
            'highlightId' => $highlightId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_at' => ['required_without:periods', 'date'],
            'end_at' => ['required_without:periods', 'date', 'after_or_equal:start_at'],
            'start_portion' => ['nullable', 'string', Rule::in(['full_day', 'morning', 'afternoon', 'custom'])],
            'end_portion' => ['nullable', 'string', Rule::in(['full_day', 'morning', 'afternoon', 'custom'])],
            'is_all_day' => ['nullable', 'boolean'],
            'custom_start_time' => ['nullable', 'date_format:H:i'],
            'custom_end_time' => ['nullable', 'date_format:H:i', 'after:custom_start_time'],
            'periods' => ['nullable', 'array', 'min:1'],
            'periods.*.start_date' => ['required_with:periods', 'date'],
            'periods.*.end_date' => ['required_with:periods', 'date'],
            'periods.*.start_portion' => ['required_with:periods', 'string', Rule::in(['full_day', 'morning', 'afternoon', 'custom'])],
            'periods.*.end_portion' => ['required_with:periods', 'string', Rule::in(['full_day', 'morning', 'afternoon', 'custom'])],
            'periods.*.description' => ['nullable', 'string', 'max:5000'],
            'periods.*.custom_start_time' => ['nullable', 'date_format:H:i'],
            'periods.*.custom_end_time' => ['nullable', 'date_format:H:i'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $normalizedPeriods = [];

        if (! empty($validated['periods'])) {
            foreach ($validated['periods'] as $index => $period) {
                $startPortion = (string) ($period['start_portion'] ?? 'full_day');
                $endPortion = (string) ($period['end_portion'] ?? 'full_day');
                $startTime = $this->resolvePortionTime($startPortion, $period['custom_start_time'] ?? null, true);
                $endTime = $this->resolvePortionTime($endPortion, $period['custom_end_time'] ?? null, false);

                $startAt = Carbon::parse(sprintf('%s %s:00', $period['start_date'], $startTime));
                $endAt = Carbon::parse(sprintf('%s %s:00', $period['end_date'], $endTime));

                if ($endAt->lt($startAt)) {
                    throw ValidationException::withMessages([
                        "periods.$index.end_date" => 'La date/heure de fin doit être postérieure ou égale à la date/heure de début.',
                    ]);
                }

                $normalizedPeriods[] = [
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'start_portion' => $startPortion,
                    'end_portion' => $endPortion,
                    'description' => array_key_exists('description', $period)
                        ? (filled($period['description']) ? (string) $period['description'] : null)
                        : null,
                    'custom_start_time' => $startPortion === 'custom' ? ($period['custom_start_time'] ?? null) : null,
                    'custom_end_time' => $endPortion === 'custom' ? ($period['custom_end_time'] ?? null) : null,
                    'is_all_day' => $startPortion === 'full_day' && $endPortion === 'full_day',
                ];
            }
        } else {
            $startPortion = (string) ($validated['start_portion'] ?? 'full_day');
            $endPortion = (string) ($validated['end_portion'] ?? 'full_day');

            $normalizedPeriods[] = [
                'start_at' => Carbon::parse($validated['start_at']),
                'end_at' => Carbon::parse($validated['end_at']),
                'start_portion' => $startPortion,
                'end_portion' => $endPortion,
                'custom_start_time' => $startPortion === 'custom' ? ($validated['custom_start_time'] ?? null) : null,
                'custom_end_time' => $endPortion === 'custom' ? ($validated['custom_end_time'] ?? null) : null,
                'description' => array_key_exists('message', $validated)
                    ? (filled($validated['message']) ? (string) $validated['message'] : null)
                    : null,
                'is_all_day' => array_key_exists('is_all_day', $validated)
                    ? (bool) $validated['is_all_day']
                    : ($startPortion === 'full_day' && $endPortion === 'full_day'),
            ];
        }

        $leaveType = LeaveType::query()
            ->where('is_active', true)
            ->visibleForUser((int) $request->user()->id)
            ->find((int) $validated['leave_type_id']);

        if (! $leaveType) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'Ce type de congé n’est pas disponible pour votre profil.',
            ]);
        }

        if ($leaveType->max_days !== null) {
            foreach ($normalizedPeriods as $periodIndex => $period) {
                $startDate = Carbon::parse($period['start_at'])->startOfDay();
                $endDate = Carbon::parse($period['end_at'])->startOfDay();
                $requestedDays = $startDate->diffInDays($endDate) + 1;

                if ($requestedDays > (int) $leaveType->max_days) {
                    $message = sprintf(
                        'Ce type de congé est limité à %d jour(s). Durée demandée : %d jour(s).',
                        (int) $leaveType->max_days,
                        $requestedDays
                    );
                    $errorField = ! empty($validated['periods'])
                        ? "periods.$periodIndex.end_date"
                        : 'leave_type_id';

                    throw ValidationException::withMessages([
                        $errorField => $message,
                    ]);
                }
            }
        }

        $requester = $request->user();
        $allowedTargetIds = $this->resolveAllowedTargetIds($requester);
        $canRequestForOthers = collect($allowedTargetIds)
            ->contains(fn ($id) => (int) $id !== (int) $requester->id);
        $targetUserId = $canRequestForOthers
            ? (int) $validated['target_user_id']
            : (int) $requester->id;

        if ($canRequestForOthers) {
            abort_unless(in_array($targetUserId, $allowedTargetIds, true), 403);
        }

        $targetUser = User::query()->findOrFail($targetUserId);

        $requesterLabel = trim(
            collect([$requester->first_name, $requester->last_name])
                ->filter()
                ->implode(' ')
        ) ?: ($requester->name ?: $requester->email);

        foreach ($normalizedPeriods as $period) {
            $leaveRequest = new LeaveRequest([
                'target_user_id' => $targetUserId,
                'leave_type_id' => (int) $validated['leave_type_id'],
                'start_at' => $period['start_at'],
                'end_at' => $period['end_at'],
                'start_portion' => $period['start_portion'],
                'end_portion' => $period['end_portion'],
                'custom_start_time' => $period['custom_start_time'],
                'custom_end_time' => $period['custom_end_time'],
                'is_all_day' => (bool) $period['is_all_day'],
                'message' => $period['description'] ?? null,
                'requester_user_id' => $requester->id,
            ]);

            // Les valideurs sont figés sur la demande à cet instant. Le groupe
            // du demandeur peut ensuite changer, être renommé ou supprimé :
            // cette demande-ci gardera son circuit et son historique.
            $this->twoStepValidation->assign(
                $leaveRequest,
                $targetUser,
                fn (): ?User => $this->legacyLeaveValidatorFor($targetUser),
            );

            $validator = $leaveRequest->validator_1_id
                ? User::query()->find($leaveRequest->validator_1_id)
                : null;

            // `validator_user_id` reste « le valideur dont la décision est
            // attendue » : le calendrier, l'écran de détail et les relances
            // continuent de s'appuyer dessus sans rien changer.
            $leaveRequest->validator_user_id = $leaveRequest->validator_1_id;
            $leaveRequest->save();

            $this->warnWhenNoValidationGroup($leaveRequest, $targetUser);

            if ($validator) {
                $validator->notify(new LeaveRequestSubmittedNotification($leaveRequest, $requesterLabel));

                SendWebPushNotificationJob::dispatch($validator->id, [
                    'title' => 'Demande de congé',
                    'body' => sprintf('%s a soumis une demande de congé', $requesterLabel),
                    'icon' => '/pwa-192.png',
                    'url' => route('leaves.index', ['highlight' => $leaveRequest->id]),
                    'resourceType' => 'leave_request',
                    'resourceId' => (int) $leaveRequest->id,
                    'action' => 'view',
                ]);
            }

            $this->auditLogService->log([
                'action' => 'create_leave_request',
                'module' => 'leaves',
                'description' => sprintf(
                    '%s a demandé un congé du %s au %s',
                    $this->userLabel($requester),
                    $leaveRequest->start_at?->toDateString() ?? '',
                    $leaveRequest->end_at?->toDateString() ?? ''
                ),
                'payload' => [
                    'leave_request_id' => (int) $leaveRequest->id,
                    'before' => null,
                    'after' => $this->leaveRequestAuditSnapshot($leaveRequest),
                ],
            ]);
        }

        return back()->with('success', count($normalizedPeriods) > 1
            ? 'Demandes de congé enregistrées.'
            : 'Demande de congé enregistrée.');
    }

    /**
     * @return int[]
     */
    private function resolveAllowedTargetIds(User $creator): array
    {
        return LeaveAllowedCreatorPair::query()
            ->where('creator_user_id', (int) $creator->id)
            ->pluck('target_user_id')
            ->map(fn ($targetUserId) => (int) $targetUserId)
            ->push((int) $creator->id)
            ->unique()
            ->values()
            ->all();
    }

    private function resolvePortionTime(string $portion, ?string $customTime, bool $isStart): string
    {
        if ($portion === 'custom' && $customTime) {
            return $customTime;
        }

        return match ($portion) {
            'morning' => $isStart ? '08:00' : '12:00',
            'afternoon' => $isStart ? '14:00' : '18:00',
            default => $isStart ? '00:00' : '18:00',
        };
    }

    public function approve(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->findOrFail($id);
        abort_unless($this->canValidateLeaveRequest($request, $leaveRequest), 403);

        $before = $this->leaveRequestAuditSnapshot($leaveRequest);
        $transition = $this->twoStepValidation->approve($leaveRequest, $request->user());

        if (! $transition->wasApplied) {
            return $this->staleValidationResponse($request);
        }

        if ($transition->isFinal) {
            $this->notifyFinalApproval($leaveRequest);
        } else {
            $this->notifySecondLevelValidator($leaveRequest);
        }

        $this->auditLogService->log([
            'action' => $transition->isFinal ? 'approve_leave_request' : 'approve_leave_request_level_1',
            'module' => 'leaves',
            'description' => $transition->isFinal
                ? sprintf(
                    '%s a définitivement accepté le congé de %s',
                    $this->userLabel($request->user()),
                    $this->userLabel($leaveRequest->target)
                )
                : sprintf(
                    '%s a validé au premier niveau le congé de %s, en attente de %s',
                    $this->userLabel($request->user()),
                    $this->userLabel($leaveRequest->target),
                    $leaveRequest->validator_2_label ?? 'la seconde validation'
                ),
            'payload' => [
                'leave_request_id' => (int) $leaveRequest->id,
                'validation_level' => $transition->level,
                'validation_trail' => $leaveRequest->validationTrail(),
                'before' => $before,
                'after' => $this->leaveRequestAuditSnapshot($leaveRequest),
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $leaveRequest->status]);
        }

        return back()->with('success', $transition->isFinal
            ? 'Demande de congé définitivement acceptée.'
            : sprintf(
                'Validation enregistrée. La demande passe à %s pour la seconde validation.',
                $leaveRequest->validator_2_label ?? 'le second valideur'
            ));
    }

    /**
     * Notifie le Valideur 2 que la demande lui revient.
     */
    private function notifySecondLevelValidator(LeaveRequest $leaveRequest): void
    {
        // Le valideur attendu change : `validator_user_id` suit, pour que le
        // calendrier et l'écran de détail désignent la bonne personne.
        $leaveRequest->validator_user_id = $leaveRequest->validator_2_id;
        $leaveRequest->save();

        $secondValidator = $leaveRequest->validator_2_id
            ? User::query()->find($leaveRequest->validator_2_id)
            : null;

        if (! $secondValidator) {
            return;
        }

        $targetLabel = $this->userLabel($leaveRequest->target);
        $firstValidatorLabel = $leaveRequest->validator_1_label ?? 'le premier valideur';

        $secondValidator->notify(new LeaveRequestFirstLevelApprovedNotification(
            $leaveRequest,
            $targetLabel,
            $firstValidatorLabel,
        ));

        SendWebPushNotificationJob::dispatch($secondValidator->id, [
            'title' => 'Congé à valider (2/2)',
            'body' => sprintf(
                'La demande de %s a été validée au premier niveau et attend votre validation.',
                $targetLabel,
            ),
            'icon' => '/pwa-192.png',
            'url' => route('leaves.index', ['highlight' => $leaveRequest->id]),
            'resourceType' => 'leave_request',
            'resourceId' => (int) $leaveRequest->id,
            'action' => 'view',
        ]);
    }

    /**
     * Notifie le demandeur que sa demande est définitivement acceptée.
     */
    private function notifyFinalApproval(LeaveRequest $leaveRequest): void
    {
        $requester = $leaveRequest->requester;

        if (! $requester) {
            return;
        }

        $requester->notify(new LeaveRequestApprovedNotification($leaveRequest));

        SendWebPushNotificationJob::dispatch($requester->id, [
            'title' => 'Congé accepté',
            'body' => sprintf(
                'Votre demande de congé du %s au %s a été définitivement acceptée.',
                $leaveRequest->start_at?->format('d/m/Y') ?? '-',
                $leaveRequest->end_at?->format('d/m/Y') ?? '-',
            ),
            'icon' => '/pwa-192.png',
            'url' => route('leaves.index', ['highlight' => $leaveRequest->id]),
            'resourceType' => 'leave_request',
            'resourceId' => (int) $leaveRequest->id,
            'action' => 'view',
        ]);
    }

    /**
     * Réponse lorsqu'une demande a déjà changé d'état entre l'affichage et le
     * clic : second onglet, double-clic, ou l'autre valideur passé avant.
     * Rien n'est rejoué, l'utilisateur est simplement informé.
     */
    private function staleValidationResponse(Request $request): RedirectResponse|JsonResponse
    {
        $message = 'Cette demande a déjà été traitée entre-temps. La page a été actualisée.';

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message], 409);
        }

        return back()->with('error', $message);
    }

    public function proposeModification(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->findOrFail($id);
        $user = $request->user();

        // Proposer une autre période est un acte de valideur : mêmes règles
        // que valider ou refuser, donc seul le valideur de l'étape courante
        // (ou un administrateur) peut le faire.
        $proposingLevel = $this->twoStepValidation->authorizedLevelFor($leaveRequest, $user);
        abort_unless($proposingLevel !== null, 403);
        $before = $this->leaveRequestAuditSnapshot($leaveRequest);

        $validated = $request->validate([
            'proposed_start_at' => ['required', 'date'],
            'proposed_end_at' => ['required', 'date', 'after_or_equal:proposed_start_at'],
            'proposed_start_portion' => ['nullable', 'string', Rule::in(['full_day', 'morning', 'afternoon', 'custom'])],
            'proposed_end_portion' => ['nullable', 'string', Rule::in(['full_day', 'morning', 'afternoon', 'custom'])],
            'proposed_custom_start_time' => ['nullable', 'required_if:proposed_start_portion,custom', 'date_format:H:i'],
            'proposed_custom_end_time' => ['nullable', 'required_if:proposed_end_portion,custom', 'date_format:H:i', 'after:proposed_custom_start_time'],
            'proposed_message' => ['nullable', 'string', 'max:5000'],
        ]);

        $leaveRequest->proposed_start_at = Carbon::parse($validated['proposed_start_at']);
        $leaveRequest->proposed_end_at = Carbon::parse($validated['proposed_end_at']);
        $leaveRequest->proposed_start_portion = $validated['proposed_start_portion'] ?? null;
        $leaveRequest->proposed_end_portion = $validated['proposed_end_portion'] ?? null;
        $leaveRequest->proposed_custom_start_time = $validated['proposed_custom_start_time'] ?? null;
        $leaveRequest->proposed_custom_end_time = $validated['proposed_custom_end_time'] ?? null;
        $leaveRequest->proposed_message = $validated['proposed_message'] ?? null;
        $leaveRequest->proposed_by_user_id = (int) $user->id;
        // Le niveau d'où part la proposition est mémorisé : c'est lui qui dira
        // où reprendre le circuit si le demandeur accepte la nouvelle période.
        $leaveRequest->proposed_at_level = $proposingLevel;
        $leaveRequest->status = LeaveRequest::STATUS_PENDING_USER_CONFIRMATION;
        $leaveRequest->save();

        $requester = $leaveRequest->requester;
        if ($requester) {
            $requester->notify(new LeaveRequestModificationProposedNotification($leaveRequest));

            SendWebPushNotificationJob::dispatch($requester->id, [
                'title' => 'Période de congé modifiée',
                'body' => 'La période proposée pour votre demande de congé a été modifiée. Consultez la demande pour la valider.',
                'icon' => '/pwa-192.png',
                'url' => route('leaves.index', ['highlight' => $leaveRequest->id]),
                'resourceType' => 'leave_request',
                'resourceId' => (int) $leaveRequest->id,
                'action' => 'view',
            ]);
        }

        $this->auditLogService->log([
            'action' => 'propose_leave_modification',
            'module' => 'leaves',
            'description' => sprintf(
                '%s a proposé une modification du congé de %s',
                $this->userLabel($user),
                $this->userLabel($leaveRequest->target)
            ),
            'payload' => [
                'leave_request_id' => (int) $leaveRequest->id,
                'before' => $before,
                'after' => $this->leaveRequestAuditSnapshot($leaveRequest),
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Contre-proposition de congé enregistrée.');
    }

    public function acceptProposedModification(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->findOrFail($id);
        $user = $request->user();
        $before = $this->leaveRequestAuditSnapshot($leaveRequest);

        abort_unless((int) $leaveRequest->requester_user_id === (int) $user?->id, 403);
        abort_unless($leaveRequest->status === LeaveRequest::STATUS_PENDING_USER_CONFIRMATION, 422);
        abort_if(! $leaveRequest->proposed_start_at || ! $leaveRequest->proposed_end_at, 422);

        $acceptedStartAt = $leaveRequest->proposed_start_at->toDateString();
        $acceptedEndAt = $leaveRequest->proposed_end_at->toDateString();
        $startPortion = (string) ($leaveRequest->proposed_start_portion ?: 'full_day');
        $endPortion = (string) ($leaveRequest->proposed_end_portion ?: 'full_day');
        $startTime = $this->resolvePortionTime($startPortion, $leaveRequest->proposed_custom_start_time, true);
        $endTime = $this->resolvePortionTime($endPortion, $leaveRequest->proposed_custom_end_time, false);

        $leaveRequest->start_at = Carbon::parse($leaveRequest->proposed_start_at->toDateString().' '.$startTime.':00');
        $leaveRequest->end_at = Carbon::parse($leaveRequest->proposed_end_at->toDateString().' '.$endTime.':00');
        $leaveRequest->start_portion = $startPortion;
        $leaveRequest->end_portion = $endPortion;
        $leaveRequest->custom_start_time = $startPortion === 'custom' ? $leaveRequest->proposed_custom_start_time : null;
        $leaveRequest->custom_end_time = $endPortion === 'custom' ? $leaveRequest->proposed_custom_end_time : null;
        $leaveRequest->is_all_day = $startPortion === 'full_day' && $endPortion === 'full_day';

        // Le valideur qui a proposé cette période l'approuve de fait — mais
        // seulement pour SON niveau. Là où l'acceptation clôturait auparavant
        // la demande d'un coup, elle valide désormais l'étape d'où venait la
        // proposition, puis le circuit reprend son cours : une contre-
        // proposition du Valideur 1 ne saute pas le Valideur 2.
        $proposedAtLevel = (int) ($leaveRequest->proposed_at_level ?: 1);
        $proposedBy = $leaveRequest->proposedBy;
        $this->twoStepValidation->completeLevelAfterAgreement($leaveRequest, $proposedAtLevel, $proposedBy);
        $leaveRequest->proposed_at_level = null;
        $leaveRequest->proposed_start_at = null;
        $leaveRequest->proposed_end_at = null;
        $leaveRequest->proposed_start_portion = null;
        $leaveRequest->proposed_end_portion = null;
        $leaveRequest->proposed_custom_start_time = null;
        $leaveRequest->proposed_custom_end_time = null;
        $leaveRequest->proposed_message = null;
        $leaveRequest->proposed_by_user_id = null;
        $leaveRequest->save();

        // Le valideur à prévenir de l'acceptation est celui qui avait proposé
        // la période, donc le valideur courant AVANT que le circuit n'avance.
        $validator = $leaveRequest->validator;
        if ($validator) {
            $validator->notify(new LeaveRequestModificationAcceptedNotification($leaveRequest, $acceptedStartAt, $acceptedEndAt));

            SendWebPushNotificationJob::dispatch($validator->id, [
                'title' => 'Modification de congé acceptée',
                'body' => sprintf(
                    '%s a accepté la nouvelle période proposée pour sa demande de congé.',
                    $this->userLabel($user),
                ),
                'icon' => '/pwa-192.png',
                'url' => route('leaves.index', ['highlight' => $leaveRequest->id]),
                'resourceType' => 'leave_request',
                'resourceId' => (int) $leaveRequest->id,
                'action' => 'view',
            ]);
        }

        // Puis le circuit reprend là où il en était : second niveau à prévenir,
        // ou demandeur à informer que tout est définitivement accepté.
        if ($leaveRequest->status === ValidationStage::PENDING_VALIDATOR_2) {
            $this->notifySecondLevelValidator($leaveRequest);
        } elseif ($leaveRequest->status === ValidationStage::APPROVED) {
            $this->notifyFinalApproval($leaveRequest);
        }

        $after = $this->leaveRequestAuditSnapshot($leaveRequest);
        $this->auditLogService->log([
            'action' => 'update_leave_request',
            'module' => 'leaves',
            'description' => sprintf(
                '%s a modifié sa demande de congé du %s au %s',
                $this->userLabel($user),
                $leaveRequest->start_at?->toDateString() ?? '',
                $leaveRequest->end_at?->toDateString() ?? ''
            ),
            'payload' => [
                'leave_request_id' => (int) $leaveRequest->id,
                'before' => $before,
                'after' => $after,
            ],
        ]);
        $this->auditLogService->log([
            'action' => 'accept_leave_modification',
            'module' => 'leaves',
            'description' => sprintf(
                '%s a accepté la modification proposée pour son congé',
                $this->userLabel($user)
            ),
            'payload' => [
                'leave_request_id' => (int) $leaveRequest->id,
                'before' => $before,
                'after' => $after,
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Modification de période acceptée.');
    }

    public function refuseProposedModification(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->findOrFail($id);
        $user = $request->user();
        $before = $this->leaveRequestAuditSnapshot($leaveRequest);

        abort_unless((int) $leaveRequest->requester_user_id === (int) $user?->id, 403);
        abort_unless($leaveRequest->status === LeaveRequest::STATUS_PENDING_USER_CONFIRMATION, 422);

        $proposedStartAt = $leaveRequest->proposed_start_at?->toDateString();
        $proposedEndAt = $leaveRequest->proposed_end_at?->toDateString();
        // Le demandeur décline la contre-proposition : la demande s'arrête là,
        // comme auparavant. Aucune décision de valideur n'est inscrite dans
        // l'historique — ce refus-ci n'est pas le leur.
        $leaveRequest->status = LeaveRequest::STATUS_REFUSED;
        $leaveRequest->proposed_at_level = null;
        $leaveRequest->proposed_start_at = null;
        $leaveRequest->proposed_end_at = null;
        $leaveRequest->proposed_start_portion = null;
        $leaveRequest->proposed_end_portion = null;
        $leaveRequest->proposed_custom_start_time = null;
        $leaveRequest->proposed_custom_end_time = null;
        $leaveRequest->proposed_message = null;
        $leaveRequest->proposed_by_user_id = null;
        $leaveRequest->save();

        $validator = $leaveRequest->validator;
        if ($validator) {
            $validator->notify(new LeaveRequestModificationRefusedNotification($leaveRequest, $proposedStartAt, $proposedEndAt));

            SendWebPushNotificationJob::dispatch($validator->id, [
                'title' => 'Modification de congé refusée',
                'body' => sprintf(
                    '%s a refusé la nouvelle période proposée pour sa demande de congé.',
                    $this->userLabel($user),
                ),
                'icon' => '/pwa-192.png',
                'url' => route('leaves.index', ['highlight' => $leaveRequest->id]),
                'resourceType' => 'leave_request',
                'resourceId' => (int) $leaveRequest->id,
                'action' => 'view',
            ]);
        }

        $this->auditLogService->log([
            'action' => 'refuse_leave_modification',
            'module' => 'leaves',
            'description' => sprintf(
                '%s a refusé la modification proposée pour son congé',
                $this->userLabel($user)
            ),
            'payload' => [
                'leave_request_id' => (int) $leaveRequest->id,
                'before' => $before,
                'after' => $this->leaveRequestAuditSnapshot($leaveRequest),
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Modification de période refusée.');
    }

    public function refuse(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $leaveRequest = LeaveRequest::query()->findOrFail($id);
        abort_unless($this->canValidateLeaveRequest($request, $leaveRequest), 403);

        $before = $this->leaveRequestAuditSnapshot($leaveRequest);

        // Un refus arrête le circuit quel que soit le niveau : une demande
        // refusée au premier n'atteint jamais le second valideur.
        $transition = $this->twoStepValidation->refuse($leaveRequest, $request->user());

        if (! $transition->wasApplied) {
            return $this->staleValidationResponse($request);
        }

        $refusedByLabel = $this->userLabel($request->user());
        $requester = $leaveRequest->requester;

        if ($requester) {
            $requester->notify(new LeaveRequestRefusedNotification(
                $leaveRequest,
                $refusedByLabel,
                $transition->level,
            ));

            SendWebPushNotificationJob::dispatch($requester->id, [
                'title' => 'Congé refusé',
                'body' => sprintf(
                    'Votre demande de congé du %s au %s a été refusée par %s.',
                    $leaveRequest->start_at?->format('d/m/Y') ?? '-',
                    $leaveRequest->end_at?->format('d/m/Y') ?? '-',
                    $refusedByLabel,
                ),
                'icon' => '/pwa-192.png',
                'url' => route('leaves.index', ['highlight' => $leaveRequest->id]),
                'resourceType' => 'leave_request',
                'resourceId' => (int) $leaveRequest->id,
                'action' => 'view',
            ]);
        }

        $this->auditLogService->log([
            'action' => 'refuse_leave_request',
            'module' => 'leaves',
            'description' => sprintf(
                '%s a refusé le congé de %s au niveau %d',
                $refusedByLabel,
                $this->userLabel($leaveRequest->target),
                $transition->level ?? 1,
            ),
            'payload' => [
                'leave_request_id' => (int) $leaveRequest->id,
                'validation_level' => $transition->level,
                'validation_trail' => $leaveRequest->validationTrail(),
                'before' => $before,
                'after' => $this->leaveRequestAuditSnapshot($leaveRequest),
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $leaveRequest->status]);
        }

        return back()->with('success', 'Demande de congé refusée.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);

        $leaveRequest = LeaveRequest::query()->findOrFail($id);
        $before = $this->leaveRequestAuditSnapshot($leaveRequest);
        $leaveRequest->delete();

        $this->auditLogService->log([
            'action' => 'cancel_leave_request',
            'module' => 'leaves',
            'description' => sprintf(
                '%s a annulé la demande de congé de %s',
                $this->userLabel($request->user()),
                $this->userLabel($leaveRequest->target)
            ),
            'payload' => [
                'leave_request_id' => (int) $leaveRequest->id,
                'before' => $before,
                'after' => null,
            ],
        ]);

        return back()->with('success', 'Demande de congé supprimée.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $leaveRequest = LeaveRequest::with([
            'requester:id,name,first_name,last_name,email',
            'target:id,name,first_name,last_name,email',
            'leaveType:id,name',
            'validator:id,name,first_name,last_name,email',
            'proposedBy:id,name,first_name,last_name,email',
        ])->findOrFail($id);

        $isRequester = (int) $leaveRequest->requester_user_id === (int) $user->id;
        $isTarget = (int) $leaveRequest->target_user_id === (int) $user->id;
        // Consultation : les deux valideurs de la demande peuvent la lire à
        // tout moment, y compris le Valideur 2 avant son tour — voir n'est pas
        // décider, et il doit pouvoir suivre ce qui arrive vers lui.
        $isValidator = (int) $leaveRequest->validator_user_id === (int) $user->id
            || (int) $leaveRequest->validator_1_id === (int) $user->id
            || (int) $leaveRequest->validator_2_id === (int) $user->id;
        $isAdmin = (bool) $user->hasRole('admin');

        abort_unless($isRequester || $isTarget || $isValidator || $isAdmin, 403);

        return response()->json($this->leaveRequestDetailData($leaveRequest, $user));
    }

    private function leaveRequestDetailData(LeaveRequest $leaveRequest, User $user): array
    {
        $isRequester = (int) $leaveRequest->requester_user_id === (int) $user->id;
        $isTarget = (int) $leaveRequest->target_user_id === (int) $user->id;
        $isAdmin = (bool) $user->hasRole('admin');
        $canAct = $isRequester || $isTarget;

        // Le droit d'agir est celui du moteur de validation : il n'est vrai
        // que pour le valideur de l'étape courante. Le Valideur 2 lit la
        // demande dès le départ, mais ses boutons n'apparaissent qu'à son tour.
        $decisionLevel = $this->twoStepValidation->authorizedLevelFor($leaveRequest, $user);
        $canValidate = $decisionLevel !== null;

        return [
            'id' => (int) $leaveRequest->id,
            'status' => (string) $leaveRequest->status,
            'requester_label' => $this->userLabel($leaveRequest->requester),
            'target_label' => $this->userLabel($leaveRequest->target),
            'requester_is_target' => (int) $leaveRequest->requester_user_id === (int) $leaveRequest->target_user_id,
            'validator_label' => $leaveRequest->validator ? $this->userLabel($leaveRequest->validator) : null,
            'leave_type_label' => $leaveRequest->leaveType?->name ?? '—',
            'start_at' => $leaveRequest->start_at?->toDateString(),
            'end_at' => $leaveRequest->end_at?->toDateString(),
            'start_portion' => $leaveRequest->start_portion,
            'end_portion' => $leaveRequest->end_portion,
            'is_all_day' => (bool) $leaveRequest->is_all_day,
            'custom_start_time' => $leaveRequest->custom_start_time,
            'custom_end_time' => $leaveRequest->custom_end_time,
            'message' => $leaveRequest->message,
            'proposed_start_at' => $leaveRequest->proposed_start_at?->toDateString(),
            'proposed_end_at' => $leaveRequest->proposed_end_at?->toDateString(),
            'proposed_start_portion' => $leaveRequest->proposed_start_portion,
            'proposed_end_portion' => $leaveRequest->proposed_end_portion,
            'proposed_custom_start_time' => $leaveRequest->proposed_custom_start_time,
            'proposed_custom_end_time' => $leaveRequest->proposed_custom_end_time,
            'proposed_message' => $leaveRequest->proposed_message,
            'proposed_by_label' => $leaveRequest->proposedBy ? $this->userLabel($leaveRequest->proposedBy) : null,
            'created_at' => $leaveRequest->created_at?->toIso8601String(),
            'updated_at' => $leaveRequest->updated_at?->toIso8601String(),

            // Le fil de validation : qui était Valideur 1, qui est Valideur 2,
            // et quand chacun a tranché. Ces libellés sont figés sur la demande
            // et ne bougent plus, même si le groupe change ensuite.
            'status_label' => $leaveRequest->validationStatusLabel(),
            'validation_level' => $leaveRequest->currentValidationLevel(),
            'validation' => $leaveRequest->validationTrail(),

            'permissions' => [
                // `$canValidate` porte déjà l'étape : il est faux pour le
                // Valideur 2 tant que le Valideur 1 n'a pas tranché, et faux
                // pour le Valideur 1 une fois qu'il a tranché.
                'can_approve' => $canValidate,
                'can_refuse' => $canValidate,
                'can_propose_modification' => $canValidate,
                'can_accept_modification' => $canAct && $leaveRequest->status === LeaveRequest::STATUS_PENDING_USER_CONFIRMATION,
                'can_refuse_modification' => $canAct && $leaveRequest->status === LeaveRequest::STATUS_PENDING_USER_CONFIRMATION,
            ],
        ];
    }

    /**
     * Le droit d'agir vient du niveau où se trouve la demande, pas de la seule
     * identité du valideur : c'est ce qui empêche le Valideur 2 de trancher
     * avant le Valideur 1, y compris par appel direct de la route.
     */
    private function canValidateLeaveRequest(Request $request, LeaveRequest $leaveRequest): bool
    {
        return $this->twoStepValidation->canDecide($leaveRequest, $request->user());
    }

    private function userLabel(?User $user): string
    {
        if (! $user) {
            return 'Utilisateur';
        }

        $fullName = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));
        if ($fullName !== '') {
            return $fullName;
        }

        return (string) ($user->name ?: $user->email ?: 'Utilisateur');
    }

    /**
     * @return array<string, mixed>
     */
    private function leaveRequestAuditSnapshot(LeaveRequest $leaveRequest): array
    {
        $leaveType = $leaveRequest->leave_type_id
            ? LeaveType::query()->find((int) $leaveRequest->leave_type_id)
            : null;

        return [
            'leave_request_id' => (int) $leaveRequest->id,
            'requester_user_id' => (int) $leaveRequest->requester_user_id,
            'requester_label' => $this->userLabel($leaveRequest->requester),
            'target_user_id' => (int) $leaveRequest->target_user_id,
            'target_user_label' => $this->userLabel($leaveRequest->target),
            'validator_user_id' => $leaveRequest->validator_user_id ? (int) $leaveRequest->validator_user_id : null,
            'validator_label' => $this->userLabel($leaveRequest->validator),
            'leave_type_id' => $leaveRequest->leave_type_id ? (int) $leaveRequest->leave_type_id : null,
            'leave_type_label' => $leaveType?->name,
            'period' => [
                'start_at' => $leaveRequest->start_at?->toDateString(),
                'end_at' => $leaveRequest->end_at?->toDateString(),
                'start_portion' => $leaveRequest->start_portion,
                'end_portion' => $leaveRequest->end_portion,
                'custom_start_time' => $leaveRequest->custom_start_time,
                'custom_end_time' => $leaveRequest->custom_end_time,
                'is_all_day' => (bool) $leaveRequest->is_all_day,
            ],
            'proposed_period' => [
                'start_at' => $leaveRequest->proposed_start_at?->toDateString(),
                'end_at' => $leaveRequest->proposed_end_at?->toDateString(),
                'start_portion' => $leaveRequest->proposed_start_portion,
                'end_portion' => $leaveRequest->proposed_end_portion,
                'custom_start_time' => $leaveRequest->proposed_custom_start_time,
                'custom_end_time' => $leaveRequest->proposed_custom_end_time,
            ],
            'status' => $leaveRequest->status,
            'message' => $leaveRequest->message,
            'proposed_message' => $leaveRequest->proposed_message,
            // Identité réelle des valideurs au moment du cliché : l'audit reste
            // lisible même si le groupe est modifié ou supprimé plus tard.
            'validation' => $leaveRequest->validationTrail(),
        ];
    }
}

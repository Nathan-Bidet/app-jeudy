<?php

namespace App\Http\Controllers;

use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\HourSheetDecisionNotification;
use App\Services\AuditLogService;
use App\Services\Hours\ApprovedLeaveDayService;
use App\Services\Validation\TwoStepValidationService;
use App\Services\Validation\ValidationRolloutService;
use App\Services\Validation\ValidationTransition;
use App\Support\Access\AccessManager;
use App\Support\Hours\WorkTimeReference;
use App\Support\Validation\ValidationStage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Exception\InvalidSheetNameException;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HourSheetController extends Controller
{
    /**
     * Colonnes de l'export Excel, dans l'ordre.
     *
     * Unique définition : l'en-tête et toutes les lignes s'y réfèrent, ce qui
     * évite qu'une ligne se décale d'une colonne par rapport aux autres.
     */
    private const EXPORT_COLUMNS = [
        'Date',
        'Jour',
        'Début matin',
        'Fin matin',
        'Début soir',
        'Fin soir',
        'Total heures travaillées',
        'Heures supplémentaires',
        'Description',
        'Casse-croûte (Avant 5h)',
        'Déjeuner',
        'Dîner (Après 21h)',
        'Nuit (Déplacement long)',
        'Valideur 1',
        'Valideur 2',
    ];

    /** Rangs des colonnes de validation, seules à recevoir du texte libre. */
    private const EXPORT_COLUMN_VALIDATOR_1 = 13;

    private const EXPORT_COLUMN_VALIDATOR_2 = 14;

    /**
     * Couleurs d'un refus dans l'export, reprises du badge « Refusée » de
     * l'application (resources/js/Components/Hours/HourSheetBadges.jsx) et de
     * l'encadré du détail d'une journée refusée.
     */
    private const EXPORT_REFUSAL_BACKGROUND_COLOR = 'FEF2F2';

    private const EXPORT_REFUSAL_FONT_COLOR = 'B91C1C';

    public function __construct(
        private readonly ApprovedLeaveDayService $approvedLeaveDayService,
        private readonly AuditLogService $auditLogService,
        private readonly TwoStepValidationService $twoStepValidation,
        private readonly ValidationRolloutService $validationRollout,
    ) {
    }

    /**
     * Trace une journée saisie par un utilisateur sans groupe de validation.
     *
     * La saisie n'est pas bloquée : empêcher quelqu'un de déclarer ses heures
     * parce que l'administration n'a pas encore créé son groupe serait pire que
     * le problème. La journée part vers un administrateur, et l'anomalie est
     * journalisée pour être corrigée.
     */
    private function warnWhenNoValidationGroup(HourSheet $hourSheet, User $user): void
    {
        if ($hourSheet->validation_group_id !== null) {
            return;
        }

        $this->auditLogService->log([
            'action' => 'hour_sheet_without_validation_group',
            'module' => 'heures',
            'description' => sprintf(
                '%s n\'appartient à aucun groupe de validation : ses heures du %s suivent un circuit à un seul niveau',
                $this->userLabel($user),
                $hourSheet->work_date?->toDateString() ?? '',
            ),
            'payload' => [
                'hour_sheet_id' => (int) $hourSheet->id,
                'user_id' => (int) $user->id,
                'fallback_validator_id' => $hourSheet->validator_1_id ? (int) $hourSheet->validator_1_id : null,
            ],
        ]);
    }

    private function userLabel(?User $user): string
    {
        if (! $user) {
            return 'Utilisateur';
        }

        $fullName = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));

        return $fullName !== '' ? $fullName : (string) ($user->name ?: $user->email ?: 'Utilisateur');
    }

    /**
     * Valideur de repli quand le salarié n'a pas de groupe.
     *
     * Les Heures n'ont aucun réglage historique équivalent à celui des congés :
     * le dernier maillon de la cascade est donc le seul disponible, un
     * administrateur. Sans lui, la journée resterait sans destinataire.
     */
    private function fallbackHoursValidator(): ?User
    {
        // `whereHas` plutôt que la portée `role()` de Spatie : celle-ci lève
        // une exception quand le rôle n'existe pas, et l'enregistrement d'une
        // journée ne doit jamais échouer pour cette raison.
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
            ->orderByRaw('COALESCE(last_name, name) asc')
            ->orderByRaw('COALESCE(first_name, name) asc')
            ->first();
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $effectiveStartDate = $this->resolveHoursTrackingStartDate($user);

        $hourSheets = HourSheet::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $effectiveStartDate)
            ->orderByDesc('work_date')
            ->get()
            ->map(fn (HourSheet $hourSheet): array => [
                'id' => (int) $hourSheet->id,
                'work_date' => $hourSheet->work_date?->toDateString(),
                'morning_start' => $hourSheet->morning_start,
                'morning_end' => $hourSheet->morning_end,
                'afternoon_start' => $hourSheet->afternoon_start,
                'afternoon_end' => $hourSheet->afternoon_end,
                // Sans ce drapeau, une journée continue s'afficherait comme
                // deux demi-journées vides chez le salarié, alors que la file
                // de validation la rend correctement.
                'is_continuous_day' => (bool) $hourSheet->is_continuous_day,
                'total_minutes' => (int) $hourSheet->total_minutes,
                'description' => $hourSheet->description,
                'is_not_worked' => (bool) $hourSheet->is_not_worked,
                'has_breakfast_before_5' => (bool) $hourSheet->has_breakfast_before_5,
                'has_lunch' => (bool) $hourSheet->has_lunch,
                'has_dinner_after_21' => (bool) $hourSheet->has_dinner_after_21,
                'has_long_night' => (bool) $hourSheet->has_long_night,

                // `status` à null : journée saisie avant la mise en place de la
                // validation. Elle n'est ni validée ni en attente, et le front
                // l'affiche comme telle plutôt que d'inventer un état.
                'status' => $hourSheet->status,
                'status_label' => $hourSheet->isLegacyEntry()
                    ? 'Saisie antérieure à la validation'
                    : $hourSheet->validationStatusLabel(),
                // Pas de détail rang par rang ici : ce sont les journées du
                // salarié lui-même, et un badge global lui suffit. Le détail
                // reste servi aux valideurs, dans leur file de validation.
                'refusal_reason' => $hourSheet->refusal_reason,
            ])
            ->values()
            ->all();

        $canCreate = app(AccessManager::class)->can($request->user(), 'heures.create');
        $canExport = app(AccessManager::class)->can($request->user(), 'heures.export');

        [$hourSheetsToValidate, $pendingValidationCount] = $this->validationQueueFor($user);
        $approvedLeaveDays = $this->approvedLeaveDayService->approvedLeaveMapForUser(
            $userId,
            $effectiveStartDate,
            now(config('app.timezone', 'Europe/Paris'))->toDateString()
        );

        // `highlight` vient du lien d'une notification : la page ouvre le
        // détail de cette journée. L'identifiant n'est pas vérifié ici — la
        // liste servie ne contient que les journées du lecteur, une valeur
        // étrangère n'y trouve donc simplement aucune correspondance.
        $highlightId = $request->query('highlight') ? (int) $request->query('highlight') : null;

        return Inertia::render('Hours/Index', [
            'hourSheets' => $hourSheets,
            'highlightId' => $highlightId,
            'canCreate' => $canCreate,
            'canExport' => $canExport,
            'approvedLeaveDays' => $approvedLeaveDays,
            'minVisibleDate' => $effectiveStartDate,
            'hourSheetsToValidate' => $hourSheetsToValidate,
            'pendingValidationCount' => $pendingValidationCount,
            'canValidateHours' => $hourSheetsToValidate !== [] || $pendingValidationCount > 0,
        ]);
    }

    /**
     * File de validation de l'utilisateur : les journées sur lesquelles il
     * peut encore se prononcer.
     *
     * Une journée y entre dès sa saisie pour SES DEUX valideurs, et n'en sort,
     * pour chacun, qu'une fois qu'il a lui-même tranché. Les journées
     * antérieures au circuit (status null) n'y figurent pas.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function validationQueueFor(User $user): array
    {
        $isAdmin = (bool) $user->hasRole('admin');

        $query = HourSheet::query()->with('user:id,name,first_name,last_name,email');

        if ($isAdmin) {
            $query->whereIn('status', ValidationStage::OPEN);
        } else {
            $query->awaitingDecisionBy($user);
        }

        $sheets = $query
            ->orderBy('work_date')
            ->orderBy('user_id')
            ->limit(200)
            ->get();

        $rows = $sheets
            ->map(fn (HourSheet $hourSheet): array => [
                'id' => (int) $hourSheet->id,
                'work_date' => $hourSheet->work_date?->toDateString(),
                'user_label' => $this->userLabel($hourSheet->user),

                // Horaires réellement enregistrés, sur lesquels porte la
                // décision. Les demi-journées couvertes par un congé sont déjà
                // stockées à null par store() : la file n'a donc pas besoin de
                // reconstituer la couverture pour éviter d'afficher une plage
                // qui n'existe pas.
                'morning_start' => $hourSheet->morning_start,
                'morning_end' => $hourSheet->morning_end,
                'afternoon_start' => $hourSheet->afternoon_start,
                'afternoon_end' => $hourSheet->afternoon_end,
                'is_continuous_day' => (bool) $hourSheet->is_continuous_day,
                'total_minutes' => (int) $hourSheet->total_minutes,

                'description' => $hourSheet->description,
                'is_not_worked' => (bool) $hourSheet->is_not_worked,

                // Cases particulières : le valideur doit voir ce qui est
                // déclaré avant de se prononcer.
                'has_breakfast_before_5' => (bool) $hourSheet->has_breakfast_before_5,
                'has_lunch' => (bool) $hourSheet->has_lunch,
                'has_dinner_after_21' => (bool) $hourSheet->has_dinner_after_21,
                'has_long_night' => (bool) $hourSheet->has_long_night,

                'status' => $hourSheet->status,
                'status_label' => $hourSheet->validationStatusLabel(),
                'validation_summary' => $hourSheet->validationSummary(),
            ])
            ->values()
            ->all();

        $count = $isAdmin
            ? HourSheet::query()->whereIn('status', ValidationStage::OPEN)->count()
            : HourSheet::query()->awaitingDecisionBy($user)->count();

        return [$rows, $count];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'morning_start' => ['nullable', 'date_format:H:i'],
            'morning_end' => ['nullable', 'date_format:H:i'],
            'afternoon_start' => ['nullable', 'date_format:H:i'],
            'afternoon_end' => ['nullable', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_not_worked' => ['nullable', 'boolean'],
            'is_continuous_day' => ['nullable', 'boolean'],
            'has_breakfast_before_5' => ['nullable', 'boolean'],
            'has_lunch' => ['nullable', 'boolean'],
            'has_dinner_after_21' => ['nullable', 'boolean'],
            'has_long_night' => ['nullable', 'boolean'],
        ]);
        $effectiveStartDate = $this->resolveHoursTrackingStartDate($request->user());
        if ($validated['work_date'] < $effectiveStartDate) {
            throw ValidationException::withMessages([
                'work_date' => 'Cette journée est antérieure à votre date de début de saisie des heures.',
            ]);
        }

        $targetUserId = (int) $request->user()->id;
        $actorLabel = trim((string) (($request->user()->first_name ?? '').' '.($request->user()->last_name ?? '')))
            ?: ((string) ($request->user()->name ?? 'Utilisateur'));
        $existingHourSheet = HourSheet::query()
            ->where('user_id', $targetUserId)
            ->whereDate('work_date', $validated['work_date'])
            ->first();
        $beforeSnapshot = $existingHourSheet ? $this->hourSheetAuditSnapshot($existingHourSheet) : null;

        $isNotWorked = (bool) ($validated['is_not_worked'] ?? false);
        $description = trim((string) ($validated['description'] ?? ''));
        if (! $isNotWorked && $description === '') {
            throw ValidationException::withMessages([
                'description' => 'La description des travaux réalisés est obligatoire.',
            ]);
        }

        $approvedLeave = $this->approvedLeaveDayService->approvedLeaveMapForUser(
            $targetUserId,
            $validated['work_date'],
            $validated['work_date']
        )[$validated['work_date']] ?? null;

        if ((bool) ($approvedLeave['is_full_day'] ?? false)) {
            throw ValidationException::withMessages([
                'work_date' => 'Vous êtes en congé ce jour-là, aucune heure ne peut être saisie.',
            ]);
        }

        $morningUnavailable = (bool) ($approvedLeave['morning'] ?? false);
        $afternoonUnavailable = (bool) ($approvedLeave['afternoon'] ?? false);
        // Le mode "journée continue" n'a de sens que sur une journée classique
        // (ni congé du matin, ni congé du soir) : on ignore sinon la case
        // cochée pour ne jamais perturber la logique des congés existante.
        $isContinuousDay = (bool) ($validated['is_continuous_day'] ?? false)
            && ! $morningUnavailable
            && ! $afternoonUnavailable;

        $morningStart = $isNotWorked || $morningUnavailable ? null : ($validated['morning_start'] ?? null);
        $morningEnd = $isNotWorked || $morningUnavailable || $isContinuousDay ? null : ($validated['morning_end'] ?? null);
        $afternoonStart = $isNotWorked || $afternoonUnavailable || $isContinuousDay ? null : ($validated['afternoon_start'] ?? null);
        $afternoonEnd = $isNotWorked || $afternoonUnavailable ? null : ($validated['afternoon_end'] ?? null);

        if ($isNotWorked) {
            $totalMinutes = 0;
        } elseif ($isContinuousDay) {
            // Début = morning_start, Fin = afternoon_end : une seule plage,
            // avec passage après minuit autorisé pour le calcul uniquement.
            $totalMinutes = $this->computeRangeMinutes($morningStart, $afternoonEnd, 'journée continue', true);
        } else {
            $morningMinutes = $this->computeRangeMinutes($morningStart, $morningEnd, 'matin');
            $afternoonMinutes = $this->computeRangeMinutes($afternoonStart, $afternoonEnd, 'soir', true);
            $totalMinutes = $morningMinutes + $afternoonMinutes;
        }

        $savedHourSheet = DB::transaction(function () use (
            $targetUserId,
            $validated,
            $morningStart,
            $morningEnd,
            $afternoonStart,
            $afternoonEnd,
            $totalMinutes,
            $description,
            $isNotWorked,
            $isContinuousDay,
            $request
        ): HourSheet {
            // Recherche explicite plutôt qu'`updateOrCreate` : la colonne
            // `work_date` est castée en date, si bien que la clause `where`
            // d'`updateOrCreate` compare une valeur brute ('2026-10-05') à une
            // valeur stockée formatée ('2026-10-05 00:00:00'). MySQL fait la
            // conversion, pas SQLite — la journée était alors réinsérée au lieu
            // d'être mise à jour. `whereDate` se comporte pareil partout, et le
            // verrou évite qu'un double enregistrement simultané n'insère deux
            // fois la même journée.
            $hourSheet = HourSheet::query()
                ->where('user_id', $targetUserId)
                ->whereDate('work_date', $validated['work_date'])
                ->lockForUpdate()
                ->first()
                ?? new HourSheet([
                    'user_id' => $targetUserId,
                    'work_date' => $validated['work_date'],
                ]);

            $hourSheet->fill(
                [
                    'morning_start' => $morningStart,
                    'morning_end' => $morningEnd,
                    'afternoon_start' => $afternoonStart,
                    'afternoon_end' => $afternoonEnd,
                    'total_minutes' => $totalMinutes,
                    'description' => $description !== '' ? $description : null,
                    'is_not_worked' => $isNotWorked,
                    'is_continuous_day' => $isContinuousDay,
                    'has_breakfast_before_5' => $isNotWorked ? false : (bool) ($validated['has_breakfast_before_5'] ?? false),
                    'has_lunch' => $isNotWorked ? false : (bool) ($validated['has_lunch'] ?? false),
                    'has_dinner_after_21' => $isNotWorked ? false : (bool) ($validated['has_dinner_after_21'] ?? false),
                    'has_long_night' => $isNotWorked ? false : (bool) ($validated['has_long_night'] ?? false),
                ]
            );

            // Enregistrer une journée la soumet à validation — à condition
            // qu'elle relève du nouveau système. Une journée antérieure à la
            // date d'effet garde le comportement d'avant : aucun circuit, donc
            // un statut nul. Rouvrir une journée déjà validée la renvoie au
            // début : la validation portait sur le contenu précédent, pas sur
            // celui-ci, et le motif de refus ne concerne plus cette saisie.
            if ($this->validationRollout->appliesToWorkDate($validated['work_date'])) {
                $this->twoStepValidation->assign(
                    $hourSheet,
                    $request->user(),
                    fn (): ?User => $this->fallbackHoursValidator(),
                );
            }

            $hourSheet->refusal_reason = null;
            $hourSheet->save();

            return $hourSheet;
        });

        if ($savedHourSheet->status !== null) {
            $this->warnWhenNoValidationGroup($savedHourSheet, $request->user());
        }

        $action = $isNotWorked
            ? 'mark_not_worked_day'
            : ($existingHourSheet ? 'update_hours_day' : 'create_hours_day');

        $this->auditLogService->log([
            'action' => $action,
            'module' => 'heures',
            'description' => $isNotWorked
                ? sprintf('%s a marqué le %s comme non travaillé', $actorLabel, (string) $validated['work_date'])
                : ($existingHourSheet
                    ? sprintf('%s a modifié ses heures du %s', $actorLabel, (string) $validated['work_date'])
                    : sprintf('%s a créé des heures pour le %s', $actorLabel, (string) $validated['work_date'])),
            'payload' => [
                'work_date' => (string) $validated['work_date'],
                'target_user_id' => $targetUserId,
                'is_not_worked' => $isNotWorked,
                'before' => $beforeSnapshot,
                'after' => $this->hourSheetAuditSnapshot($savedHourSheet),
            ],
        ]);

        return redirect()->route('hours.index')->with('success', 'Journée enregistrée avec succès.');
    }

    /**
     * Validation d'une journée d'heures à l'étape courante.
     *
     * Le contrôle du droit et de l'ordre est intégralement délégué au moteur
     * partagé : un Valideur 2 qui appellerait cette route avant le Valideur 1
     * reçoit un 403, quel que soit ce que montre son écran.
     */
    public function approve(Request $request, HourSheet $hourSheet): RedirectResponse|JsonResponse
    {
        abort_unless($this->twoStepValidation->canDecide($hourSheet, $request->user()), 403);

        $before = $this->hourSheetAuditSnapshot($hourSheet);
        $transition = $this->twoStepValidation->approve($hourSheet, $request->user());

        if (! $transition->wasApplied) {
            return $this->staleValidationResponse($request);
        }

        // Le salarié n'est prévenu qu'une fois les DEUX valideurs prononcés, et
        // de l'issue réelle : elle n'est pas forcément celle que vient
        // d'exprimer l'acteur, puisque le Valideur 2 tranche en cas de
        // désaccord.
        if ($transition->closesCircuit()) {
            $this->notifyHourSheetOwner($hourSheet, $transition->completesApproval(), $request->user());
        }

        $this->auditLogService->log([
            'action' => $transition->closesCircuit()
                ? 'approve_hour_sheet'
                : 'approve_hour_sheet_partial',
            'module' => 'heures',
            'description' => sprintf(
                '%s a validé les heures du %s de %s%s',
                $this->userLabel($request->user()),
                $hourSheet->work_date?->toDateString() ?? '',
                $this->userLabel($hourSheet->user),
                $transition->closesCircuit()
                    ? ' ; issue finale : '.($transition->completesApproval() ? 'validé' : 'refusé (décision du Valideur 2)')
                    : ' ; l\'autre valideur doit encore se prononcer',
            ),
            'payload' => [
                'hour_sheet_id' => (int) $hourSheet->id,
                'validation_levels' => $transition->levels,
                'validation_trail' => $hourSheet->validationTrail(),
                'before' => $before,
                'after' => $this->hourSheetAuditSnapshot($hourSheet),
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $hourSheet->status]);
        }

        return back()->with('success', $this->hourSheetOutcomeMessage($transition));
    }

    /**
     * Message rendu au valideur après sa décision.
     *
     * Tant que l'autre n'a pas répondu, rien n'est tranché. Une fois la
     * journée close, on annonce l'issue réelle, même quand elle contredit la
     * décision qui vient d'être prise.
     */
    private function hourSheetOutcomeMessage(ValidationTransition $transition): string
    {
        if (! $transition->closesCircuit()) {
            return 'Votre décision est enregistrée. La journée reste en attente de la seconde validation.';
        }

        return $transition->completesApproval()
            ? 'Journée définitivement validée.'
            : 'Journée définitivement refusée.';
    }

    /**
     * Refus d'une journée d'heures : le circuit s'arrête, quel que soit le
     * niveau atteint.
     */
    public function refuse(Request $request, HourSheet $hourSheet): RedirectResponse|JsonResponse
    {
        abort_unless($this->twoStepValidation->canDecide($hourSheet, $request->user()), 403);

        $validated = $request->validate([
            'refusal_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $this->hourSheetAuditSnapshot($hourSheet);
        $transition = $this->twoStepValidation->refuse($hourSheet, $request->user());

        if (! $transition->wasApplied) {
            return $this->staleValidationResponse($request);
        }

        // Le motif est conservé même si l'issue finit par être une validation :
        // c'est la trace de la position de ce valideur. L'écran ne l'affiche
        // que sur une journée effectivement refusée.
        $reason = trim((string) ($validated['refusal_reason'] ?? ''));
        $hourSheet->refusal_reason = $reason !== '' ? $reason : null;
        $hourSheet->save();

        if ($transition->closesCircuit()) {
            $this->notifyHourSheetOwner($hourSheet, $transition->completesApproval(), $request->user());
        }

        $this->auditLogService->log([
            'action' => $transition->closesCircuit()
                ? 'refuse_hour_sheet'
                : 'refuse_hour_sheet_partial',
            'module' => 'heures',
            'description' => sprintf(
                '%s a refusé les heures du %s de %s%s',
                $this->userLabel($request->user()),
                $hourSheet->work_date?->toDateString() ?? '',
                $this->userLabel($hourSheet->user),
                $transition->closesCircuit()
                    ? ' ; issue finale : '.($transition->completesRefusal() ? 'refusé' : 'validé (décision du Valideur 2)')
                    : ' ; l\'autre valideur doit encore se prononcer',
            ),
            'payload' => [
                'hour_sheet_id' => (int) $hourSheet->id,
                'validation_levels' => $transition->levels,
                'refusal_reason' => $hourSheet->refusal_reason,
                'validation_trail' => $hourSheet->validationTrail(),
                'before' => $before,
                'after' => $this->hourSheetAuditSnapshot($hourSheet),
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $hourSheet->status]);
        }

        return back()->with('success', $this->hourSheetOutcomeMessage($transition));
    }

    /**
     * Le salarié n'est prévenu QUE d'un refus.
     *
     * Une journée validée est le cas normal — une par personne et par jour
     * ouvré : la notifier reviendrait à annoncer que tout s'est passé comme
     * prévu, plusieurs fois par semaine et par salarié. Le statut reste lisible
     * sur la page Heures, qui porte déjà le badge de chaque journée. Seul le
     * refus appelle une action, et lui seul est notifié.
     *
     * C'est bien l'ISSUE du circuit qui décide, pas le bouton qui vient d'être
     * pressé : un refus du Valideur 1 rattrapé par un accord du Valideur 2 est
     * une validation, et ne notifie donc rien.
     *
     * Le passage du premier accord au second ne concerne pas non plus le
     * salarié : l'appelant ne notifie qu'une fois le circuit clos.
     *
     * Les Congés ne sont pas concernés : ils gardent leurs deux notifications.
     */
    private function notifyHourSheetOwner(HourSheet $hourSheet, bool $isApproved, ?User $actor): void
    {
        if ($isApproved) {
            return;
        }

        $owner = $hourSheet->user;

        if (! $owner) {
            return;
        }

        $owner->notify(new HourSheetDecisionNotification(
            $hourSheet,
            $isApproved,
            $this->userLabel($actor),
            $hourSheet->refusal_reason,
        ));
    }

    private function staleValidationResponse(Request $request): RedirectResponse|JsonResponse
    {
        $message = 'Cette journée a déjà été traitée entre-temps. La page a été actualisée.';

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message], 409);
        }

        return back()->with('error', $message);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $hourSheets = HourSheet::query()
            ->with('user:id,name,first_name,last_name')
            ->whereBetween('work_date', [$validated['start_date'], $validated['end_date']])
            ->orderBy('user_id')
            ->orderBy('work_date')
            ->get();

        $userIds = $hourSheets->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $leaveUserIds = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where(function ($query) use ($validated): void {
                $query
                    ->whereBetween('start_at', [$validated['start_date'].' 00:00:00', $validated['end_date'].' 23:59:59'])
                    ->orWhere(function ($subQuery) use ($validated): void {
                        $subQuery
                            ->whereNotNull('end_at')
                            ->whereBetween('end_at', [$validated['start_date'].' 00:00:00', $validated['end_date'].' 23:59:59']);
                    })
                    ->orWhere(function ($subQuery) use ($validated): void {
                        $subQuery
                            ->whereNotNull('end_at')
                            ->where('start_at', '<=', $validated['start_date'].' 00:00:00')
                            ->where('end_at', '>=', $validated['end_date'].' 23:59:59');
                    });
            })
            ->pluck('target_user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $userIds = array_values(array_unique(array_merge($userIds, $leaveUserIds)));

        $approvedLeavesByUser = $this->approvedLeaveDayService->approvedLeaveMapForUsers(
            $userIds,
            $validated['start_date'],
            $validated['end_date']
        );

        $downloadName = sprintf(
            'heures_%s_%s.xlsx',
            date('Y-m-d', strtotime($validated['start_date'])),
            date('Y-m-d', strtotime($validated['end_date']))
        );

        $filePath = storage_path('app/temp/'.$downloadName);
        if (! is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        // Les seules colonnes dont la largeur est fixée sont les deux nouvelles :
        // elles peuvent contenir « Refusé - » suivi d'un motif libre. Les autres
        // gardent la largeur par défaut, l'export ne change donc pas d'allure.
        $options = new XlsxOptions();
        $options->setColumnWidth(38.0, self::EXPORT_COLUMN_VALIDATOR_1 + 1, self::EXPORT_COLUMN_VALIDATOR_2 + 1);

        $writer = new Writer($options);
        $writer->openToFile($filePath);

        $groupedByUser = $hourSheets->groupBy(fn (HourSheet $sheet): int => (int) $sheet->user_id);
        $usersById = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'first_name', 'last_name'])
            ->keyBy('id');
        $exportUserIds = array_values(array_unique(array_merge(
            array_keys($groupedByUser->all()),
            array_keys($approvedLeavesByUser)
        )));
        sort($exportUserIds);
        $usedTitles = [];

        foreach ($exportUserIds as $index => $exportUserId) {
            $rows = $groupedByUser->get($exportUserId, collect());
            $sheetRef = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $user = $usersById->get((int) $exportUserId);
            $title = $this->uniqueSheetTitle($user, $usedTitles);
            $usedTitles[] = $title;

            try {
                $sheetRef->setName($title);
            } catch (InvalidSheetNameException) {
                $sheetRef->setName('Utilisateur '.($index + 1));
            }

            $writer->addRow($this->exportRow(self::EXPORT_COLUMNS));

            $userId = (int) $exportUserId;
            $rowsByDate = [];
            foreach ($rows as $sheet) {
                $dateKey = $sheet->work_date?->toDateString();
                if ($dateKey) {
                    $rowsByDate[$dateKey] = $sheet;
                }
            }

            $leaveMap = $approvedLeavesByUser[$userId] ?? [];
            $allDates = array_unique(array_merge(array_keys($rowsByDate), array_keys($leaveMap)));
            sort($allDates);

            foreach ($allDates as $dateKey) {
                $leave = $leaveMap[$dateKey] ?? null;
                $morningOnLeave = (bool) ($leave['morning'] ?? false);
                $afternoonOnLeave = (bool) ($leave['afternoon'] ?? false);
                $isFullDayLeave = (bool) ($leave['is_full_day'] ?? false)
                    || ($morningOnLeave && $afternoonOnLeave);

                if ($isFullDayLeave) {
                    $leaveDate = CarbonImmutable::parse($dateKey);
                    $writer->addRow($this->exportRow([
                        $leaveDate->format('d/m/Y'),
                        ucfirst((string) $leaveDate->locale('fr')->translatedFormat('l')),
                        'Congé validé',
                        '', '', '', '',
                        '', // heures supplémentaires
                        '', '', '', '', '',
                        '', '', // valideurs : aucune journée saisie ce jour-là
                    ]));
                    continue;
                }

                if (isset($rowsByDate[$dateKey])) {
                    $sheet = $rowsByDate[$dateKey];
                    $date = $sheet->work_date;
                    if ((bool) $sheet->is_not_worked) {
                        $writer->addRow($this->exportRow([
                            $date?->format('d/m/Y'),
                            $date?->locale('fr')->translatedFormat('l') ? ucfirst((string) $date->locale('fr')->translatedFormat('l')) : '',
                            'Non travaillé',
                            '', '', '',
                            '', // total : déjà vide avant cette évolution
                            '', // heures supplémentaires : aucune durée, donc aucun écart
                            $sheet->description ?? '',
                            '', '', '', '',
                            // La journée reste dans le circuit de validation :
                            // ses décisions sont exportées comme les autres.
                            $this->validatorDecisionForExport($sheet, 1),
                            $this->validatorDecisionForExport($sheet, 2),
                        ]));
                        continue;
                    }

                    if ((bool) $sheet->is_continuous_day) {
                        // Une seule plage Début (morning_start) -> Fin (afternoon_end) :
                        // morning_end et afternoon_start sont vides pour ces journées.
                        $totalMinutes = $this->computeRangeMinutes(
                            $sheet->morning_start,
                            $sheet->afternoon_end,
                            'journée continue',
                            true
                        );
                    } else {
                        $totalMinutes = ($morningOnLeave ? 0 : $this->computeRangeMinutes(
                            $sheet->morning_start,
                            $sheet->morning_end,
                            'matin'
                        )) + ($afternoonOnLeave ? 0 : $this->computeRangeMinutes(
                            $sheet->afternoon_start,
                            $sheet->afternoon_end,
                            'soir',
                            true
                        ));
                    }

                    $writer->addRow($this->exportRow([
                        $date?->format('d/m/Y'),
                        $date?->locale('fr')->translatedFormat('l') ? ucfirst((string) $date->locale('fr')->translatedFormat('l')) : '',
                        $morningOnLeave ? 'Congé' : $this->formatTimeForExport($sheet->morning_start),
                        $morningOnLeave ? 'Congé' : $this->formatTimeForExport($sheet->morning_end),
                        $afternoonOnLeave ? 'Congé' : $this->formatTimeForExport($sheet->afternoon_start),
                        $afternoonOnLeave ? 'Congé' : $this->formatTimeForExport($sheet->afternoon_end),
                        $this->formatMinutesForExport($totalMinutes),
                        // Même référence et même écart que le badge de la page
                        // Heures et que la file du valideur, au format de
                        // l'export : 01h00 plutôt que +1h00.
                        $this->formatMinutesForExport(
                            WorkTimeReference::overtimeForDay($totalMinutes, $date)
                        ),
                        $sheet->description ?? '',
                        $sheet->has_breakfast_before_5 ? 'Oui' : 'Non',
                        $sheet->has_lunch ? 'Oui' : 'Non',
                        $sheet->has_dinner_after_21 ? 'Oui' : 'Non',
                        $sheet->has_long_night ? 'Oui' : 'Non',
                        $this->validatorDecisionForExport($sheet, 1),
                        $this->validatorDecisionForExport($sheet, 2),
                    ]));
                    continue;
                }

                if (! $leave) {
                    continue;
                }

                $leaveDate = CarbonImmutable::parse($dateKey);
                $writer->addRow($this->exportRow([
                    $leaveDate->format('d/m/Y'),
                    ucfirst((string) $leaveDate->locale('fr')->translatedFormat('l')),
                    $morningOnLeave ? 'Congé' : '',
                    $morningOnLeave ? 'Congé' : '',
                    $afternoonOnLeave ? 'Congé' : '',
                    $afternoonOnLeave ? 'Congé' : '',
                    '',
                    '', // heures supplémentaires
                    '', '', '', '', '',
                    '', '', // valideurs : aucune journée saisie ce jour-là
                ]));
            }
        }

        if ($exportUserIds === []) {
            $writer->getCurrentSheet()->setName('Heures');
            $writer->addRow($this->exportRow(self::EXPORT_COLUMNS));
        }

        $writer->close();
        $actorLabel = trim((string) (($request->user()?->first_name ?? '').' '.($request->user()?->last_name ?? '')))
            ?: ((string) ($request->user()?->name ?? 'Utilisateur'));

        $this->auditLogService->log([
            'action' => 'export_hours',
            'module' => 'heures',
            'description' => sprintf(
                '%s a exporté les heures du %s au %s',
                $actorLabel,
                (string) $validated['start_date'],
                (string) $validated['end_date']
            ),
            'payload' => [
                'format' => 'xlsx',
                'start_date' => (string) $validated['start_date'],
                'end_date' => (string) $validated['end_date'],
                'target_user_scope' => 'all_users_with_data_or_approved_leave_in_period',
                'target_user_ids' => $exportUserIds,
            ],
        ]);

        return response()->download($filePath, $downloadName)->deleteFileAfterSend(true);
    }

    /**
     * @param  bool  $allowOvernight  Si true (créneau "soir" uniquement), une fin
     *                                 antérieure au début est considérée comme le
     *                                 lendemain (+24h) pour le calcul de la durée,
     *                                 sans jamais modifier work_date ni créer de
     *                                 données sur le jour suivant.
     */
    private function computeRangeMinutes(?string $start, ?string $end, string $label, bool $allowOvernight = false): int
    {
        if (! $start || ! $end) {
            return 0;
        }

        $startMinutes = $this->timeToMinutes($start);
        $endMinutes = $this->timeToMinutes($end);

        if ($startMinutes === null || $endMinutes === null) {
            return 0;
        }

        if ($endMinutes < $startMinutes) {
            if (! $allowOvernight) {
                throw ValidationException::withMessages([
                    'work_date' => sprintf('Plage %s invalide: arrivée avant départ.', $label),
                ]);
            }

            $endMinutes += 1440;
        }

        return $endMinutes - $startMinutes;
    }

    private function timeToMinutes(string $time): ?int
    {
        if (! str_contains($time, ':')) {
            return null;
        }

        [$hour, $minute] = explode(':', $time);
        if (! is_numeric($hour) || ! is_numeric($minute)) {
            return null;
        }

        return ((int) $hour * 60) + (int) $minute;
    }

    private function formatTimeForExport(?string $value): string
    {
        if (! $value || ! str_contains($value, ':')) {
            return '';
        }

        [$hours, $minutes] = explode(':', $value);

        return sprintf('%02d:%02d', (int) $hours, (int) $minutes);
    }

    /**
     * Une ligne de l'export.
     *
     * Seules les deux colonnes de validation reçoivent un style : elles seules
     * peuvent porter un motif de refus long (retour à la ligne), et elles
     * seules signalent un refus (fond rouge). Le reste de la feuille garde
     * exactement l'aspect qu'il avait.
     */
    private function exportRow(array $values): Row
    {
        return Row::fromValuesWithStyles($values, [
            self::EXPORT_COLUMN_VALIDATOR_1 => $this->validationCellStyle(
                $values[self::EXPORT_COLUMN_VALIDATOR_1] ?? null
            ),
            self::EXPORT_COLUMN_VALIDATOR_2 => $this->validationCellStyle(
                $values[self::EXPORT_COLUMN_VALIDATOR_2] ?? null
            ),
        ]);
    }

    /**
     * Style d'une cellule de validation : rouge lorsqu'elle porte un refus.
     *
     * Les couleurs sont celles du badge « Refusée » de l'application — fond
     * #FEF2F2, texte #B91C1C — pour que le tableur et l'écran disent la même
     * chose de la même façon.
     *
     * La police reprend explicitement celle du classeur (Calibri 12) : poser
     * une couleur de texte suffit à faire appliquer TOUTE la police par
     * OpenSpout, dont les valeurs par défaut de la classe Style (Arial 11)
     * différeraient du reste de la feuille.
     *
     * Aucune bordure n'est posée ni retirée : `Style` en est dépourvu par
     * défaut, comme le reste de l'export.
     */
    private function validationCellStyle(mixed $value): Style
    {
        $base = new Style(
            fontSize: XlsxOptions::DEFAULT_FONT_SIZE,
            fontName: XlsxOptions::DEFAULT_FONT_NAME,
            shouldWrapText: true,
        );

        if (! $this->isRefusalLabel($value)) {
            return $base;
        }

        return $base
            ->withFontColor(self::EXPORT_REFUSAL_FONT_COLOR)
            // ARGB explicite : OpenSpout convertit la couleur de police mais
            // recopie celle du fond telle quelle, et l'attribut `rgb` d'OOXML
            // attend huit caractères. Excel tolère la forme courte ; autant ne
            // pas dépendre de cette tolérance.
            ->withBackgroundColor(Color::toARGB(self::EXPORT_REFUSAL_BACKGROUND_COLOR));
    }

    /**
     * Une cellule de validation porte-t-elle un refus ?
     *
     * La cellule vaut « Refusé » seul ou « Refusé - motif » : c'est donc le
     * DÉBUT de la valeur qui est comparé, jamais l'égalité stricte, sans quoi
     * tout refus motivé passerait au travers.
     *
     * Le mot comparé n'est pas écrit ici : il vient de ValidationStage, qui
     * produit aussi le texte de la cellule. Renommer le libellé là-bas met donc
     * automatiquement la détection à jour — une chaîne recopiée aurait fini par
     * ne plus correspondre.
     */
    private function isRefusalLabel(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return str_starts_with(
            trim($value),
            ValidationStage::decisionLabel(ValidationStage::DECISION_REFUSED),
        );
    }

    /**
     * Décision d'un valideur, telle qu'elle part dans l'export.
     *
     * L'ÉTAT, jamais l'identité : les écrans n'affichent aucun nom de valideur,
     * l'export n'en affiche pas davantage. Les noms restent en base et dans
     * validationTrail(), pour l'audit.
     *
     * Les états réels de chaque rang sont exportés tels quels, sans recomposer
     * un statut global : une journée dont un seul valideur s'est prononcé
     * montre « Validé » d'un côté et « En attente » de l'autre.
     *
     * « Non applicable » couvre deux cas distincts mais de même nature — il n'y
     * a pas de décision à attendre : une journée antérieure à la date d'effet du
     * système de validation, et le rang 2 d'un groupe qui n'a pas de second
     * valideur. Une cellule vide se confondrait avec une donnée manquante.
     */
    private function validatorDecisionForExport(HourSheet $hourSheet, int $level): string
    {
        if ($hourSheet->isLegacyEntry()) {
            return 'Non applicable';
        }

        if ($level === 2 && ! $hourSheet->hasSecondValidationLevel()) {
            return 'Non applicable';
        }

        $decision = $hourSheet->decisionForLevel($level);
        // Même vocabulaire que les écrans : « Validé » / « Refusé » /
        // « En attente » viennent tous de ValidationStage.
        $label = ValidationStage::decisionLabel($decision);

        if ($decision !== ValidationStage::DECISION_REFUSED) {
            return $label;
        }

        // `refusal_reason` est porté par la journée, pas par le rang : les deux
        // valideurs qui refusent partagent donc le dernier motif enregistré.
        $reason = trim((string) $hourSheet->refusal_reason);

        return $reason !== '' ? $label.' - '.$reason : $label;
    }

    private function formatMinutesForExport(int $totalMinutes): string
    {
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        return sprintf('%02dh%02d', $hours, $minutes);
    }

    private function uniqueSheetTitle(?object $user, array $usedTitles): string
    {
        $fullName = trim((string) ($user?->last_name ? ($user->last_name.' ') : '').(string) ($user?->first_name ?? ''));
        if ($fullName === '') {
            $fullName = (string) ($user?->name ?? 'Utilisateur');
        }

        $baseTitle = $this->sanitizeSheetTitle($fullName);
        if (! in_array($baseTitle, $usedTitles, true)) {
            return $baseTitle;
        }

        $suffix = 2;
        while (true) {
            $candidate = $this->sanitizeSheetTitle($baseTitle.' '.$suffix);
            if (! in_array($candidate, $usedTitles, true)) {
                return $candidate;
            }
            $suffix++;
        }
    }

    private function sanitizeSheetTitle(string $title): string
    {
        $clean = trim(str_replace(['/', '\\', '?', '*', '[', ']'], ' ', $title));
        $clean = preg_replace('/\s+/', ' ', $clean) ?: 'Utilisateur';

        if (mb_strlen($clean) <= 31) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, 31));
    }

    private function resolveHoursTrackingStartDate(User $user): string
    {
        if ($user->hours_tracking_starts_at) {
            return $user->hours_tracking_starts_at->toDateString();
        }

        return (string) config('hours.min_visible_date', '2026-04-27');
    }

    /**
     * @return array<string, mixed>
     */
    private function hourSheetAuditSnapshot(HourSheet $hourSheet): array
    {
        return [
            'id' => (int) $hourSheet->id,
            'user_id' => (int) $hourSheet->user_id,
            'work_date' => $hourSheet->work_date?->toDateString(),
            'morning_start' => $hourSheet->morning_start,
            'morning_end' => $hourSheet->morning_end,
            'afternoon_start' => $hourSheet->afternoon_start,
            'afternoon_end' => $hourSheet->afternoon_end,
            'total_minutes' => (int) $hourSheet->total_minutes,
            'description' => $hourSheet->description,
            'is_not_worked' => (bool) $hourSheet->is_not_worked,
            'is_continuous_day' => (bool) $hourSheet->is_continuous_day,
            'has_breakfast_before_5' => (bool) $hourSheet->has_breakfast_before_5,
            'has_lunch' => (bool) $hourSheet->has_lunch,
            'has_dinner_after_21' => (bool) $hourSheet->has_dinner_after_21,
            'has_long_night' => (bool) $hourSheet->has_long_night,
        ];
    }
}

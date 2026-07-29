<?php

namespace App\Http\Controllers;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\Announcement;
use App\Models\AnnouncementPoll;
use App\Models\AnnouncementPollResponse;
use App\Models\AnnouncementRecipientGroup;
use App\Models\AnnouncementView;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Services\Announcements\AnnouncementPollPresenter;
use App\Services\Announcements\AnnouncementRecipientResolver;
use App\Services\AuditLogService;
use App\Support\Access\AccessManager;
use App\Support\RichText\SimpleHtmlSanitizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AnnouncementRecipientResolver $recipientResolver,
        private readonly AnnouncementPollPresenter $pollPresenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $access = app(AccessManager::class);

        $canView = (bool) ($user && $access->can($user, 'annonces.view'));
        $canCreate = (bool) ($user && $access->can($user, 'annonces.create'));
        $canManage = (bool) ($user && $access->can($user, 'annonces.manage'));
        $canAccessModule = $canView || $canCreate || $canManage;

        $validated = $request->validate([
            'open_draft' => ['nullable', 'integer'],
            'highlight' => ['nullable', 'integer'],
        ]);
        $highlightId = ! empty($validated['highlight']) ? (int) $validated['highlight'] : null;
        $highlightAnnouncement = $this->highlightAnnouncement($highlightId, $user, $canManage);

        if (! $canAccessModule && ! $highlightAnnouncement) {
            abort(403);
        }

        $announcementsQuery = Announcement::query()
            ->with($this->announcementRelations());

        if (! $canAccessModule) {
            $announcementsQuery->whereRaw('1 = 0');
        } elseif (! $canManage) {
            $announcementsQuery->where('created_by_user_id', $user?->id);
        }

        $announcements = $announcementsQuery
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Announcement $announcement): array => $this->presentAnnouncement($announcement, $user, $canManage))
            ->values()
            ->all();

        $openDraft = null;
        if ($canAccessModule && ! empty($validated['open_draft'])) {
            $draft = Announcement::query()
                ->with($this->announcementRelations())
                ->whereKey((int) $validated['open_draft'])
                ->first();

            if ($draft && ($canManage || (int) $draft->created_by_user_id === (int) $user?->id)) {
                $openDraft = $this->presentAnnouncement($draft, $user, $canManage);
            }
        }

        return Inertia::render('Annonces/Index', [
            'permissions' => [
                'can_view' => $canView,
                'can_create' => $canCreate,
                'can_manage' => $canManage,
            ],
            'sectors' => $canAccessModule ? Sector::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Sector $sector): array => ['id' => $sector->id, 'name' => $sector->name])
                ->values()
                ->all() : [],
            'users' => $canAccessModule ? User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'first_name', 'last_name', 'sector_id', 'mobile_phone', 'directory_phones'])
                ->map(fn (User $candidate): array => [
                    'id' => $candidate->id,
                    'name' => $this->userLabel($candidate),
                    'sector_id' => $candidate->sector_id,
                    'mobile_phone' => $candidate->mobile_phone,
                    'directory_phones' => is_array($candidate->directory_phones) ? $candidate->directory_phones : [],
                ])
                ->values()
                ->all() : [],
            'recipientGroups' => $canAccessModule ? AnnouncementRecipientGroup::query()
                ->when(! $canManage, fn ($query) => $query->where('created_by_user_id', $user?->id))
                ->orderBy('name')
                ->get()
                ->map(fn (AnnouncementRecipientGroup $group): array => $this->presentGroup($group))
                ->values()
                ->all() : [],
            'announcements' => $announcements,
            'openDraft' => $openDraft,
            'highlightId' => $highlightId,
            'highlightAnnouncement' => $highlightAnnouncement,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);

        $validated = $this->validateAnnouncement($request);
        $announcement = $this->persistAnnouncement($validated, $user->id);

        if ($validated['mode'] === 'send_now') {
            $this->dispatchAnnouncement($announcement);
        }

        $this->logAction($announcement, $validated['mode'] === 'draft' ? 'create_draft' : ($validated['mode'] === 'schedule' ? 'schedule_announcement' : 'send_announcement'));

        return back()->with('success', $this->successMessage($validated['mode']));
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);
        $this->authorizeOwnership($user, $announcement);

        if ($announcement->status === Announcement::STATUS_SENT) {
            throw ValidationException::withMessages([
                'body_html' => 'Cette annonce a déjà été envoyée et ne peut plus être modifiée.',
            ]);
        }

        $validated = $this->validateAnnouncement($request);
        $announcement = $this->persistAnnouncement($validated, $user->id, $announcement);

        if ($validated['mode'] === 'send_now') {
            $this->dispatchAnnouncement($announcement);
        }

        $this->logAction($announcement, 'update_announcement');

        return back()->with('success', $this->successMessage($validated['mode']));
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);
        $this->authorizeOwnership($user, $announcement);

        $snapshot = $this->presentAnnouncement($announcement);
        $announcement->delete();

        $this->auditLogService->log([
            'action' => 'delete_announcement',
            'module' => 'annonces',
            'description' => sprintf('Suppression de l\'annonce #%d', $snapshot['id']),
            'payload' => ['announcement' => $snapshot],
        ]);

        return back()->with('success', 'Annonce supprimée.');
    }

    public function duplicate(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);
        $this->authorizeOwnership($user, $announcement);

        $announcement->loadMissing('poll.options');

        if ($announcement->status !== Announcement::STATUS_SENT) {
            return redirect()->route('annonces.index', ['open_draft' => $announcement->id])
                ->with('success', 'Annonce ouverte.');
        }

        $draft = DB::transaction(function () use ($announcement, $user): Announcement {
            $draft = Announcement::create([
                'created_by_user_id' => $user->id,
                'title' => $announcement->title,
                'body_html' => $announcement->body_html,
                'status' => Announcement::STATUS_DRAFT,
                'sector_ids' => $announcement->sector_ids ?? [],
                'user_ids' => $announcement->user_ids ?? [],
                'excluded_user_ids' => $announcement->excluded_user_ids ?? [],
                'scheduled_at' => null,
                'sent_at' => null,
            ]);

            if ($announcement->poll) {
                $poll = $draft->poll()->create([
                    'poll_type' => $announcement->poll->poll_type,
                    'title' => $announcement->poll->title,
                    'allow_other' => $announcement->poll->allow_other,
                    'other_label' => $announcement->poll->other_label,
                ]);

                foreach ($announcement->poll->options as $option) {
                    $poll->options()->create([
                        'label' => $option->label,
                        'sort_order' => $option->sort_order,
                    ]);
                }
            }

            return $draft;
        });

        $this->logAction($draft, 'duplicate_announcement');

        return redirect()->route('annonces.index', ['open_draft' => $draft->id])
            ->with('success', 'Annonce reprise sous forme de brouillon.');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);

        $validated = $this->validateGroup($request);

        AnnouncementRecipientGroup::create([
            ...$validated,
            'created_by_user_id' => $user->id,
        ]);

        return back()->with('success', 'Groupe de destinataires enregistré.');
    }

    public function updateGroup(Request $request, AnnouncementRecipientGroup $group): RedirectResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);

        if (! $access->can($user, 'annonces.manage') && (int) $group->created_by_user_id !== (int) $user->id) {
            abort(403);
        }

        $group->update($this->validateGroup($request));

        return back()->with('success', 'Groupe de destinataires mis à jour.');
    }

    public function destroyGroup(Request $request, AnnouncementRecipientGroup $group): RedirectResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);

        if (! $access->can($user, 'annonces.manage') && (int) $group->created_by_user_id !== (int) $user->id) {
            abort(403);
        }

        $group->delete();

        return back()->with('success', 'Groupe de destinataires supprimé.');
    }

    public function markViewed(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        AnnouncementView::query()->firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['viewed_at' => now()],
        );

        return response()->json(['ok' => true]);
    }

    public function respondPoll(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $announcement->loadMissing(['poll.options']);
        abort_unless($announcement->poll && $announcement->status === Announcement::STATUS_SENT, 404);
        abort_unless($this->isAnnouncementRecipient($announcement, $user), 403);

        $validated = $request->validate([
            'selected_option_ids' => ['nullable', 'array'],
            'selected_option_ids.*' => ['integer'],
            'other_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $payload = $this->buildPollResponsePayload($announcement, $validated);

        AnnouncementPollResponse::query()->updateOrCreate(
            [
                'announcement_poll_id' => $announcement->poll->id,
                'user_id' => $user->id,
            ],
            array_merge($payload, [
                'announcement_id' => $announcement->id,
                'responded_at' => now(),
            ]),
        );

        return back()->with('success', 'Votre réponse a été enregistrée.');
    }

    /**
     * Permet à un administrateur ou au créateur de l'annonce d'enregistrer
     * ou de modifier la réponse d'un destinataire à sa place — même table,
     * même contrainte d'unicité et même logique de validation que
     * respondPoll(), la seule différence étant l'identité du répondant ciblé
     * et l'autorisation requise (authorizeOwnership : admin ou créateur,
     * jamais le destinataire lui-même via cette route).
     */
    public function respondPollFor(Request $request, Announcement $announcement, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        $announcement->loadMissing(['poll.options']);
        abort_unless($announcement->poll && $announcement->status === Announcement::STATUS_SENT, 404);

        // Admin (annonces.manage) ou créateur de CETTE annonce uniquement —
        // un créateur ne peut pas agir sur le sondage d'un autre créateur.
        $this->authorizeOwnership($actor, $announcement);

        if (! $this->isAnnouncementRecipient($announcement, $user)) {
            throw ValidationException::withMessages([
                'selected_option_ids' => 'Cette personne ne fait pas partie des destinataires du sondage.',
            ]);
        }

        $validated = $request->validate([
            'selected_option_ids' => ['nullable', 'array'],
            'selected_option_ids.*' => ['integer'],
            'other_text' => ['nullable', 'string', 'max:1000'],
            // Instantané de la réponse tel que vu par le modal à son
            // ouverture, comparé au contenu réel sous verrou ci-dessous
            // (concurrence optimiste basée sur le contenu, pas sur une
            // horloge : un timestamp() en secondes ne distinguerait pas deux
            // écritures survenues dans la même seconde).
            'expected_exists' => ['required', 'boolean'],
            'expected_selected_option_ids' => ['nullable', 'array'],
            'expected_selected_option_ids.*' => ['integer'],
            'expected_other_text' => ['nullable', 'string'],
        ]);

        $payload = $this->buildPollResponsePayload($announcement, $validated);

        DB::transaction(function () use ($announcement, $user, $actor, $validated, $payload): void {
            $existing = AnnouncementPollResponse::query()
                ->where('announcement_poll_id', $announcement->poll->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $this->matchesExpectedPollResponse($existing, $validated)) {
                throw ValidationException::withMessages([
                    'selected_option_ids' => 'La réponse de cette personne a été modifiée entretemps. Rechargez avant de continuer.',
                ]);
            }

            $before = $existing ? [
                'selected_option_ids' => $existing->selected_option_ids,
                'other_text' => $existing->other_text,
            ] : null;

            AnnouncementPollResponse::query()->updateOrCreate(
                [
                    'announcement_poll_id' => $announcement->poll->id,
                    'user_id' => $user->id,
                ],
                array_merge($payload, [
                    'announcement_id' => $announcement->id,
                    'responded_at' => now(),
                ]),
            );

            $this->auditLogService->log([
                'action' => $existing ? 'update_poll_response_on_behalf' : 'create_poll_response_on_behalf',
                'module' => 'annonces',
                'description' => sprintf(
                    '%s de la réponse au sondage de %s par %s',
                    $existing ? 'Modification' : 'Création',
                    $this->userLabel($user),
                    $this->userLabel($actor),
                ),
                'payload' => [
                    'announcement_id' => $announcement->id,
                    'announcement_poll_id' => $announcement->poll->id,
                    'target_user_id' => $user->id,
                    'target_user_label' => $this->userLabel($user),
                    'before' => $before,
                    'after' => $payload,
                ],
            ]);
        });

        return back()->with('success', 'Réponse enregistrée pour '.$this->userLabel($user).'.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{selected_option_ids: array<int, int>, other_text: string|null}
     */
    private function buildPollResponsePayload(Announcement $announcement, array $validated): array
    {
        $availableOptionIds = $announcement->poll->options->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $selectedOptionIds = array_values(array_intersect(
            array_values(array_unique(array_map('intval', $validated['selected_option_ids'] ?? []))),
            $availableOptionIds,
        ));

        if ($announcement->poll->poll_type === AnnouncementPoll::TYPE_SINGLE && count($selectedOptionIds) > 1) {
            throw ValidationException::withMessages([
                'selected_option_ids' => 'Sélectionnez une seule réponse.',
            ]);
        }

        $otherText = trim((string) ($validated['other_text'] ?? ''));
        if (! $announcement->poll->allow_other) {
            $otherText = '';
        }

        if ($announcement->poll->poll_type === AnnouncementPoll::TYPE_SINGLE && $otherText !== '' && $selectedOptionIds !== []) {
            throw ValidationException::withMessages([
                'selected_option_ids' => 'Sélectionnez une seule réponse.',
            ]);
        }

        if ($selectedOptionIds === [] && $otherText === '') {
            throw ValidationException::withMessages([
                'selected_option_ids' => 'Sélectionnez au moins une réponse.',
            ]);
        }

        return [
            'selected_option_ids' => $selectedOptionIds,
            'other_text' => $otherText !== '' ? $otherText : null,
        ];
    }

    /**
     * Concurrence optimiste basée sur le contenu réel de la réponse (plutôt
     * que sur un horodatage) : compare ce que le modal affichait à son
     * ouverture (expected_*) à l'état actuel, lu sous verrou. Un écart —
     * réponse créée, modifiée ou supprimée entretemps par quelqu'un d'autre —
     * bloque l'écriture au lieu de l'écraser silencieusement.
     *
     * @param  array<string, mixed>  $validated
     */
    private function matchesExpectedPollResponse(?AnnouncementPollResponse $existing, array $validated): bool
    {
        $expectedExists = (bool) ($validated['expected_exists'] ?? false);
        if ($expectedExists !== ($existing !== null)) {
            return false;
        }

        if ($existing === null) {
            return true;
        }

        $expectedSelected = array_values(array_unique(array_map('intval', $validated['expected_selected_option_ids'] ?? [])));
        sort($expectedSelected);
        $currentSelected = array_values(array_unique(array_map('intval', $existing->selected_option_ids ?? [])));
        sort($currentSelected);

        $expectedOtherText = trim((string) ($validated['expected_other_text'] ?? ''));
        $currentOtherText = trim((string) ($existing->other_text ?? ''));

        return $expectedSelected === $currentSelected && $expectedOtherText === $currentOtherText;
    }

    public function updateDashboard(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);
        $this->authorizeOwnership($user, $announcement);

        $validated = $request->validate([
            'show_on_dashboard' => ['required', 'boolean'],
            'dashboard_expires_at' => ['nullable', 'date'],
        ]);

        $show = (bool) $validated['show_on_dashboard'];
        $expiresAt = ! empty($validated['dashboard_expires_at'])
            ? Carbon::parse($validated['dashboard_expires_at'])->toDateString()
            : null;

        if ($show) {
            Announcement::query()
                ->whereKeyNot($announcement->id)
                ->where('show_on_dashboard', true)
                ->update(['show_on_dashboard' => false, 'dashboard_expires_at' => null]);
        }

        $announcement->update([
            'show_on_dashboard' => $show,
            'dashboard_expires_at' => $show ? $expiresAt : null,
        ]);

        $this->logAction($announcement, 'update_dashboard_visibility');

        $message = $show ? 'Annonce affichée sur l\'accueil.' : 'Annonce retirée de l\'accueil.';

        return back()->with('success', $message);
    }

    public function sendPush(Request $request, Announcement $announcement): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $access = app(AccessManager::class);
        abort_unless($user && $access->can($user, 'annonces.create'), 403);

        $recipients = $this->recipientResolver->resolve(
            $announcement->sector_ids,
            $announcement->user_ids,
            $announcement->excluded_user_ids,
        );

        if ($recipients->isEmpty()) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => true, 'sent' => 0]);
            }

            return back();
        }

        $title = filled($announcement->title) ? $announcement->title : 'Nouvelle annonce';
        $rawBody = $announcement->body_html
            ? SimpleHtmlSanitizer::toPlainText($announcement->body_html)
            : '';
        $body = filled($rawBody) ? mb_substr($rawBody, 0, 120) : $title;

        $recipientIds = $recipients->pluck('id')->values()->all();

        $notifByUser = DatabaseNotification::query()
            ->whereIn('notifiable_id', $recipientIds)
            ->where('notifiable_type', User::class)
            ->where('data->type', 'announcement')
            ->where('data->announcement_id', $announcement->id)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('notifiable_id')
            ->map(fn ($group) => (string) $group->first()->id);

        $sentCount = 0;
        foreach ($recipients as $recipient) {
            try {
                SendWebPushNotificationJob::dispatch($recipient->id, [
                    'title' => $title,
                    'body' => $body,
                    'icon' => '/pwa-192.png',
                    'url' => route('annonces.index', ['highlight' => $announcement->id]),
                    'resourceType' => 'announcement',
                    'resourceId' => (int) $announcement->id,
                    'notificationId' => $notifByUser->get((string) $recipient->id),
                ]);
                $sentCount++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('sendPush dispatch failed', [
                    'user_id' => $recipient->id,
                    'announcement_id' => $announcement->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'sent' => $sentCount]);
        }

        return back()->with('success', 'Notification Push envoyée à '.$sentCount.' destinataire(s).');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAnnouncement(Request $request): array
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['draft', 'send_now', 'schedule'])],
            'title' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:20000'],
            'sector_ids' => ['nullable', 'array'],
            'sector_ids.*' => ['integer', 'exists:sectors,id'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'excluded_user_ids' => ['nullable', 'array'],
            'excluded_user_ids.*' => ['integer', 'exists:users,id'],
            'scheduled_at' => ['nullable', 'date'],
            'show_on_dashboard' => ['nullable', 'boolean'],
            'dashboard_expires_at' => ['nullable', 'date'],
            'has_poll' => ['nullable', 'boolean'],
            'poll' => ['nullable', 'array'],
            'poll.poll_type' => ['required_if:has_poll,true', Rule::in(['single', 'multiple'])],
            'poll.title' => ['nullable', 'string', 'max:255'],
            'poll.allow_other' => ['nullable', 'boolean'],
            'poll.other_label' => ['nullable', 'string', 'max:255'],
            'poll.options' => ['required_if:has_poll,true', 'array', 'min:1'],
            'poll.options.*' => ['string', 'max:255'],
        ]);

        $mode = $validated['mode'];
        $bodyText = SimpleHtmlSanitizer::toPlainText($validated['body_html'] ?? '');
        $sectorIds = array_values(array_unique(array_map('intval', $validated['sector_ids'] ?? [])));
        $userIds = array_values(array_unique(array_map('intval', $validated['user_ids'] ?? [])));
        $excludedUserIds = array_values(array_unique(array_map('intval', $validated['excluded_user_ids'] ?? [])));
        $hasPoll = (bool) ($validated['has_poll'] ?? false);

        if ($mode !== 'draft') {
            if ($bodyText === '') {
                throw ValidationException::withMessages([
                    'body_html' => 'Le message de l\'annonce est obligatoire.',
                ]);
            }

            if ($sectorIds === [] && $userIds === []) {
                throw ValidationException::withMessages([
                    'sector_ids' => 'Sélectionnez au moins un secteur ou un utilisateur destinataire.',
                ]);
            }
        }

        $scheduledAt = ! empty($validated['scheduled_at'])
            ? Carbon::parse($validated['scheduled_at'])
            : null;

        if ($mode === 'schedule' && ! $scheduledAt) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Choisissez une date et une heure d\'envoi.',
            ]);
        }

        if ($mode === 'schedule' && $scheduledAt && $scheduledAt->lte(now())) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'La date programmée doit être dans le futur.',
            ]);
        }

        $pollOptions = $hasPoll
            ? array_values(array_filter(array_map(static fn ($label): string => trim((string) $label), $validated['poll']['options'] ?? [])))
            : [];
        $pollAllowOther = (bool) ($validated['poll']['allow_other'] ?? false);
        $pollOtherLabel = trim((string) ($validated['poll']['other_label'] ?? ''));
        if ($pollAllowOther && $pollOtherLabel === '') {
            $pollOtherLabel = 'Autre';
        }

        if ($hasPoll && $pollOptions === []) {
            throw ValidationException::withMessages([
                'poll.options' => 'Ajoutez au moins une réponse possible au sondage.',
            ]);
        }

        $showOnDashboard = (bool) ($validated['show_on_dashboard'] ?? false);
        $dashboardExpiresAt = ! empty($validated['dashboard_expires_at'])
            ? Carbon::parse($validated['dashboard_expires_at'])->toDateString()
            : null;

        return [
            'mode' => $mode,
            'title' => trim((string) ($validated['title'] ?? '')),
            'body_html' => SimpleHtmlSanitizer::sanitize($validated['body_html'] ?? ''),
            'sector_ids' => $sectorIds,
            'user_ids' => $userIds,
            'excluded_user_ids' => $excludedUserIds,
            'scheduled_at' => $mode === 'schedule' ? $scheduledAt : null,
            'show_on_dashboard' => $showOnDashboard,
            'dashboard_expires_at' => $showOnDashboard ? $dashboardExpiresAt : null,
            'has_poll' => $hasPoll,
            'poll_type' => $validated['poll']['poll_type'] ?? AnnouncementPoll::TYPE_SINGLE,
            'poll_title' => $hasPoll ? trim((string) ($validated['poll']['title'] ?? '')) : null,
            'poll_allow_other' => $pollAllowOther,
            'poll_other_label' => $pollAllowOther ? $pollOtherLabel : null,
            'poll_options' => $pollOptions,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistAnnouncement(array $validated, int $actorId, ?Announcement $announcement = null): Announcement
    {
        return DB::transaction(function () use ($validated, $actorId, $announcement): Announcement {
            $status = match ($validated['mode']) {
                'send_now' => Announcement::STATUS_SENT,
                'schedule' => Announcement::STATUS_SCHEDULED,
                default => Announcement::STATUS_DRAFT,
            };

            if ($validated['show_on_dashboard']) {
                Announcement::query()
                    ->when($announcement, fn ($q) => $q->whereKeyNot($announcement->id))
                    ->where('show_on_dashboard', true)
                    ->update(['show_on_dashboard' => false, 'dashboard_expires_at' => null]);
            }

            $attributes = [
                'created_by_user_id' => $announcement?->created_by_user_id ?? $actorId,
                'title' => $validated['title'] !== '' ? $validated['title'] : null,
                'body_html' => $validated['body_html'],
                'status' => $status,
                'sector_ids' => $validated['sector_ids'],
                'user_ids' => $validated['user_ids'],
                'excluded_user_ids' => $validated['excluded_user_ids'],
                'scheduled_at' => $validated['scheduled_at'],
                'sent_at' => $status === Announcement::STATUS_SENT ? now() : null,
                'show_on_dashboard' => $validated['show_on_dashboard'],
                'dashboard_expires_at' => $validated['dashboard_expires_at'],
            ];

            if ($announcement) {
                $announcement->forceFill($attributes)->save();
            } else {
                $announcement = Announcement::create($attributes);
            }

            $announcement->poll?->options()->delete();
            $announcement->poll?->delete();

            if ($validated['has_poll']) {
                $poll = $announcement->poll()->create([
                    'poll_type' => $validated['poll_type'],
                    'title' => $validated['poll_title'] !== '' ? $validated['poll_title'] : null,
                    'allow_other' => $validated['poll_allow_other'],
                    'other_label' => $validated['poll_other_label'],
                ]);

                foreach ($validated['poll_options'] as $index => $label) {
                    $poll->options()->create([
                        'label' => $label,
                        'sort_order' => $index,
                    ]);
                }
            }

            return $announcement->refresh();
        });
    }

    private function dispatchAnnouncement(Announcement $announcement): void
    {
        $recipients = $this->recipientResolver->resolve(
            $announcement->sector_ids,
            $announcement->user_ids,
            $announcement->excluded_user_ids,
        );

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AnnouncementNotification($announcement));
        }
    }

    private function authorizeOwnership(User $user, Announcement $announcement): void
    {
        $access = app(AccessManager::class);
        if ($access->can($user, 'annonces.manage')) {
            return;
        }

        abort_unless((int) $announcement->created_by_user_id === (int) $user->id, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAnnouncement(Announcement $announcement, ?User $viewer = null, bool $canManage = false): array
    {
        $poll = $this->pollPresenter->present($announcement, $viewer, $canManage);

        $isDashboardActive = (bool) $announcement->show_on_dashboard
            && (
                ! $announcement->dashboard_expires_at
                || $announcement->dashboard_expires_at->gte(now()->startOfDay())
            );

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body_html' => SimpleHtmlSanitizer::render($announcement->body_html),
            'body_text' => SimpleHtmlSanitizer::toPlainText($announcement->body_html),
            'status' => $announcement->status,
            'sector_ids' => $announcement->sector_ids ?? [],
            'user_ids' => $announcement->user_ids ?? [],
            'excluded_user_ids' => $announcement->excluded_user_ids ?? [],
            'scheduled_at' => $announcement->scheduled_at?->toIso8601String(),
            'sent_at' => $announcement->sent_at?->toIso8601String(),
            'created_at' => $announcement->created_at?->toIso8601String(),
            'created_by' => $announcement->creator ? $this->userLabel($announcement->creator) : null,
            'created_by_user_id' => $announcement->created_by_user_id,
            'show_on_dashboard' => (bool) $announcement->show_on_dashboard,
            'dashboard_expires_at' => $announcement->dashboard_expires_at?->toDateString(),
            'is_dashboard_active' => $isDashboardActive,
            'poll' => $poll,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function highlightAnnouncement(?int $announcementId, ?User $user, bool $canManage): ?array
    {
        if (! $announcementId || ! $user) {
            return null;
        }

        $announcement = Announcement::query()
            ->with($this->announcementRelations())
            ->whereKey($announcementId)
            ->first();

        if (! $announcement) {
            return null;
        }

        if ($canManage || (int) $announcement->created_by_user_id === (int) $user->id) {
            return $this->presentAnnouncement($announcement, $user, $canManage);
        }

        $recipients = $this->recipientResolver->resolve(
            $announcement->sector_ids,
            $announcement->user_ids,
            $announcement->excluded_user_ids,
        );

        if (! $recipients->contains(fn (User $recipient): bool => (int) $recipient->id === (int) $user->id)) {
            return null;
        }

        return $this->presentAnnouncement($announcement, $user, $canManage);
    }

    /**
     * @return array<int, string>
     */
    private function announcementRelations(): array
    {
        return [
            'creator:id,name,first_name,last_name',
            'poll.options',
            'poll.responses.user:id,name,first_name,last_name',
        ];
    }

    private function isAnnouncementRecipient(Announcement $announcement, User $user): bool
    {
        $recipients = $this->recipientResolver->resolve(
            $announcement->sector_ids,
            $announcement->user_ids,
            $announcement->excluded_user_ids,
        );

        return $recipients->contains(fn (User $recipient): bool => (int) $recipient->id === (int) $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGroup(AnnouncementRecipientGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'sector_ids' => $group->sector_ids ?? [],
            'user_ids' => $group->user_ids ?? [],
            'excluded_user_ids' => $group->excluded_user_ids ?? [],
            'created_by_user_id' => $group->created_by_user_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGroup(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sector_ids' => ['nullable', 'array'],
            'sector_ids.*' => ['integer', 'exists:sectors,id'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'excluded_user_ids' => ['nullable', 'array'],
            'excluded_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        return [
            'name' => trim($validated['name']),
            'sector_ids' => array_values(array_unique(array_map('intval', $validated['sector_ids'] ?? []))),
            'user_ids' => array_values(array_unique(array_map('intval', $validated['user_ids'] ?? []))),
            'excluded_user_ids' => array_values(array_unique(array_map('intval', $validated['excluded_user_ids'] ?? []))),
        ];
    }

    private function successMessage(string $mode): string
    {
        return match ($mode) {
            'send_now' => 'Annonce envoyée.',
            'schedule' => 'Annonce programmée.',
            default => 'Brouillon enregistré.',
        };
    }

    private function logAction(Announcement $announcement, string $action): void
    {
        $this->auditLogService->log([
            'action' => $action,
            'module' => 'annonces',
            'description' => sprintf('Action "%s" sur l\'annonce #%d', $action, $announcement->id),
            'payload' => $this->presentAnnouncement($announcement),
        ]);
    }

    private function userLabel(User $user): string
    {
        $fullName = trim((string) $user->first_name.' '.(string) $user->last_name);
        if ($fullName !== '') {
            return $fullName;
        }

        $name = trim((string) $user->name);

        return $name !== '' ? $name : (string) $user->email;
    }
}

<?php

namespace App\Services\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementPoll;
use App\Models\AnnouncementPollResponse;
use App\Models\User;

/**
 * Construit la représentation JSON d'un sondage d'annonce, réutilisée par
 * la page Annonces, le widget d'accueil et le centre de notifications afin
 * que tous les affichages restent cohérents.
 */
class AnnouncementPollPresenter
{
    public function __construct(
        private readonly AnnouncementRecipientResolver $recipientResolver,
    ) {
    }

    /**
     * Représentation complète : réponse du destinataire, capacité à
     * répondre, et résultats si le viewer est autorisé à les consulter.
     *
     * @return array<string, mixed>|null
     */
    public function present(Announcement $announcement, ?User $viewer, bool $canManage = false): ?array
    {
        $poll = $announcement->poll;
        if (! $poll) {
            return null;
        }

        $canViewResults = $viewer && ($canManage || (int) $announcement->created_by_user_id === (int) $viewer->id);
        $canRespond = $viewer
            && $announcement->status === Announcement::STATUS_SENT
            && $this->isRecipient($announcement, $viewer);

        $data = $this->basic($poll);
        $data['current_response'] = $this->currentResponse($poll, $viewer);
        $data['can_respond'] = (bool) $canRespond;

        if ($canViewResults) {
            $data['results'] = $this->results($announcement, $poll);
        }

        return $data;
    }

    /**
     * Représentation légère (métadonnées uniquement), sans résolution des
     * destinataires — utilisée là où seul l'affichage informatif du
     * sondage est nécessaire (ex. centre de notifications).
     *
     * @return array<string, mixed>
     */
    public function basic(AnnouncementPoll $poll): array
    {
        return [
            'id' => $poll->id,
            'poll_type' => $poll->poll_type,
            'title' => $poll->title,
            'allow_other' => (bool) $poll->allow_other,
            'other_label' => $poll->other_label ?: 'Autre',
            'options' => $poll->options
                ->map(fn ($option): array => [
                    'id' => $option->id,
                    'label' => $option->label,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentResponse(AnnouncementPoll $poll, ?User $viewer): ?array
    {
        if (! $viewer) {
            return null;
        }

        $response = $poll->responses
            ->first(fn (AnnouncementPollResponse $candidate): bool => (int) $candidate->user_id === (int) $viewer->id);

        if (! $response) {
            return null;
        }

        return [
            'selected_option_ids' => array_values(array_map('intval', $response->selected_option_ids ?? [])),
            'other_text' => $response->other_text,
            'responded_at' => $response->responded_at?->toIso8601String(),
        ];
    }

    private function isRecipient(Announcement $announcement, User $user): bool
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
    private function results(Announcement $announcement, AnnouncementPoll $poll): array
    {
        $recipients = $this->recipientResolver->resolve(
            $announcement->sector_ids,
            $announcement->user_ids,
            $announcement->excluded_user_ids,
        );
        $recipientIds = $recipients->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $recipientCount = count($recipientIds);

        $responses = $poll->responses
            ->filter(fn (AnnouncementPollResponse $response): bool => in_array((int) $response->user_id, $recipientIds, true))
            ->values();
        $responseCount = $responses->count();
        $respondedUserIds = $responses->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $recipientsById = $recipients->keyBy('id');

        $options = $poll->options->map(function ($option) use ($responses, $responseCount): array {
            $optionResponses = $responses->filter(function (AnnouncementPollResponse $response) use ($option): bool {
                return in_array((int) $option->id, array_map('intval', $response->selected_option_ids ?? []), true);
            })->values();
            $votes = $optionResponses->count();

            return [
                'id' => $option->id,
                'label' => $option->label,
                'votes' => $votes,
                'percentage' => $responseCount > 0 ? round(($votes / $responseCount) * 100, 1) : 0,
                'respondents' => $optionResponses
                    ->map(fn (AnnouncementPollResponse $response): array => [
                        'id' => $response->user_id,
                        'name' => $this->userLabel($response->user),
                    ])
                    ->values()
                    ->all(),
            ];
        })->values()->all();

        return [
            'recipient_count' => $recipientCount,
            'response_count' => $responseCount,
            'other_label' => $poll->other_label ?: 'Autre',
            'options' => $options,
            'other_responses' => $responses
                ->filter(fn (AnnouncementPollResponse $response): bool => trim((string) $response->other_text) !== '')
                ->map(fn (AnnouncementPollResponse $response): array => [
                    'user_id' => $response->user_id,
                    'user_name' => $this->userLabel($response->user),
                    'text' => $response->other_text,
                    'responded_at' => $response->responded_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'responded_users' => $responses
                ->map(fn (AnnouncementPollResponse $response): array => [
                    'id' => $response->user_id,
                    'name' => $this->userLabel($response->user),
                    'responded_at' => $response->responded_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'pending_users' => $recipientsById
                ->reject(fn (User $recipient): bool => in_array((int) $recipient->id, $respondedUserIds, true))
                ->map(fn (User $recipient): array => [
                    'id' => $recipient->id,
                    'name' => $this->userLabel($recipient),
                ])
                ->values()
                ->all(),
        ];
    }

    private function userLabel(?User $user): string
    {
        if (! $user) {
            return 'Utilisateur';
        }

        $fullName = trim((string) $user->first_name.' '.(string) $user->last_name);
        if ($fullName !== '') {
            return $fullName;
        }

        $name = trim((string) $user->name);

        return $name !== '' ? $name : (string) $user->email;
    }
}

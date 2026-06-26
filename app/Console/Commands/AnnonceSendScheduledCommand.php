<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Notifications\AnnouncementNotification;
use App\Services\Announcements\AnnouncementRecipientResolver;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class AnnonceSendScheduledCommand extends Command
{
    protected $signature = 'annonces:send-scheduled';

    protected $description = 'Envoie les annonces programmées dont la date/heure d\'envoi est atteinte.';

    public function handle(AnnouncementRecipientResolver $recipientResolver, AuditLogService $auditLogService): int
    {
        $announcements = Announcement::query()
            ->where('status', Announcement::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($announcements->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($announcements as $announcement) {
            // Résolution des destinataires au moment de l'envoi (composition
            // des secteurs à l'instant T), jamais figée à la création.
            $recipients = $recipientResolver->resolve(
                $announcement->sector_ids,
                $announcement->user_ids,
                $announcement->excluded_user_ids,
            );

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new AnnouncementNotification($announcement));
            }

            $announcement->forceFill([
                'status' => Announcement::STATUS_SENT,
                'sent_at' => now(),
            ])->save();

            $auditLogService->log([
                'action' => 'send_scheduled_announcement',
                'module' => 'annonces',
                'description' => sprintf('Envoi programmé de l\'annonce #%d à %d destinataire(s)', $announcement->id, $recipients->count()),
                'payload' => [
                    'announcement_id' => $announcement->id,
                    'recipient_count' => $recipients->count(),
                ],
            ]);

            $this->info(sprintf('Annonce #%d envoyée à %d destinataire(s).', $announcement->id, $recipients->count()));
        }

        return self::SUCCESS;
    }
}

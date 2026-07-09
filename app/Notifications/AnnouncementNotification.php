<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Support\RichText\SimpleHtmlSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AnnouncementNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Announcement $announcement,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $plainText = SimpleHtmlSanitizer::toPlainText($this->announcement->body_html);
        $excerpt = mb_substr($plainText, 0, 160);
        if (mb_strlen($plainText) > 160) {
            $excerpt .= '…';
        }

        return [
            'type' => 'announcement',
            'announcement_id' => (int) $this->announcement->id,
            'title' => $this->announcement->title ?? null,
            'message' => $excerpt !== '' ? $excerpt : 'Nouvelle annonce.',
            'full_message' => $plainText !== '' ? $plainText : null,
            'has_poll' => $this->announcement->poll()->exists(),
        ];
    }
}

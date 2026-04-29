<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class HoursMissingEntryReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $workDate)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hours_missing_entry_reminder',
            'work_date' => $this->workDate,
            'message' => 'Vous n’avez pas encore saisi vos heures pour aujourd’hui.',
        ];
    }
}

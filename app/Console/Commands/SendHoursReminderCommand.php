<?php

namespace App\Console\Commands;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\HourSheet;
use App\Models\User;
use App\Notifications\HoursMissingEntryReminderNotification;
use App\Services\Hours\ApprovedLeaveDayService;
use App\Support\Access\AccessManager;
use Illuminate\Console\Command;

class SendHoursReminderCommand extends Command
{
    protected $signature = 'hours:send-missing-reminders';

    protected $description = 'Envoie une notification aux utilisateurs qui n\'ont pas encore saisi leurs heures du jour';

    public function handle(AccessManager $accessManager, ApprovedLeaveDayService $approvedLeaveDayService): int
    {
        $today = now(config('app.timezone', 'Europe/Paris'))->toDateString();

        $users = User::query()->select(['id', 'sector_id', 'hours_tracking_starts_at'])->get();
        $sentCount = 0;

        foreach ($users as $user) {
            if (! $accessManager->can($user, 'heures.create')) {
                continue;
            }
            $trackingStartDate = $user->hours_tracking_starts_at?->toDateString()
                ?? (string) config('hours.min_visible_date', '2026-04-27');
            if ($today < $trackingStartDate) {
                continue;
            }

            if ($approvedLeaveDayService->isUserOnApprovedLeaveForDate((int) $user->id, $today)) {
                continue;
            }

            $hasEntryToday = HourSheet::query()
                ->where('user_id', (int) $user->id)
                ->whereDate('work_date', $today)
                ->exists();

            if ($hasEntryToday) {
                continue;
            }

            $alreadyNotifiedToday = $user->notifications()
                ->whereDate('created_at', now()->toDateString())
                ->where('data->type', 'hours_missing_entry_reminder')
                ->where('data->work_date', $today)
                ->exists();

            if ($alreadyNotifiedToday) {
                continue;
            }

            $user->notify(new HoursMissingEntryReminderNotification($today));
            $sentCount++;

            try {
                $dbNotification = $user->notifications()
                    ->where('data->type', 'hours_missing_entry_reminder')
                    ->where('data->work_date', $today)
                    ->latest()
                    ->first();

                SendWebPushNotificationJob::dispatch($user->id, [
                    'title' => 'Heures à renseigner',
                    'body' => 'Vous n\'avez pas encore saisi vos heures pour aujourd\'hui.',
                    'icon' => '/pwa-192.png',
                    'url' => route('hours.index'),
                    'resourceType' => 'hours_reminder',
                    'notificationId' => $dbNotification?->id ? (string) $dbNotification->id : null,
                ]);
            } catch (\Throwable $e) {
                $this->warn(sprintf('Push hours reminder failed for user %d: %s', $user->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('%d rappel(s) heures envoyé(s).', $sentCount));

        return self::SUCCESS;
    }
}

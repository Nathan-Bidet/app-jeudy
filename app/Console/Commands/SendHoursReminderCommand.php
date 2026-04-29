<?php

namespace App\Console\Commands;

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

        $users = User::query()->select(['id', 'sector_id'])->get();
        $sentCount = 0;

        foreach ($users as $user) {
            if (! $accessManager->can($user, 'heures.create')) {
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
        }

        $this->info(sprintf('%d rappel(s) heures envoyé(s).', $sentCount));

        return self::SUCCESS;
    }
}

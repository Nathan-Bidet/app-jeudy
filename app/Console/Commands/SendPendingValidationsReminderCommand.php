<?php

namespace App\Console\Commands;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\PendingValidationsReminderNotification;
use App\Services\Validation\PendingValidationDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rappel hebdomadaire aux valideurs (jeudi 14h, cf. routes/console.php).
 *
 * Un valideur qui n'a plus rien à traiter ne reçoit rien : le service ne
 * renvoie que les utilisateurs ayant au moins un élément en attente.
 *
 * IDEMPOTENT : un valideur déjà rappelé dans la journée n'est pas rappelé une
 * seconde fois, quel que soit le nombre d'exécutions. La garde porte sur la
 * date de la notification déjà envoyée, et non sur un verrou : le rappel du
 * jeudi suivant part donc normalement. Le verrou du planificateur
 * (withoutOverlapping + onOneServer) couvre le cas des exécutions simultanées,
 * cette garde couvre celui des exécutions rapprochées.
 */
class SendPendingValidationsReminderCommand extends Command
{
    protected $signature = 'validation:send-pending-reminders {--no-push : N\'envoyer que les notifications internes}';

    protected $description = 'Rappelle à chaque valideur les congés et heures qu\'il n\'a pas encore validés';

    public function handle(PendingValidationDigestService $digest): int
    {
        $today = now(config('app.timezone', 'Europe/Paris'))->toDateString();
        $counts = $digest->pendingCountsByValidator();

        Log::info('Rappel de validation : démarrage', [
            'date' => $today,
            'validators_with_pending' => count($counts),
        ]);

        if ($counts === []) {
            $this->info('Aucun valideur n\'a d\'élément en attente.');

            return self::SUCCESS;
        }

        // Les comptes viennent des instantanés figés sur les demandes : un
        // valideur désactivé ou supprimé depuis peut encore y figurer. Une
        // seule requête pour les résoudre tous.
        $validators = User::query()
            ->whereIn('id', array_keys($counts))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $sent = 0;
        $pushed = 0;
        $failed = 0;

        foreach ($counts as $validatorId => $pending) {
            $validator = $validators->get($validatorId);

            if (! $validator) {
                continue;
            }

            try {
                if ($this->alreadyRemindedToday($validator, $today)) {
                    continue;
                }

                $validator->notify(new PendingValidationsReminderNotification(
                    $pending['leaves'],
                    $pending['hours'],
                ));
                $sent++;

                if ($this->sendPush($validator, $pending)) {
                    $pushed++;
                }
            } catch (Throwable $exception) {
                // Un valideur en échec ne doit pas priver les autres de leur
                // rappel : on journalise et on continue.
                $failed++;

                Log::warning('Rappel de validation : envoi impossible', [
                    'user_id' => (int) $validator->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        Log::info('Rappel de validation : terminé', [
            'date' => $today,
            'sent' => $sent,
            'pushed' => $pushed,
            'failed' => $failed,
        ]);

        $this->info(sprintf(
            '%d rappel(s) de validation envoyé(s), dont %d en notification native. %d échec(s).',
            $sent,
            $pushed,
            $failed,
        ));

        return self::SUCCESS;
    }

    private function alreadyRemindedToday(User $validator, string $today): bool
    {
        return $validator->notifications()
            ->where('data->type', PendingValidationsReminderNotification::TYPE)
            ->whereDate('created_at', $today)
            ->exists();
    }

    /**
     * Notification native, UNIQUEMENT si l'utilisateur l'a activée.
     *
     * L'activation se matérialise par un abonnement push enregistré depuis son
     * navigateur, supprimé dès qu'il la désactive. On vérifie donc l'abonnement
     * avant de mettre un job en file : sans cette garde, un job serait
     * programmé puis abandonné pour chaque valideur non abonné.
     *
     * @param  array{leaves:int, hours:int, total:int}  $pending
     */
    private function sendPush(User $validator, array $pending): bool
    {
        if ($this->option('no-push')) {
            return false;
        }

        if (! PushSubscription::query()->where('user_id', $validator->id)->exists()) {
            return false;
        }

        $target = PendingValidationsReminderNotification::target($pending['leaves'], $pending['hours']);

        SendWebPushNotificationJob::dispatch((int) $validator->id, [
            'title' => PendingValidationsReminderNotification::TITLE,
            'body' => PendingValidationsReminderNotification::message($pending['leaves'], $pending['hours']),
            'icon' => '/pwa-192.png',
            'url' => route($target === 'leaves' ? 'leaves.index' : 'hours.index'),
            'resourceType' => 'pending_validations_reminder',
            'action' => 'view',
        ]);

        return true;
    }
}

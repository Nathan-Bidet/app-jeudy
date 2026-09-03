<?php

namespace App\Console\Commands;

use App\Services\Validation\ValidationRolloutService;
use Illuminate\Console\Command;

/**
 * Rattrapage des journées d'heures déjà saisies qui relèvent du nouveau
 * système de validation.
 *
 * Le même traitement est déclenché à l'enregistrement de la date d'effet dans
 * l'administration ; cette commande permet de le rejouer — après correction
 * d'un groupe incomplet, par exemple — ou de le planifier. Elle est idempotente :
 * la relancer ne crée aucun doublon et ne touche à aucune décision déjà prise.
 */
class BackfillHoursValidationCommand extends Command
{
    protected $signature = 'validation:backfill-hours
        {--no-notify : Rattache les journées sans prévenir les valideurs}';

    protected $description = 'Rattache au circuit de validation les journées d\'heures déjà saisies qui entrent dans le périmètre';

    public function handle(ValidationRolloutService $rollout): int
    {
        $effectiveDate = $rollout->effectiveDate();

        $this->info($effectiveDate === null
            ? 'Aucune date d\'effet configurée : toutes les journées non soumises sont concernées.'
            : sprintf('Date d\'effet : %s', $effectiveDate->toDateString()));

        $result = $rollout->backfillHourSheets(! $this->option('no-notify'));

        $this->info(sprintf('%d journée(s) rattachée(s).', $result['attached']));

        if ($result['skipped'] > 0) {
            $this->warn(sprintf('%d journée(s) ignorée(s) :', $result['skipped']));

            foreach ($result['anomalies'] as $anomaly) {
                $this->line(sprintf(
                    '  - %s : %s (%d journée(s))',
                    $anomaly['user_label'],
                    $anomaly['reason'],
                    count($anomaly['work_dates']),
                ));
            }
        }

        return self::SUCCESS;
    }
}

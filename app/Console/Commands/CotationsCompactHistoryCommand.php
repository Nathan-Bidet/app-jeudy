<?php

namespace App\Console\Commands;

use App\Services\Cotations\CotationHistoryCompactor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compactage de l'historique des cotations (cf. routes/console.php).
 *
 * IDEMPOTENTE : une journée déjà compactée ne contient plus qu'un relevé par
 * cotation ; la sélection redésigne ce même relevé et aucune suppression
 * supplémentaire n'a lieu. La commande peut donc être relancée sans risque.
 *
 * REPRENABLE : le traitement avance journée par journée, de la plus ancienne à
 * la plus récente, et chaque journée est finalisée avant de passer à la
 * suivante. Une interruption ne laisse jamais une journée à moitié compactée
 * de façon incohérente : au pire il reste des lignes redondantes que la
 * prochaine exécution supprimera.
 */
class CotationsCompactHistoryCommand extends Command
{
    protected $signature = 'cotations:compact-history
        {--days= : Nombre de jours de granularité complète à conserver (défaut : config)}
        {--until= : Ne pas compacter au-delà de cette date incluse (Y-m-d)}
        {--max-days=0 : Nombre maximal de journées traitées (0 = illimité)}
        {--batch= : Nombre de lignes supprimées par lot}
        {--chunk= : Nombre de lignes lues par lot lors de la sélection}
        {--sleep= : Pause en millisecondes entre deux lots de suppression}
        {--keep-refreshes : Ne pas supprimer les actualisations devenues orphelines}
        {--dry-run : Simule le traitement sans rien supprimer}
        {--show-kept : Détaille les relevés conservés et leur écart à 15 h}';

    protected $description = 'Compacte l\'historique des cotations : un relevé quotidien au plus près de 15 h au-delà de la fenêtre de rétention';

    public function handle(CotationHistoryCompactor $compactor): int
    {
        $startedAt = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $retentionDays = $this->intOption('days') ?? (int) config('cotations.compaction.retention_days', 7);
        $maxDays = max(0, (int) $this->option('max-days'));

        $days = $compactor->eligibleDays(
            CarbonImmutable::now(),
            $retentionDays,
            $this->option('until'),
            $maxDays,
        );

        Log::info('Compactage cotations : démarrage', [
            'dry_run' => $dryRun,
            'retention_days' => $retentionDays,
            'days_to_process' => count($days),
            'first_day' => $days[0] ?? null,
            'last_day' => $days === [] ? null : end($days),
        ]);

        if ($days === []) {
            $this->info('Aucune journée à compacter : tout l\'historique est dans la fenêtre de rétention.');

            return self::SUCCESS;
        }

        $options = [
            'dry_run' => $dryRun,
            'batch_size' => $this->intOption('batch') ?? (int) config('cotations.compaction.batch_size', 2000),
            'chunk_size' => $this->intOption('chunk') ?? (int) config('cotations.compaction.chunk_size', 5000),
            'sleep_ms' => $this->intOption('sleep') ?? (int) config('cotations.compaction.sleep_ms', 0),
            'prune_refreshes' => ! $this->option('keep-refreshes'),
        ];

        $examined = 0;
        $processed = 0;
        $keptTotal = 0;
        $deletedTotal = 0;
        $refreshesTotal = 0;
        $errors = 0;

        foreach ($days as $day) {
            try {
                $result = $compactor->compactDay($day, $options);
            } catch (Throwable $exception) {
                // Une journée en échec ne doit pas bloquer les suivantes : la
                // commande est idempotente, la journée sera retentée au
                // prochain passage.
                $errors++;

                Log::warning('Compactage cotations : journée en échec', [
                    'day' => $day,
                    'error' => $exception->getMessage(),
                ]);

                $this->warn(sprintf('%s : échec — %s', $day, $exception->getMessage()));

                continue;
            }

            $examined++;

            // Les journées sans aucune cotation (arrêt du serveur, trou dans
            // l'historique) sont traversées mais ne comptent pas comme
            // compactées : le rapport resterait sinon illisible.
            if ($result['total'] === 0) {
                continue;
            }

            $processed++;
            $keptTotal += $result['kept'];
            $deletedTotal += $result['deleted'];
            $refreshesTotal += $result['refreshes_deleted'];

            if ($result['total'] > 0 && ($result['deleted'] > 0 || $this->option('show-kept'))) {
                $this->line(sprintf(
                    '%s : %d ligne(s), %d conservée(s), %d %s, %d actualisation(s) %s%s',
                    $day,
                    $result['total'],
                    $result['kept'],
                    $result['deleted'],
                    $dryRun ? 'supprimable(s)' : 'supprimée(s)',
                    $result['refreshes_deleted'],
                    $dryRun ? 'orpheline(s)' : 'supprimée(s)',
                    $result['single_refresh'] ? ' [instantané cohérent]' : ' [relevés issus de plusieurs actualisations]',
                ));
            }

            if ($this->option('show-kept')) {
                $this->renderKeptRows($result);
            }
        }

        $duration = round(microtime(true) - $startedAt, 2);

        $summary = [
            'dry_run' => $dryRun,
            'days_examined' => $examined,
            'days_compacted' => $processed,
            'rows_kept' => $keptTotal,
            'rows_deleted' => $deletedTotal,
            'refreshes_deleted' => $refreshesTotal,
            'errors' => $errors,
            'duration_seconds' => $duration,
        ];

        Log::info('Compactage cotations : terminé', $summary);

        $this->info(sprintf(
            '%s : %d journée(s) sur %d, %d ligne(s) conservée(s), %d ligne(s) %s, %d actualisation(s) %s, %d erreur(s) en %ss.',
            $dryRun ? 'Simulation' : 'Compactage',
            $processed,
            $examined,
            $keptTotal,
            $deletedTotal,
            $dryRun ? 'à supprimer' : 'supprimée(s)',
            $refreshesTotal,
            $dryRun ? 'orpheline(s)' : 'supprimée(s)',
            $errors,
            $duration,
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Mode de contrôle : identifiants conservés et écart à l'heure cible.
     *
     * @param  array<string, mixed>  $result
     */
    private function renderKeptRows(array $result): void
    {
        if ($result['kept_rows'] === []) {
            return;
        }

        $rows = [];

        foreach ($result['kept_rows'] as $row) {
            $rows[] = [
                $row['identity'],
                $row['id'],
                $row['at']->format('H:i:s'),
                sprintf('%s%s', $row['after'] ? '+' : '-', gmdate('H:i:s', $row['distance'])),
                $row['refresh_id'],
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left[0], (string) $right[0]));

        $this->table(['Cotation', 'ID conservé', 'Heure', 'Écart à la cible', 'Actualisation'], $rows);
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return ($value === null || $value === '') ? null : (int) $value;
    }
}

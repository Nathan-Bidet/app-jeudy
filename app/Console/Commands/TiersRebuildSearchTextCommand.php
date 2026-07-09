<?php

namespace App\Console\Commands;

use App\Support\Tiers\TiersSearchText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TiersRebuildSearchTextCommand extends Command
{
    protected $signature = 'tasks:tiers:rebuild-search-text
        {--batch=1000 : Nombre de lignes traitées par lot}
        {--limit= : Nombre maximum de lignes à traiter}
        {--force : Recalculer aussi les lignes déjà remplies}';

    protected $description = 'Reconstruit le champ search_text des données Tiers par lots relançables.';

    public function handle(): int
    {
        $batchSize = max(100, min(5000, (int) $this->option('batch')));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $force = (bool) $this->option('force');
        $processed = 0;
        $startedAt = microtime(true);

        $baseQuery = DB::table('task_tiers_records');
        if (! $force) {
            $baseQuery->whereNull('search_text');
        }

        $total = (clone $baseQuery)->count();
        if ($limit !== null) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $this->info('Aucune ligne Tiers à recalculer.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Recalcul search_text Tiers : %s ligne(s), lots de %s.',
            number_format($total, 0, ',', ' '),
            number_format($batchSize, 0, ',', ' '),
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query = DB::table('task_tiers_records')
            ->select(['id', 'primary_identifier', 'reference_value', 'data'])
            ->orderBy('id');

        if (! $force) {
            $query->whereNull('search_text');
        }

        $query->chunkById($batchSize, function ($records) use (&$processed, $limit, $bar): bool {
            if ($limit !== null && $processed >= $limit) {
                return false;
            }

            $updates = [];
            foreach ($records as $record) {
                if ($limit !== null && $processed >= $limit) {
                    break;
                }

                $data = json_decode((string) ($record->data ?? '{}'), true);
                if (! is_array($data)) {
                    $data = [];
                }

                $updates[(int) $record->id] = TiersSearchText::build(
                    $data,
                    $record->primary_identifier,
                    $record->reference_value,
                );
                $processed++;
            }

            $this->bulkUpdateSearchText($updates);
            $bar->advance(count($updates));

            return true;
        });

        $bar->finish();
        $this->newLine(2);

        $elapsed = max(0.001, microtime(true) - $startedAt);
        $this->info(sprintf(
            'Terminé : %s ligne(s) en %.2f s (%.1f lignes/s).',
            number_format($processed, 0, ',', ' '),
            $elapsed,
            $processed / $elapsed,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $updates
     */
    private function bulkUpdateSearchText(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $cases = [];
        $bindings = [];
        foreach ($updates as $id => $searchText) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $searchText;
        }

        $ids = array_keys($updates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings = [
            ...$bindings,
            ...$ids,
        ];

        DB::update(
            "UPDATE task_tiers_records SET search_text = CASE id ".implode(' ', $cases)." END WHERE id IN ($placeholders)",
            $bindings,
        );
    }
}

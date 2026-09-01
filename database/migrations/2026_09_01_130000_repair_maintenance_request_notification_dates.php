<?php

use App\Models\MaintenanceTask;
use App\Notifications\MaintenanceTaskSummary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les notifications de demande affichaient « pour le - » : leur libellé lisait
 * la date de début, restée vide depuis que le formulaire de demande ne
 * comporte plus que la date souhaitée. Le message étant figé dans le payload
 * à l'écriture, corriger le code ne répare pas celles déjà envoyées.
 *
 * On les reconstruit ici depuis la tâche, quand elle existe encore. Les autres
 * types de notifications ne sont pas touchés.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('notifications')
            ->where('data', 'like', '%maintenance_request_submitted%')
            ->get(['id', 'data']);

        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true);

            if (! is_array($data) || ($data['type'] ?? null) !== 'maintenance_request_submitted') {
                continue;
            }

            $task = MaintenanceTask::query()->find($data['maintenance_task_id'] ?? null);
            $date = MaintenanceTaskSummary::dateLabel($task);

            if ($task === null || $date === null) {
                continue;
            }

            $label = trim((string) ($data['requester_label'] ?? ''));
            $excerpt = MaintenanceTaskSummary::excerpt($task->task);
            $message = sprintf('Demande de %s pour le %s : %s', $label, $date, $excerpt);

            if (($data['message'] ?? null) === $message) {
                continue;
            }

            $data['message'] = $message;

            DB::table('notifications')
                ->where('id', $row->id)
                ->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        // Rien à défaire : on ne réintroduit pas un libellé erroné.
    }
};

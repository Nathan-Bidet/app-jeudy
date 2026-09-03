<?php

use App\Support\Validation\ValidationStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passage de la double validation séquentielle à la double validation
 * parallèle.
 *
 * Jusqu'ici, l'étape en cours était portée par le STATUT (`pending` = attente
 * du Valideur 1, `pending_validator_2` = attente du Valideur 2), ce qui
 * imposait un ordre : tant que le statut valait `pending`, le second valideur
 * n'était à aucun moment le « valideur courant ».
 *
 * Désormais chaque rang porte sa propre décision, et le statut n'exprime plus
 * qu'une issue globale. C'est ce qui rend les deux valideurs indépendants.
 *
 * Reprise des données existantes, sans perte :
 *
 *   - `pending_validator_2` signifiait « rang 1 accordé, rang 2 en attente ».
 *     C'est exactement ce qu'expriment `validator_1_decision = approved` et
 *     `validator_2_decision = null`, avec un statut global `pending`. Ces
 *     demandes restent donc en attente du même valideur qu'avant.
 *   - Une demande `approved` avait reçu tous les accords attendus : chaque rang
 *     ayant une date de décision est marqué comme accordé.
 *   - Une demande `refused` s'est arrêtée sur le rang tranché EN DERNIER — le
 *     circuit séquentiel garantissait que les rangs antérieurs, eux, avaient
 *     accordé. Le rang à la date de décision la plus récente est donc marqué
 *     refusé, les précédents accordés.
 *
 * Les dates et les auteurs des décisions ne sont pas touchés.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['leave_requests', 'hour_sheets'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('validator_1_decision')->nullable()->after('validator_1_id');
                $blueprint->string('validator_2_decision')->nullable()->after('validator_2_id');
            });

            $this->backfillDecisions($table);
        }

        // Le statut ne décrit plus une étape : l'attente du second valideur se
        // lit maintenant dans les décisions individuelles.
        foreach ($this->tables as $table) {
            DB::table($table)
                ->where('status', ValidationStage::LEGACY_PENDING_VALIDATOR_2)
                ->update(['status' => ValidationStage::PENDING]);
        }
    }

    private function backfillDecisions(string $table): void
    {
        // Rangs accordés : tout rang décidé sur une demande qui n'a pas été
        // refusée, plus le rang 1 des demandes arrêtées au second valideur.
        DB::table($table)
            ->whereNull('validator_1_decision')
            ->whereNotNull('validator_1_decided_at')
            ->whereIn('status', [ValidationStage::APPROVED, ValidationStage::LEGACY_PENDING_VALIDATOR_2])
            ->update(['validator_1_decision' => ValidationStage::DECISION_APPROVED]);

        DB::table($table)
            ->whereNull('validator_2_decision')
            ->whereNotNull('validator_2_decided_at')
            ->where('status', ValidationStage::APPROVED)
            ->update(['validator_2_decision' => ValidationStage::DECISION_APPROVED]);

        // Demandes refusées : le refus est le dernier rang tranché.
        DB::table($table)
            ->where('status', ValidationStage::REFUSED)
            ->whereNotNull('validator_2_decided_at')
            ->whereNull('validator_2_decision')
            ->update(['validator_2_decision' => ValidationStage::DECISION_REFUSED]);

        // Sur ces mêmes demandes, un rang 1 décidé AVANT le rang 2 avait donc
        // accordé ; s'il est le seul décidé, c'est lui qui a refusé.
        DB::table($table)
            ->where('status', ValidationStage::REFUSED)
            ->whereNotNull('validator_1_decided_at')
            ->whereNull('validator_1_decision')
            ->whereNotNull('validator_2_decided_at')
            ->update(['validator_1_decision' => ValidationStage::DECISION_APPROVED]);

        DB::table($table)
            ->where('status', ValidationStage::REFUSED)
            ->whereNotNull('validator_1_decided_at')
            ->whereNull('validator_1_decision')
            ->whereNull('validator_2_decided_at')
            ->update(['validator_1_decision' => ValidationStage::DECISION_REFUSED]);
    }

    public function down(): void
    {
        // Retour au séquentiel : une demande dont seul le rang 1 a accordé
        // redevient « en attente du Valideur 2 ».
        foreach ($this->tables as $table) {
            DB::table($table)
                ->where('status', ValidationStage::PENDING)
                ->where('validator_1_decision', ValidationStage::DECISION_APPROVED)
                ->whereNull('validator_2_decision')
                ->whereNotNull('validator_2_id')
                ->update(['status' => ValidationStage::LEGACY_PENDING_VALIDATOR_2]);

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['validator_1_decision', 'validator_2_decision']);
            });
        }
    }
};

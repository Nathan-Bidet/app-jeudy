<?php

use App\Support\Validation\ValidationStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double validation des demandes de congé.
 *
 * Aucun statut existant n'est réécrit : `pending` signifiait déjà « en attente
 * du valideur », il signifie désormais « en attente du Valideur 1 ». Seul
 * `pending_validator_2` est nouveau. Les demandes déjà acceptées ou refusées
 * gardent leur état — une décision passée reste une décision passée.
 *
 * Reprise de l'historique :
 *
 *   - Toute demande, quel que soit son état, reçoit comme Valideur 1 le
 *     valideur qui lui était déjà affecté. C'est une information certaine :
 *     c'est bien cette personne qui devait décider, ou qui a décidé.
 *   - Les demandes déjà tranchées reçoivent en plus la date de décision
 *     (`updated_at`, la meilleure approximation disponible) afin que
 *     l'historique ne présente pas une décision sans date.
 *   - AUCUN Valideur 2 n'est attribué rétroactivement. Une demande acceptée
 *     sous l'ancien circuit l'a été à un seul niveau ; lui inventer un second
 *     valideur laisserait croire qu'une personne a validé ce qu'elle n'a jamais
 *     vu. Les demandes encore en attente restent donc à un seul niveau et se
 *     termineront comme elles ont commencé ; les nouvelles demandes, elles,
 *     partent avec les deux valideurs de leur groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->foreignId('validation_group_id')->nullable()->after('validator_user_id')
                ->constrained('validation_groups')->nullOnDelete();
            $table->string('validation_group_name')->nullable()->after('validation_group_id');

            $table->foreignId('validator_1_id')->nullable()->after('validation_group_name')
                ->constrained('users')->nullOnDelete();
            $table->string('validator_1_label')->nullable()->after('validator_1_id');
            $table->dateTime('validator_1_decided_at')->nullable()->after('validator_1_label');
            $table->foreignId('validator_1_decided_by_id')->nullable()->after('validator_1_decided_at')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('validator_2_id')->nullable()->after('validator_1_decided_by_id')
                ->constrained('users')->nullOnDelete();
            $table->string('validator_2_label')->nullable()->after('validator_2_id');
            $table->dateTime('validator_2_decided_at')->nullable()->after('validator_2_label');
            $table->foreignId('validator_2_decided_by_id')->nullable()->after('validator_2_decided_at')
                ->constrained('users')->nullOnDelete();

            // Niveau depuis lequel une contre-proposition a été émise : c'est
            // lui qui dit où reprendre le circuit quand le demandeur accepte.
            $table->unsignedTinyInteger('proposed_at_level')->nullable()->after('proposed_by_user_id');

            $table->index(['validator_1_id', 'status']);
            $table->index(['validator_2_id', 'status']);
        });

        $this->backfillHistory();
    }

    /**
     * Reprise des demandes antérieures à la double validation.
     */
    private function backfillHistory(): void
    {
        // Le valideur déjà affecté devient le Valideur 1, pour toutes les
        // demandes sans exception.
        DB::table('leave_requests')
            ->whereNull('validator_1_id')
            ->whereNotNull('validator_user_id')
            ->update([
                'validator_1_id' => DB::raw('validator_user_id'),
            ]);

        // Libellé figé du Valideur 1, pour que l'historique reste lisible même
        // si le compte est supprimé plus tard.
        $labelExpression = "TRIM(CONCAT(COALESCE(users.first_name, ''), ' ', COALESCE(users.last_name, '')))";
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $labelExpression = "TRIM(COALESCE(users.first_name, '') || ' ' || COALESCE(users.last_name, ''))";
        }

        DB::table('leave_requests')
            ->whereNull('validator_1_label')
            ->whereNotNull('validator_1_id')
            ->update([
                'validator_1_label' => DB::raw(
                    '(SELECT CASE'
                    ." WHEN {$labelExpression} <> '' THEN {$labelExpression}"
                    .' ELSE COALESCE(users.name, users.email) END'
                    .' FROM users WHERE users.id = leave_requests.validator_1_id)'
                ),
            ]);

        // Une décision déjà prise doit porter une date. `updated_at` est la
        // seule trace disponible sur ces lignes ; elle vaut mieux qu'un vide.
        DB::table('leave_requests')
            ->whereIn('status', [ValidationStage::APPROVED, ValidationStage::REFUSED])
            ->whereNull('validator_1_decided_at')
            ->update([
                'validator_1_decided_at' => DB::raw('updated_at'),
                'validator_1_decided_by_id' => DB::raw('validator_user_id'),
            ]);
    }

    public function down(): void
    {
        // Les demandes arrêtées au second niveau n'ont pas d'équivalent dans
        // l'ancien modèle : on les ramène en attente du premier valideur, seul
        // état antérieur qui les laisse traitables.
        DB::table('leave_requests')
            ->where('status', ValidationStage::PENDING_VALIDATOR_2)
            ->update(['status' => ValidationStage::PENDING_VALIDATOR_1]);

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropIndex(['validator_1_id', 'status']);
            $table->dropIndex(['validator_2_id', 'status']);
            $table->dropConstrainedForeignId('validation_group_id');
            $table->dropConstrainedForeignId('validator_1_id');
            $table->dropConstrainedForeignId('validator_1_decided_by_id');
            $table->dropConstrainedForeignId('validator_2_id');
            $table->dropConstrainedForeignId('validator_2_decided_by_id');
            $table->dropColumn([
                'validation_group_name',
                'validator_1_label',
                'validator_1_decided_at',
                'validator_2_label',
                'validator_2_decided_at',
                'proposed_at_level',
            ]);
        });
    }
};

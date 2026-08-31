<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * created_by_user_id était en ON DELETE CASCADE, par reprise de la convention
 * d'À Prévoir et d'Engrais. Supprimer un compte y effaçait donc toutes les
 * tâches qu'il avait créées.
 *
 * Une tâche de maintenance porte un historique de pointage et une date métier :
 * elle doit survivre au départ de son auteur. On bascule en ON DELETE SET NULL,
 * comme les autres références d'utilisateur de la table. La traçabilité de
 * l'auteur reste assurée par les logs d'audit, qui enregistrent son nom.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La contrainte doit être explicitement retirée puis reposée : un
        // simple change() de colonne conserve l'ancien ON DELETE.
        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
        });

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Le retour en arrière rattacherait les tâches orphelines à personne :
        // on ne restaure la contrainte stricte que si aucune ne l'est.
        if (DB::table('maintenance_tasks')->whereNull('created_by_user_id')->exists()) {
            throw new RuntimeException(
                'Des tâches de maintenance n\'ont plus de créateur : '
                .'renseignez created_by_user_id avant de revenir en arrière.'
            );
        }

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_user_id')->nullable(false)->change();
        });

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};

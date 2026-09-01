<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une demande devient une tâche sur la même ligne : pas de seconde entrée, donc
 * pas de doublon possible et aucun lien à reconstituer. `origin` reste
 * « request » et le demandeur est conservé — la traçabilité de la provenance
 * survit à la conversion. Seul `converted_at` distingue une demande encore en
 * attente d'une tâche issue d'une demande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->dateTime('converted_at')->nullable()->after('requested_by_user_id');
            $table->foreignId('converted_by_user_id')
                ->nullable()
                ->after('converted_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            // Une demande ne porte pas de date de début : c'est la personne qui
            // la transforme en tâche qui la fixe. La colonne devient donc
            // nullable, plutôt que d'y écrire une date inventée.
            $table->date('date')->nullable()->change();
        });

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            // Les demandes en attente se lisent en tête de liste à chaque
            // chargement : l'index sert le tri autant que le filtre.
            $table->index(['origin', 'converted_at', 'date'], 'maintenance_pending_request_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->dropIndex('maintenance_pending_request_idx');
        });

        // Le retour en arrière exige une date sur chaque ligne.
        if (DB::table('maintenance_tasks')->whereNull('date')->exists()) {
            throw new RuntimeException(
                'Des demandes de maintenance n\'ont pas de date de début : '
                .'renseignez-la avant de revenir en arrière.'
            );
        }

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->date('date')->nullable(false)->change();
        });

        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('converted_by_user_id');
            $table->dropColumn('converted_at');
        });
    }
};

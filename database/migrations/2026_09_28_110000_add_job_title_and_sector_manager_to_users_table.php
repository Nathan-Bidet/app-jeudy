<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiches annuaire : « Poste » et « Responsable secteur », jusqu'ici de simples
 * placeholders. Colonnes facultatives, aucune donnée existante modifiée.
 * Le responsable référence un autre utilisateur ; sa suppression remet le
 * champ à vide plutôt que de bloquer ou de supprimer la fiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'job_title')) {
                $table->string('job_title', 120)->nullable()->after('internal_number');
            }

            if (! Schema::hasColumn('users', 'sector_manager_id')) {
                $table->foreignId('sector_manager_id')
                    ->nullable()
                    ->after('sector_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'sector_manager_id')) {
                $table->dropConstrainedForeignId('sector_manager_id');
            }

            if (Schema::hasColumn('users', 'job_title')) {
                $table->dropColumn('job_title');
            }
        });
    }
};

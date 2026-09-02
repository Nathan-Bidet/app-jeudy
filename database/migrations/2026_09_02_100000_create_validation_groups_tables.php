<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groupes de validation partagés par les modules Congés et Heures.
 *
 * Le nommage reste volontairement générique (validation_*) : un groupe ne
 * porte aucune notion propre aux congés, il décrit qui valide pour qui.
 *
 * Les valideurs sont nullables en base — et seulement en base : la validation
 * applicative les exige. Cette souplesse existe pour un seul cas, la
 * suppression d'un compte utilisateur, qui doit vider la référence plutôt que
 * d'emporter le groupe entier (cascade) ou de bloquer la suppression du compte
 * (restrict).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('validator_1_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validator_2_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('validation_group_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('validation_group_id')->constrained('validation_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // La règle « un utilisateur = un seul groupe » est portée par la
            // base : ni un appel API forgé, ni deux enregistrements simultanés
            // ne peuvent la contourner.
            $table->unique('user_id');
            $table->index('validation_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_group_user');
        Schema::dropIfExists('validation_groups');
    }
};

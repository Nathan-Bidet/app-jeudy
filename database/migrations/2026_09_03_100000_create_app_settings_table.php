<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages applicatifs, sous forme clé/valeur.
 *
 * Le projet ne disposait d'aucun magasin de paramètres générique :
 * `cotation_settings` est propre au module Cotations et ne stocke que des
 * décimaux. Cette table accueille les réglages transverses — le premier étant
 * la date d'effet du système de validation — sans qu'aucun d'eux n'ait à
 * exister en dur dans le code ou en double dans le frontend.
 *
 * La valeur est un texte : chaque réglage sait l'interpréter (date, booléen,
 * nombre). `updated_by` garde la trace de qui a modifié quoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};

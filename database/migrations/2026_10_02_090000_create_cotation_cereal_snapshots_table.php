<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cotation_cereal_snapshots')) {
            return;
        }

        Schema::create('cotation_cereal_snapshots', function (Blueprint $table): void {
            $table->id();
            // Jour civil (fuseau métier) de la version : sert à regrouper par journée.
            $table->date('business_date');
            // Instantané COMPLET de la page céréales telle qu'affichée (jamais recomposé).
            $table->json('payload');
            // Empreinte du contenu : évite d'enregistrer deux fois le même état.
            $table->string('payload_hash', 64);
            // 'edit' (sauvegarde des cotations) ou 'daily' (relevé de fin de journée).
            $table->string('source', 16)->default('edit');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Dates disponibles / dernière version d'un jour.
            $table->index(['business_date', 'created_at', 'id'], 'cotation_cereal_snapshots_day_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotation_cereal_snapshots');
    }
};

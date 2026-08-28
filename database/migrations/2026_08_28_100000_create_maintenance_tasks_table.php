<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tasks', function (Blueprint $table): void {
            $table->id();

            // Origine : création directe ou demande. Déterminé côté serveur d'après
            // les permissions de l'auteur, jamais librement depuis le frontend.
            $table->string('origin', 16)->default('creation');
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('date');
            $table->date('fin_date')->nullable();
            $table->date('due_date')->nullable();

            // Personne affectée : utilisateur applicatif, texte libre, ou rien.
            // Les deux colonnes sont mutuellement exclusives (garanti par la validation).
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignee_label_free')->nullable();

            // Lieu : dépôt référencé et/ou adresse libre.
            $table->foreignId('depot_id')->nullable()->constrained('depots')->nullOnDelete();
            $table->text('address_free')->nullable();

            $table->text('task');
            $table->text('comment')->nullable();
            $table->boolean('comment_hidden')->default(false);

            $table->boolean('partially_pointed')->default(false);
            $table->dateTime('partially_pointed_at')->nullable();
            $table->foreignId('partially_pointed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('pointed')->default(false);
            $table->dateTime('pointed_at')->nullable();
            $table->foreignId('pointed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Date métier du tout premier pointage (partiel ou définitif).
            // Renseignée une seule fois, jamais réécrite ni effacée ensuite.
            $table->date('first_pointed_on')->nullable();

            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['pointed', 'date', 'position'], 'maintenance_pointed_date_position_idx');
            $table->index(['date', 'assignee_user_id', 'position'], 'maintenance_group_position_idx');
            $table->index(['depot_id', 'date'], 'maintenance_depot_date_idx');
            $table->index(['origin', 'date'], 'maintenance_origin_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tasks');
    }
};

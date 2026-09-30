<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cotation_mail_attachments')) {
            return;
        }

        Schema::create('cotation_mail_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('draft_id');
            // 'pdf' : export des cotations généré par le serveur ; 'file' : ajout manuel.
            $table->string('kind', 8);
            // Nom affiché et joint au courriel (assaini) ; le fichier est stocké
            // sous un nom aléatoire (stored_name), jamais fourni par le navigateur.
            $table->string('original_name', 255);
            $table->string('stored_name', 64)->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['user_id', 'draft_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotation_mail_attachments');
    }
};

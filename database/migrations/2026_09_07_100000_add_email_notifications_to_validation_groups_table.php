<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinataires email d'un groupe de validation.
 *
 * Deux colonnes plutôt qu'une : le drapeau dit si l'envoi est actif, la liste
 * dit à qui. Les séparer permet de désactiver l'option sans perdre les adresses
 * — les réactiver ne demande alors qu'une case à cocher.
 *
 * Les adresses sont stockées en JSON, pas en chaîne à virgules : c'est une
 * liste, l'application la manipule comme telle (dédoublonnage, comptage,
 * itération), et aucune couche n'a besoin de la ré-analyser.
 *
 * Aucune table dédiée : ces adresses n'ont ni identité, ni cycle de vie propre,
 * ni relation avec autre chose que leur groupe. Une table de plus n'apporterait
 * qu'une jointure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_groups', function (Blueprint $table): void {
            $table->boolean('notify_by_email')->default(false)->after('validator_2_id');
            $table->json('notification_emails')->nullable()->after('notify_by_email');
        });
    }

    public function down(): void
    {
        Schema::table('validation_groups', function (Blueprint $table): void {
            $table->dropColumn(['notify_by_email', 'notification_emails']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Nettoyage de la table `cotation_cereal_snapshots`, créée par une première
 * version de l'historique des céréales (copies JSON de la page) puis
 * abandonnée au profit de l'historique déjà présent dans
 * `cotation_market_prices`.
 *
 * La migration de création ayant déjà été exécutée sur le serveur de
 * développement, la supprimer ne suffit pas : cette migration retire la table
 * restante. Elle ne touche à AUCUNE autre table (surtout pas
 * cotation_market_prices / cotation_market_refreshes) et ne fait rien là où la
 * table n'a jamais existé. Les données qu'elle contenait n'étaient que des
 * copies : rien n'est perdu (la table n'a jamais été utilisée en production).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cotation_cereal_snapshots');
    }

    public function down(): void
    {
        // Volontairement vide : le système de snapshots est abandonné.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index nécessaire au compactage de l'historique des cotations.
 *
 * `cotation_market_prices` n'avait aucun index sur `created_at` : le découpage
 * par journée du compactage (`created_at >= ? AND created_at < ?`) provoquait
 * un balayage complet de 2,7 millions de lignes à chaque journée traitée.
 *
 * L'index composite `(created_at, id)` couvre les trois accès du traitement :
 *  - la borne de journée (préfixe `created_at`) ;
 *  - la pagination par identifiant de la passe de sélection (`ORDER BY id`) ;
 *  - la relecture des identifiants à supprimer, servie directement par l'index.
 *
 * MySQL 8 crée cet index en ALGORITHM=INPLACE / LOCK=NONE : les écritures de
 * `cotations:refresh` (toutes les minutes) continuent pendant la création.
 * Comptez environ 10 à 30 secondes sur 2,7 millions de lignes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotation_market_prices')) {
            return;
        }

        if ($this->hasIndex('cotation_market_prices', 'cot_price_created_idx')) {
            return;
        }

        Schema::table('cotation_market_prices', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'cot_price_created_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cotation_market_prices')) {
            return;
        }

        if (! $this->hasIndex('cotation_market_prices', 'cot_price_created_idx')) {
            return;
        }

        Schema::table('cotation_market_prices', function (Blueprint $table): void {
            $table->dropIndex('cot_price_created_idx');
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return Schema::getConnection()
            ->getSchemaBuilder()
            ->hasIndex($table, $index);
    }
};

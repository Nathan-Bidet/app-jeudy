<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clarification du vocabulaire (renommage pur, aucune valeur recalculée) :
 *   margin           (Base)             → base
 *   margin_operation (signe de la Base) → base_operation
 *   sale_margin      (Marge calculée)   → margin
 *
 * L'ordre compte : « margin » doit être libéré avant d'être réattribué.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename([
            'margin' => 'base',
            'margin_operation' => 'base_operation',
            'sale_margin' => 'margin',
        ]);
    }

    public function down(): void
    {
        $this->rename([
            'margin' => 'sale_margin',
            'base_operation' => 'margin_operation',
            'base' => 'margin',
        ]);
    }

    /**
     * @param  array<string, string>  $renames  ancien nom => nouveau nom, appliqués dans l'ordre
     */
    private function rename(array $renames): void
    {
        if (! Schema::hasTable('cotation_manual_prices')) {
            return;
        }

        foreach ($renames as $from => $to) {
            if (! Schema::hasColumn('cotation_manual_prices', $from) || Schema::hasColumn('cotation_manual_prices', $to)) {
                throw new RuntimeException("Renommage impossible : cotation_manual_prices.{$from} → {$to} (schéma inattendu).");
            }

            Schema::table('cotation_manual_prices', function (Blueprint $table) use ($from, $to): void {
                $table->renameColumn($from, $to);
            });
        }
    }
};

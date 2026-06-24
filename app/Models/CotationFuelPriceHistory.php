<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instantané complet de la grille carburant (fuel_grid_config) à un instant
 * donné. Une ligne est créée à chaque sauvegarde des prix carburant qui
 * modifie réellement la configuration. Lecture uniquement : ne sert jamais
 * de source pour l'affichage courant ni pour l'export PDF.
 */
class CotationFuelPriceHistory extends Model
{
    protected $table = 'cotation_fuel_price_history';

    protected $fillable = [
        'fuel_grid',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'fuel_grid' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

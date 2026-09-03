<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglage applicatif transverse, sous forme clé/valeur.
 *
 * Passer par AppSettings plutôt que par ce modèle directement : le service
 * porte le typage et le cache, le modèle n'est que le stockage.
 */
class AppSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ordre manuel des groupes (chauffeur/transporteur/dépôt) défini par
 * glisser-déposer sur une date donnée, pour les modules À prévoir / Engrais.
 * Le tri par défaut (AprevoirService::compareGroups) reste inchangé tant
 * qu'aucune ligne n'existe pour le couple (module, date).
 */
class TaskGroupOrder extends Model
{
    protected $table = 'task_group_orders';

    protected $fillable = [
        'module',
        'date',
        'group_key',
        'sort_order',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}

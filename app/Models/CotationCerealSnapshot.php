<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version historique complète de la page « Cotations céréales » : prix,
 * produits, ordre, libellés, textes d'information, grille de transport et
 * années de récolte, tels qu'affichés au moment de l'enregistrement.
 * Lecture seule : ne sert jamais de source à l'affichage courant, au PDF ni à
 * l'envoi. Une ligne n'est jamais modifiée après sa création.
 */
class CotationCerealSnapshot extends Model
{
    protected $table = 'cotation_cereal_snapshots';

    protected $fillable = [
        'business_date',
        'payload',
        'payload_hash',
        'source',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'payload' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

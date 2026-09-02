<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Appartenance d'un utilisateur à un groupe de validation.
 *
 * Modèle à part entière (et non simple pivot anonyme) parce que la règle
 * « un seul groupe par utilisateur » se vérifie et se verrouille ligne à ligne
 * lors des affectations concurrentes.
 */
class ValidationGroupUser extends Model
{
    protected $table = 'validation_group_user';

    protected $fillable = [
        'validation_group_id',
        'user_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ValidationGroup::class, 'validation_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

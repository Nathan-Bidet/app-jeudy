<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groupe de validation : un nom, deux valideurs, des membres.
 *
 * Générique par construction — les modules Congés et Heures s'appuient sur le
 * même groupe, aucun champ n'est propre à l'un ou à l'autre.
 */
class ValidationGroup extends Model
{
    /** @use HasFactory<\Database\Factories\ValidationGroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'validator_1_id',
        'validator_2_id',
    ];

    public function validator1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_1_id');
    }

    public function validator2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_2_id');
    }

    /**
     * Membres du groupe. Un utilisateur n'appartient qu'à un seul groupe :
     * l'index unique sur validation_group_user.user_id le garantit.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'validation_group_user')
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ValidationGroupUser::class);
    }
}

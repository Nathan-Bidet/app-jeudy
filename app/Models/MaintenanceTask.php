<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceTask extends Model
{
    public const ORIGIN_CREATION = 'creation';

    public const ORIGIN_REQUEST = 'request';

    /**
     * Les champs de traçabilité (auteur, origine, pointage, position) sont pilotés
     * par le contrôleur et volontairement absents du fillable : ils ne doivent
     * jamais être renseignés par une saisie du frontend.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'date',
        'fin_date',
        'due_date',
        'assignee_user_id',
        'assignee_label_free',
        'depot_id',
        'address_free',
        'task',
        'comment',
        'comment_hidden',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'fin_date' => 'date',
            'due_date' => 'date',
            'first_pointed_on' => 'date',
            'first_pointed_on_manual' => 'boolean',
            'assignee_user_id' => 'integer',
            'depot_id' => 'integer',
            'comment_hidden' => 'boolean',
            'partially_pointed' => 'boolean',
            'partially_pointed_at' => 'datetime',
            'partially_pointed_by_user_id' => 'integer',
            'pointed' => 'boolean',
            'pointed_at' => 'datetime',
            'pointed_by_user_id' => 'integer',
            'position' => 'integer',
            'created_by_user_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function pointedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pointed_by_user_id');
    }

    public function partiallyPointedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partially_pointed_by_user_id');
    }

    public function isRequest(): bool
    {
        return $this->origin === self::ORIGIN_REQUEST;
    }

    /**
     * Type d'affectation dérivé, pour homogénéiser le payload avec les autres
     * modules de tâches sans stocker de colonne redondante.
     */
    public function assigneeType(): string
    {
        if ($this->assignee_user_id !== null) {
            return 'user';
        }

        if (trim((string) $this->assignee_label_free) !== '') {
            return 'free';
        }

        return 'none';
    }

    /**
     * Le pointage partiel appartient à la personne réellement affectée, et à
     * elle seule. Ce n'est pas une permission mais une règle d'identité : elle
     * vit donc dans le domaine, hors du Gate, pour qu'aucun contournement
     * global (y compris le Gate::before administrateur) ne puisse l'annuler.
     *
     * Une tâche affectée à un texte libre ou à personne n'est pointable
     * partiellement par aucun utilisateur.
     */
    public function isPartialPointableBy(?User $user): bool
    {
        if ($user === null || $this->assignee_user_id === null) {
            return false;
        }

        return (int) $this->assignee_user_id === (int) $user->id;
    }

    /**
     * Date métier du premier pointage : posée une seule fois, jamais recalculée.
     * Une valeur fixée à la main par un responsable est définitive.
     */
    public function stampFirstPointingDate(): void
    {
        if ($this->first_pointed_on_manual) {
            return;
        }

        if ($this->first_pointed_on === null) {
            $this->first_pointed_on = now()->toDateString();
        }
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('pointed', false);
    }

    public function scopeForDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('date', '<=', $to);
        }

        return $query;
    }
}

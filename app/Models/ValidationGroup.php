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
        'notify_by_email',
        'notification_emails',
    ];

    protected $casts = [
        'notify_by_email' => 'boolean',
        'notification_emails' => 'array',
    ];

    /**
     * Adresses à prévenir par email lors d'une nouvelle soumission.
     *
     * Renvoie un tableau vide dès que l'option est désactivée, sans regarder la
     * liste : les adresses restent en base pour qu'une réactivation ne demande
     * que de recocher la case, mais elles ne servent à rien tant que le drapeau
     * est baissé. Unique point de lecture — aucun appelant ne teste le drapeau
     * de son côté.
     *
     * @return array<int, string>
     */
    public function emailRecipients(): array
    {
        if (! $this->notify_by_email) {
            return [];
        }

        $emails = is_array($this->notification_emails) ? $this->notification_emails : [];

        return self::normalizeEmails($emails);
    }

    /**
     * Nettoie une liste d'adresses : espaces retirés, entrées vides écartées,
     * doublons supprimés SANS tenir compte de la casse — « RH@x.fr » et
     * « rh@x.fr » désignent la même boîte.
     *
     * La première occurrence est conservée telle qu'elle a été saisie : rien ne
     * justifie de réécrire ce que l'administrateur a tapé.
     *
     * @param  array<int, mixed>  $emails
     * @return array<int, string>
     */
    public static function normalizeEmails(array $emails): array
    {
        $seen = [];
        $normalized = [];

        foreach ($emails as $email) {
            $trimmed = trim((string) $email);

            if ($trimmed === '') {
                continue;
            }

            $key = mb_strtolower($trimmed);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    /**
     * Découpe la saisie du formulaire — une seule ligne, adresses séparées par
     * des virgules — en liste nettoyée.
     *
     * Le point-virgule est accepté en plus de la virgule : c'est ce que produit
     * un copier-coller depuis Outlook, et le refuser n'apprendrait rien à
     * personne.
     *
     * @return array<int, string>
     */
    public static function parseEmailList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return self::normalizeEmails(preg_split('/[,;\r\n]+/', $raw) ?: []);
    }

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

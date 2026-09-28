<?php

namespace App\Support\Validation;

use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Access\AccessManager;

/**
 * Seul point de décision sur l'identité des valideurs dans les écrans de
 * validation, pour les Congés comme pour les Heures.
 *
 * Par défaut, validationSummary() ne dit que « Validé » / « Refusé » /
 * « En attente ». Avec la permission ABILITY — résolue par l'AccessManager :
 * administrateur, puis Deny, puis Allow, puis rôle ou défaut du secteur de
 * l'utilisateur —, chaque rang reçoit en plus le nom du valideur désigné.
 * Sans elle, la clé `validator` est absente : aucun nom ne quitte le serveur.
 */
class ValidatorIdentity
{
    public const ABILITY = 'conges_heures.validators_identity.view';

    /** Colonnes nécessaires à l'affichage, pour le chargement anticipé. */
    private const USER_COLUMNS = 'id,name,first_name,last_name,is_active';

    /**
     * Décision mémorisée pour la durée de vie de CETTE instance, résolue une
     * fois par action de contrôleur : jamais de décision périmée d'une
     * requête à l'autre après un changement de droits.
     *
     * @var array<int, bool>
     */
    private array $decisions = [];

    public function __construct(private readonly AccessManager $accessManager)
    {
    }

    public function visibleTo(?User $viewer): bool
    {
        if (! $viewer) {
            return false;
        }

        return $this->decisions[(int) $viewer->id] ??= $this->accessManager->can($viewer, self::ABILITY);
    }

    /**
     * Relations à précharger (éviter le N+1), vides quand le lecteur ne verra
     * pas les noms : rien d'inutile n'est alors lu en base.
     *
     * @return array<int, string>
     */
    public function eagerLoadsFor(?User $viewer): array
    {
        if (! $this->visibleTo($viewer)) {
            return [];
        }

        return ['validator1:'.self::USER_COLUMNS, 'validator2:'.self::USER_COLUMNS];
    }

    /**
     * validationSummary(), complété du nom de chaque valideur si le lecteur y
     * a droit.
     *
     * @return array<int, array{level:int, decision:?string, label:string, validator?:array{name:string, state:string}}>
     */
    public function summaryFor(LeaveRequest|HourSheet $subject, ?User $viewer): array
    {
        $summary = $subject->validationSummary();

        if (! $this->visibleTo($viewer)) {
            return $summary;
        }

        return array_map(function (array $entry) use ($subject): array {
            $entry['validator'] = $this->identityForLevel($subject, (int) $entry['level']);

            return $entry;
        }, $summary);
    }

    /**
     * Nom composé depuis le compte (prénom + nom, sinon nom d'affichage), avec
     * un état permettant un repli propre :
     *  - active   : compte actif ;
     *  - inactive : compte désactivé depuis la soumission ;
     *  - deleted  : compte supprimé — nom repris du libellé figé à la soumission ;
     *  - unknown  : aucun valideur identifiable.
     *
     * @return array{name:string, state:string}
     */
    private function identityForLevel(LeaveRequest|HourSheet $subject, int $level): array
    {
        $relation = $level === 1 ? 'validator1' : 'validator2';
        $validatorId = $subject->validatorIdForLevel($level);
        $snapshot = trim((string) ($level === 1 ? $subject->validator_1_label : $subject->validator_2_label));
        $user = $validatorId !== null ? $subject->{$relation} : null;

        if ($user instanceof User) {
            return [
                'name' => $this->displayName($user) ?? ($snapshot !== '' ? $snapshot : 'Nom non renseigné'),
                'state' => $user->is_active ? 'active' : 'inactive',
            ];
        }

        // La suppression d'un compte vide la clé étrangère ; le libellé figé à
        // la soumission dit toujours qui était désigné.
        if ($snapshot !== '') {
            return ['name' => $snapshot, 'state' => 'deleted'];
        }

        return ['name' => 'Valideur inconnu', 'state' => 'unknown'];
    }

    private function displayName(User $user): ?string
    {
        $fullName = trim(implode(' ', array_filter([
            trim((string) $user->first_name),
            trim((string) $user->last_name),
        ])));

        if ($fullName !== '') {
            return $fullName;
        }

        $name = trim((string) $user->name);

        return $name !== '' ? $name : null;
    }
}

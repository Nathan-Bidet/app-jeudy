<?php

namespace App\Services\Validation;

use App\Models\User;
use App\Models\ValidationGroup;
use App\Models\ValidationGroupUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Point d'entrée unique des groupes de validation.
 *
 * Écritures : toute opération touchant à la fois le groupe et ses membres est
 * enveloppée dans une transaction. Un échec au milieu d'une modification ne
 * laisse jamais un groupe à moitié modifié.
 *
 * Lectures : les modules Congés et Heures passent par resolveValidators() /
 * groupFor() plutôt que d'interroger les tables directement.
 */
class ValidationGroupService
{
    /**
     * @param  array{name:string,validator_1_id:int,validator_2_id:int,member_user_ids?:array<int,int>}  $attributes
     */
    public function create(array $attributes): ValidationGroup
    {
        return DB::transaction(function () use ($attributes): ValidationGroup {
            $group = ValidationGroup::query()->create([
                'name' => $this->normalizeName($attributes['name']),
                'validator_1_id' => (int) $attributes['validator_1_id'],
                'validator_2_id' => (int) $attributes['validator_2_id'],
            ]);

            $this->syncMembers($group, $attributes['member_user_ids'] ?? []);

            return $group->fresh(['validator1', 'validator2', 'members']);
        });
    }

    /**
     * @param  array{name:string,validator_1_id:int,validator_2_id:int,member_user_ids?:array<int,int>}  $attributes
     */
    public function update(ValidationGroup $group, array $attributes): ValidationGroup
    {
        return DB::transaction(function () use ($group, $attributes): ValidationGroup {
            $group->update([
                'name' => $this->normalizeName($attributes['name']),
                'validator_1_id' => (int) $attributes['validator_1_id'],
                'validator_2_id' => (int) $attributes['validator_2_id'],
            ]);

            $this->syncMembers($group, $attributes['member_user_ids'] ?? []);

            return $group->fresh(['validator1', 'validator2', 'members']);
        });
    }

    /**
     * Supprime le groupe et libère ses membres.
     *
     * Aucun historique n'est perdu : les demandes de congé figent leur valideur
     * sur la demande elle-même (leave_requests.validator_user_id) au moment de
     * la soumission. Un groupe supprimé ne rend donc aucune validation passée
     * illisible — il cesse seulement de servir aux demandes futures.
     */
    public function delete(ValidationGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            // Explicite plutôt que de compter sur le ON DELETE CASCADE :
            // SQLite n'applique les clés étrangères que si elles sont activées.
            $group->memberships()->delete();
            $group->delete();
        });
    }

    /**
     * Remplace la composition du groupe.
     *
     * Les lignes d'appartenance des utilisateurs visés sont verrouillées avant
     * lecture : deux administrateurs qui affectent le même utilisateur en même
     * temps se sérialisent ici, le second reçoit une erreur de validation. Le
     * cas où l'index unique parle avant nous (transactions non sérialisées,
     * requête forgée) est traduit dans la même erreur.
     *
     * @param  array<int, int|string>  $userIds
     */
    public function syncMembers(ValidationGroup $group, array $userIds): void
    {
        $userIds = collect($userIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($group, $userIds): void {
            $conflicting = $this->conflictingMemberships($group, $userIds);

            if ($conflicting !== []) {
                throw ValidationException::withMessages([
                    'member_user_ids' => $this->conflictMessage($conflicting),
                ]);
            }

            $group->memberships()->delete();

            if ($userIds === []) {
                return;
            }

            $now = now();

            try {
                ValidationGroupUser::query()->insert(array_map(
                    static fn (int $userId): array => [
                        'validation_group_id' => (int) $group->id,
                        'user_id' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $userIds,
                ));
            } catch (\Illuminate\Database\QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'member_user_ids' => 'Un des utilisateurs sélectionnés vient d\'être affecté à un autre groupe. Rechargez la page.',
                ]);
            }
        });
    }

    /**
     * Groupe de l'utilisateur, ou null s'il n'est membre d'aucun groupe.
     */
    public function groupFor(User|int|null $user): ?ValidationGroup
    {
        $userId = $user instanceof User ? (int) $user->id : ($user !== null ? (int) $user : 0);

        if ($userId <= 0) {
            return null;
        }

        return ValidationGroup::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $userId))
            ->with(['validator1', 'validator2'])
            ->first();
    }

    /**
     * Valideurs applicables à un utilisateur.
     *
     * Renvoie systématiquement les deux clés, à null quand le groupe n'existe
     * pas ou qu'un compte valideur a été supprimé entre-temps.
     *
     * @return array{validator_1: ?User, validator_2: ?User}
     */
    public function resolveValidators(User|int|null $user): array
    {
        $group = $this->groupFor($user);

        return [
            'validator_1' => $group?->validator1,
            'validator_2' => $group?->validator2,
        ];
    }

    /**
     * Valideur principal (Valideur 1) d'un utilisateur, à défaut le Valideur 2.
     *
     * Le repli sur le Valideur 2 couvre le groupe dont le premier valideur a
     * été supprimé : mieux vaut router la demande vers le second que vers
     * personne.
     */
    public function resolvePrimaryValidator(User|int|null $user): ?User
    {
        $validators = $this->resolveValidators($user);

        return $validators['validator_1'] ?? $validators['validator_2'];
    }

    /**
     * Groupes dont l'utilisateur est valideur, quel que soit le rang.
     *
     * @return \Illuminate\Support\Collection<int, ValidationGroup>
     */
    public function groupsValidatedBy(User|int $user)
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return ValidationGroup::query()
            ->where('validator_1_id', $userId)
            ->orWhere('validator_2_id', $userId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Appartenances des utilisateurs visés qui relèvent d'un autre groupe.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{user_id:int,group_name:string}>
     */
    public function conflictingMemberships(?ValidationGroup $group, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $query = ValidationGroupUser::query()
            ->whereIn('user_id', $userIds);

        if ($group !== null && $group->exists) {
            $query->where('validation_group_id', '!=', (int) $group->id);
        }

        // Verrou pris avant décision : sans lui, deux requêtes concurrentes
        // liraient toutes deux « libre » avant d'écrire. Hors transaction, le
        // verrou n'aurait aucune portée — on ne le demande donc pas.
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $memberships = $query->get();

        if ($memberships->isEmpty()) {
            return [];
        }

        $groupNames = ValidationGroup::query()
            ->whereIn('id', $memberships->pluck('validation_group_id')->unique()->all())
            ->pluck('name', 'id');

        return $memberships
            ->map(fn (ValidationGroupUser $membership): array => [
                'user_id' => (int) $membership->user_id,
                'group_name' => (string) ($groupNames[$membership->validation_group_id] ?? 'un autre groupe'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{user_id:int,group_name:string}>  $conflicts
     */
    private function conflictMessage(array $conflicts): string
    {
        $userIds = array_column($conflicts, 'user_id');

        $labels = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(fn (User $user): array => [
                (int) $user->id => $this->userLabel($user),
            ]);

        $details = collect($conflicts)
            ->map(fn (array $conflict): string => sprintf(
                '%s (déjà dans « %s »)',
                $labels[$conflict['user_id']] ?? ('Utilisateur #'.$conflict['user_id']),
                $conflict['group_name'],
            ))
            ->implode(', ');

        return 'Ces utilisateurs appartiennent déjà à un autre groupe : '.$details.'.';
    }

    private function userLabel(User $user): string
    {
        $fullName = trim(
            collect([$user->first_name, $user->last_name])
                ->filter()
                ->implode(' ')
        );

        return $fullName !== '' ? $fullName : ($user->name ?: $user->email);
    }

    private function normalizeName(string $name): string
    {
        return trim($name);
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        // 23000/23505 : violation de contrainte d'intégrité (MySQL/PostgreSQL),
        // 19/2067 : équivalent SQLite utilisé par la suite de tests.
        return $sqlState === '23000'
            || $sqlState === '23505'
            || in_array($driverCode, ['1062', '19', '2067'], true);
    }
}

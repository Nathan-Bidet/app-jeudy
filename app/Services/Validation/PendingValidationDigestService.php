<?php

namespace App\Services\Validation;

use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Support\Validation\ValidationStage;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Ce qui reste à trancher, par valideur, tous modules confondus.
 *
 * Sert au rappel hebdomadaire. Le service ne décide de rien : il compte
 * exactement ce que les files « Demandes de congés à valider » et « Heures à
 * valider » montreraient à chaque valideur, en une requête par module.
 *
 * ÉQUIVALENCE AVEC LA FILE — le prédicat reproduit terme pour terme celui de
 * HasTwoStepValidation::scopeAwaitingDecisionBy() :
 *
 *   - statut ouvert (les éléments antérieurs au circuit ont un statut NULL et
 *     sont donc naturellement exclus, comme les éléments validés ou refusés) ;
 *   - l'utilisateur est désigné à un rang réellement attendu (le rang 2 n'est
 *     attendu que si un Valideur 2 a été figé à la soumission) ;
 *   - il n'a pas encore rendu SA décision à ce rang.
 *
 * Un test compare, valideur par valideur, le total renvoyé ici à celui de la
 * file : les deux ne peuvent pas diverger sans le faire échouer.
 *
 * UNICITÉ — les deux rangs sont réunis par UNION (et non UNION ALL) sur le
 * couple (identifiant, valideur) : une même personne désignée aux deux rangs
 * d'un même élément ne le compte qu'une fois. Être valideur de plusieurs
 * groupes ne change rien non plus — le compte porte sur les éléments, pas sur
 * les groupes, et aucune jointure de groupe n'intervient.
 */
class PendingValidationDigestService
{
    /**
     * Charge de validation restante, par utilisateur.
     *
     * Les valideurs qui n'ont plus rien à traiter n'apparaissent pas : la
     * commande de rappel n'a donc jamais à filtrer des totaux nuls.
     *
     * @return array<int, array{leaves:int, hours:int, total:int}> indexé par identifiant de valideur
     */
    public function pendingCountsByValidator(): array
    {
        $counts = [];

        foreach ($this->countPendingBy(LeaveRequest::query()->getModel()->getTable()) as $validatorId => $total) {
            $counts[$validatorId] = ['leaves' => $total, 'hours' => 0, 'total' => $total];
        }

        foreach ($this->countPendingBy(HourSheet::query()->getModel()->getTable()) as $validatorId => $total) {
            $counts[$validatorId]['leaves'] ??= 0;
            $counts[$validatorId]['hours'] = $total;
            $counts[$validatorId]['total'] = $counts[$validatorId]['leaves'] + $total;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Charge de validation d'un seul utilisateur.
     *
     * @return array{leaves:int, hours:int, total:int}
     */
    public function pendingCountsFor(int $validatorId): array
    {
        return $this->pendingCountsByValidator()[$validatorId]
            ?? ['leaves' => 0, 'hours' => 0, 'total' => 0];
    }

    /**
     * Une seule requête agrégée par module, quel que soit le nombre de
     * valideurs, de groupes ou d'éléments : rien n'est chargé en mémoire, et
     * aucune requête n'est émise par utilisateur.
     *
     * @return array<int, int> total par identifiant de valideur
     */
    private function countPendingBy(string $table): array
    {
        $rows = DB::query()
            ->fromSub(
                $this->undecidedAtLevel($table, 1)->union($this->undecidedAtLevel($table, 2)),
                'pending',
            )
            ->select('validator_id', DB::raw('COUNT(*) as total'))
            ->groupBy('validator_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->validator_id] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Éléments ouverts sur lesquels le valideur du rang donné doit encore se
     * prononcer, sous la forme (identifiant, valideur).
     *
     * L'identifiant est sélectionné pour que l'UNION puisse dédoublonner : sans
     * lui, deux éléments distincts attendus du même valideur fusionneraient.
     */
    private function undecidedAtLevel(string $table, int $level): QueryBuilder
    {
        return DB::table($table)
            ->select([
                'id',
                DB::raw(sprintf('validator_%d_id as validator_id', $level)),
            ])
            ->whereIn('status', ValidationStage::OPEN)
            ->whereNotNull(sprintf('validator_%d_id', $level))
            ->whereNull(sprintf('validator_%d_decision', $level));
    }
}

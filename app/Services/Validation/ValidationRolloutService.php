<?php

namespace App\Services\Validation;

use App\Models\HourSheet;
use App\Models\User;
use App\Models\ValidationGroup;
use App\Notifications\HoursAttachedToValidationNotification;
use App\Services\AuditLogService;
use App\Services\Settings\AppSettings;
use App\Support\Validation\ValidationStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mise en service du système de validation à deux valideurs.
 *
 * Une seule date pilote l'ensemble : au-delà, Congés et Heures passent par les
 * groupes et la double validation ; en deçà, ils gardent le comportement
 * d'avant. Cette classe est le seul endroit qui répond à la question « cet
 * élément relève-t-il du nouveau système ? », et le seul qui rattache
 * a posteriori les heures déjà saisies.
 *
 * DATE NON RENSEIGNÉE = le nouveau système s'applique à tout. C'est l'état
 * actuel de l'application : ne rien configurer ne change donc rien, et le
 * réglage ne sert qu'à restreindre le périmètre.
 */
class ValidationRolloutService
{
    public const EFFECTIVE_DATE_KEY = 'validation.effective_date';

    public function __construct(
        private readonly AppSettings $settings,
        private readonly ValidationGroupService $validationGroups,
        private readonly TwoStepValidationService $twoStepValidation,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function effectiveDate(): ?CarbonImmutable
    {
        return $this->settings->date(self::EFFECTIVE_DATE_KEY);
    }

    public function setEffectiveDate(?string $date, ?User $actor = null): void
    {
        $this->settings->set(self::EFFECTIVE_DATE_KEY, $date, $actor);
    }

    /**
     * Une date métier relève-t-elle du nouveau système ?
     *
     * Comparaison sur le JOUR, dans le fuseau de l'application : le 1er
     * septembre est inclus, le 31 août ne l'est pas.
     */
    public function appliesTo(CarbonImmutable|string|null $businessDate): bool
    {
        $effectiveDate = $this->effectiveDate();

        if ($effectiveDate === null) {
            return true;
        }

        if ($businessDate === null) {
            return false;
        }

        $date = $businessDate instanceof CarbonImmutable
            ? $businessDate
            : CarbonImmutable::parse($businessDate, config('app.timezone', 'Europe/Paris'));

        return $date->startOfDay()->greaterThanOrEqualTo($effectiveDate);
    }

    /**
     * Heures : c'est la DATE DE LA JOURNÉE TRAVAILLÉE qui compte, pas la date
     * de saisie. Une journée du 31 août enregistrée en octobre reste hors
     * périmètre.
     */
    public function appliesToWorkDate(CarbonImmutable|string|null $workDate): bool
    {
        return $this->appliesTo($workDate);
    }

    /**
     * Congés : c'est la DATE DE DÉBUT du congé qui décide, et elle seule.
     *
     * Un congé à cheval sur la date d'effet (30 août → 3 septembre) suit donc
     * l'ancien circuit. La règle est volontairement tranchée sur une seule
     * borne : découper une demande en deux régimes de validation n'aurait pas
     * de sens métier, et se fonder sur la fin ferait basculer dans le nouveau
     * système des congés commencés avant sa mise en service.
     */
    public function appliesToLeaveStart(CarbonImmutable|string|null $startAt): bool
    {
        return $this->appliesTo($startAt);
    }

    /**
     * Rattache au circuit les journées d'heures déjà saisies qui entrent dans
     * le périmètre.
     *
     * IDEMPOTENT par construction : seules les journées dont le statut est NULL
     * — c'est-à-dire qui ne sont jamais entrées dans le circuit — sont
     * traitées. Une journée en attente, validée ou refusée n'est jamais
     * réécrite, quelle que soit la façon dont la date d'effet évolue ensuite.
     *
     * Aucune duplication n'est possible : l'état de validation est porté par
     * les colonnes de `hour_sheets`, table déjà contrainte par un index unique
     * (user_id, work_date). Il n'existe pas d'enregistrement de validation
     * séparé qui pourrait être créé deux fois.
     *
     * @return array{attached:int, skipped:int, anomalies:array<int, array{user_id:int, user_label:string, work_dates:array<int,string>, reason:string}>}
     */
    public function backfillHourSheets(bool $notify = true): array
    {
        $effectiveDate = $this->effectiveDate();

        $query = HourSheet::query()
            ->whereNull('status')
            ->with('user');

        if ($effectiveDate !== null) {
            $query->whereDate('work_date', '>=', $effectiveDate->toDateString());
        }

        /** @var \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, HourSheet>> $byUser */
        $byUser = $query->orderBy('work_date')->get()->groupBy('user_id');

        $attached = 0;
        $skipped = 0;
        $anomalies = [];
        /** @var array<int, array{validator: User, dates: array<int, string>}> $notifications */
        $notifications = [];

        foreach ($byUser as $userId => $sheets) {
            /** @var User|null $user */
            $user = $sheets->first()->user;

            if (! $user) {
                // Le compte a disparu : la journée n'est rattachable à personne.
                $skipped += $sheets->count();
                $anomalies[] = $this->anomaly((int) $userId, 'Utilisateur introuvable', $sheets, null);

                continue;
            }

            $group = $this->validationGroups->groupFor($user);
            $reason = $this->configurationProblem($group);

            if ($reason !== null) {
                // Rien n'est écrit : la journée garde son statut NULL et sera
                // reprise telle quelle au prochain passage, une fois le groupe
                // corrigé. Aucune validation impossible n'est créée.
                $skipped += $sheets->count();
                $anomalies[] = $this->anomaly((int) $userId, $reason, $sheets, $user);

                continue;
            }

            foreach ($sheets as $sheet) {
                DB::transaction(function () use ($sheet, $user, $group, &$attached, &$notifications): void {
                    /** @var HourSheet|null $locked */
                    $locked = HourSheet::query()->whereKey($sheet->getKey())->lockForUpdate()->first();

                    // Relecture sous verrou : une journée réenregistrée entre
                    // la sélection et ici est déjà dans le circuit, on la laisse.
                    if (! $locked || $locked->status !== null) {
                        return;
                    }

                    $this->twoStepValidation->assign($locked, $user, null, $group);
                    $locked->save();

                    $attached++;

                    foreach ([$locked->validator_1_id, $locked->validator_2_id] as $validatorId) {
                        if ($validatorId === null) {
                            continue;
                        }

                        $notifications[(int) $validatorId]['dates'][] = $locked->work_date?->toDateString() ?? '';
                    }
                });
            }
        }

        $this->logRun($effectiveDate, $attached, $skipped, $anomalies);

        if ($notify && $attached > 0) {
            $this->notifyValidators($notifications);
        }

        return [
            'attached' => $attached,
            'skipped' => $skipped,
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Journées qui entrent dans le périmètre mais que le rattrapage ne peut pas
     * traiter, regroupées par motif — destiné à l'écran d'administration.
     *
     * @return array<int, array{user_id:int, user_label:string, work_dates:array<int,string>, reason:string}>
     */
    public function pendingAnomalies(): array
    {
        $effectiveDate = $this->effectiveDate();

        $query = HourSheet::query()->whereNull('status')->with('user');

        if ($effectiveDate !== null) {
            $query->whereDate('work_date', '>=', $effectiveDate->toDateString());
        }

        $anomalies = [];

        foreach ($query->orderBy('work_date')->get()->groupBy('user_id') as $userId => $sheets) {
            $user = $sheets->first()->user;
            $reason = $user === null
                ? 'Utilisateur introuvable'
                : $this->configurationProblem($this->validationGroups->groupFor($user));

            if ($reason === null) {
                continue;
            }

            $anomalies[] = $this->anomaly((int) $userId, $reason, $sheets, $user);
        }

        return $anomalies;
    }

    /**
     * Ce qui empêche un groupe de recevoir des validations, ou null si tout va
     * bien. Un groupe sans second valideur n'est PAS une anomalie : le circuit
     * fonctionne alors à un seul accord, comme pour les utilisateurs sans
     * groupe des Congés.
     */
    private function configurationProblem(?ValidationGroup $group): ?string
    {
        if ($group === null) {
            return "L'utilisateur n'appartient à aucun groupe de validation";
        }

        $validator1 = $group->validator1;
        $validator2 = $group->validator2;
        $usable = [$validator1, $validator2];
        $usable = array_filter($usable, fn (?User $user): bool => $user !== null && (bool) $user->is_active);

        if ($usable === []) {
            return sprintf('Le groupe « %s » n\'a aucun valideur actif', $group->name);
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, HourSheet>  $sheets
     * @return array{user_id:int, user_label:string, work_dates:array<int,string>, reason:string}
     */
    private function anomaly(int $userId, string $reason, $sheets, ?User $user): array
    {
        return [
            'user_id' => $userId,
            'user_label' => $user ? $this->userLabel($user) : ('Utilisateur #'.$userId),
            'work_dates' => $sheets
                ->map(fn (HourSheet $sheet): string => $sheet->work_date?->toDateString() ?? '')
                ->filter()
                ->values()
                ->all(),
            'reason' => $reason,
        ];
    }

    /**
     * Une notification groupée par valideur, jamais une par journée : un
     * rattrapage peut reprendre des centaines de journées d'un coup.
     *
     * @param  array<int, array{dates: array<int, string>}>  $notifications
     */
    private function notifyValidators(array $notifications): void
    {
        if ($notifications === []) {
            return;
        }

        $validators = User::query()->whereIn('id', array_keys($notifications))->get()->keyBy('id');

        foreach ($notifications as $validatorId => $payload) {
            $validator = $validators->get($validatorId);
            $dates = array_values(array_unique(array_filter($payload['dates'] ?? [])));

            if (! $validator || $dates === []) {
                continue;
            }

            sort($dates);

            $validator->notify(new HoursAttachedToValidationNotification(
                count($dates),
                $dates[0],
                $dates[count($dates) - 1],
            ));
        }
    }

    /**
     * @param  array<int, array{user_id:int, user_label:string, work_dates:array<int,string>, reason:string}>  $anomalies
     */
    private function logRun(?CarbonImmutable $effectiveDate, int $attached, int $skipped, array $anomalies): void
    {
        if ($attached === 0 && $skipped === 0) {
            return;
        }

        $this->auditLogService->log([
            'action' => 'backfill_hour_sheets_validation',
            'module' => 'heures',
            'description' => sprintf(
                'Rattrapage de validation des heures : %d journée(s) rattachée(s), %d ignorée(s)',
                $attached,
                $skipped,
            ),
            'payload' => [
                'effective_date' => $effectiveDate?->toDateString(),
                'attached' => $attached,
                'skipped' => $skipped,
                'anomalies' => $anomalies,
            ],
        ]);
    }

    private function userLabel(User $user): string
    {
        $fullName = trim(
            collect([$user->first_name, $user->last_name])
                ->filter()
                ->implode(' ')
        );

        return $fullName !== '' ? $fullName : ((string) ($user->name ?: $user->email));
    }
}

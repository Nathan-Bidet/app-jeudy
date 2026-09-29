<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\HourSheet;
use App\Models\User;
use App\Support\Validation\ValidationStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

/*
 * Régression : « Historique » affichait « Aucune journée terminée » alors que
 * les journées traitées des 1er, 3 et 4 septembre étaient intactes en base.
 *
 * Situation reproduite : « Début saisie heures » au 28 septembre, journées
 * traitées AVANT cette date. La page Heures ne doit pas filtrer les journées
 * servies sur cette date — et la suppression des notifications de décision
 * ne doit rien retirer du passage au statut final.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Mail::fake();
    Queue::fake();
});

/** Journées servies au salarié, indexées par date. */
function servedHistory(User $employee): array
{
    return collect(test()->actingAs($employee->fresh())->get(route('hours.index'))->viewData('page')['props']['hourSheets'])
        ->keyBy('work_date')
        ->all();
}

function assertNoNotificationDuring(callable $action): void
{
    $before = DB::table('notifications')->count();
    Notification::fake();
    Mail::fake();
    Queue::fake();

    $action();

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
    expect(DB::table('notifications')->count())->toBe($before);
}

it('les journées traitées avant la date de début restent servies, celle en attente aussi mais « en validation »', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);
    $employee->forceFill(['hours_tracking_starts_at' => '2026-08-25'])->save();

    // 1er septembre : V1 refuse, V2 valide → validée.
    $first = submitHourSheet($employee, '2026-09-01');
    // 2 septembre : seul V2 s'est prononcé → reste en attente.
    $second = submitHourSheet($employee, '2026-09-02');
    // 3 septembre : V1 valide, V2 refuse → refusée.
    $third = submitHourSheet($employee, '2026-09-03');
    // 4 septembre : deux accords → validée.
    $fourth = submitHourSheet($employee, '2026-09-04');

    assertNoNotificationDuring(function () use ($v1, $v2, $first, $second, $third, $fourth): void {
        test()->actingAs($v1)->post(route('hours.refuse', $first->id), ['refusal_reason' => 'Pause']);
        test()->actingAs($v2)->post(route('hours.approve', $first->id));
        test()->actingAs($v2)->post(route('hours.refuse', $second->id), ['refusal_reason' => 'Horaires']);
        test()->actingAs($v1)->post(route('hours.approve', $third->id));
        test()->actingAs($v2)->post(route('hours.refuse', $third->id), ['refusal_reason' => 'Horaires']);
        test()->actingAs($v1)->post(route('hours.approve', $fourth->id));
        test()->actingAs($v2)->post(route('hours.approve', $fourth->id));
    });

    $snapshot = HourSheet::query()->where('user_id', $employee->id)->orderBy('id')->get()
        ->map(fn (HourSheet $sheet): array => $sheet->getAttributes())->all();

    // L'administrateur avance la date de début, comme sur le serveur.
    $employee->forceFill(['hours_tracking_starts_at' => '2026-09-28'])->save();

    $served = servedHistory($employee);

    expect(array_keys($served))->toEqualCanonicalizing(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'])
        ->and($served['2026-09-01']['status'])->toBe('processed')
        ->and($served['2026-09-03']['status'])->toBe('processed')
        ->and($served['2026-09-04']['status'])->toBe('processed')
        ->and($served['2026-09-02']['status'])->toBe(ValidationStage::PENDING)
        ->and($served['2026-09-04']['total_minutes'])->toBe(480)
        ->and($served['2026-09-04']['description'])->toBe('Travaux réalisés');

    // Statuts réels et contenu en base : intacts.
    expect($first->fresh()->status)->toBe(ValidationStage::APPROVED)
        ->and($second->fresh()->status)->toBe(ValidationStage::PENDING)
        ->and($third->fresh()->status)->toBe(ValidationStage::REFUSED)
        ->and($fourth->fresh()->status)->toBe(ValidationStage::APPROVED)
        ->and(HourSheet::query()->where('user_id', $employee->id)->orderBy('id')->get()
            ->map(fn (HourSheet $sheet): array => $sheet->getAttributes())->all())->toBe($snapshot);
});

it('un seul valideur : la validation clôt la journée, servie « Traitée » malgré une date de début postérieure', function (): void {
    $validator = hoursUser();
    $employee = hoursUser();
    $group = groupWith($validator, hoursUser(), [$employee]);
    $group->update(['validator_2_id' => null]);
    $employee->forceFill(['hours_tracking_starts_at' => '2026-08-25'])->save();

    $sheet = submitHourSheet($employee, '2026-09-04');

    assertNoNotificationDuring(fn () => test()->actingAs($validator)->post(route('hours.approve', $sheet->id)));

    $employee->forceFill(['hours_tracking_starts_at' => '2026-09-28'])->save();

    expect($sheet->fresh()->status)->toBe(ValidationStage::APPROVED)
        ->and(servedHistory($employee)['2026-09-04']['status'])->toBe('processed');
});

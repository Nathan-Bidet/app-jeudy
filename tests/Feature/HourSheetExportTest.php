<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\HourSheet;
use App\Support\Hours\WorkTimeReference;
use App\Support\Validation\ValidationStage;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\PermissionRegistrar;

/**
 * Export Excel des heures : heures supplémentaires et décisions des valideurs.
 *
 * Les assertions portent sur le CONTENU RÉEL du fichier produit : il est écrit,
 * puis relu avec OpenSpout. Un test qui n'inspecterait que le contrôleur ne
 * dirait rien de l'ordre des colonnes ni de ce qu'Excel affichera.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Lit toutes les lignes de la première feuille du classeur exporté. */
function exportedRows(string $binary): array
{
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $binary);

    $reader = new Reader();
    $reader->open($path);

    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(
                fn ($value): string => (string) $value,
                $row->toArray(),
            );
        }

        break; // Une feuille par salarié ; les tests n'en exportent qu'un.
    }

    $reader->close();
    unlink($path);

    return $rows;
}

/** Exporte la période et renvoie ses lignes, en-tête compris. */
function exportRowsFor(string $start = '2026-10-01', string $end = '2026-10-31'): array
{
    $exporter = hoursUser(['heures.view', 'heures.create', 'heures.export']);

    $response = test()->actingAs($exporter)
        ->get(route('hours.export', ['start_date' => $start, 'end_date' => $end]));

    $response->assertOk();

    // `response()->download()` renvoie un BinaryFileResponse : le contenu se
    // lit sur le fichier temporaire, que `deleteFileAfterSend` n'a pas encore
    // supprimé faute d'envoi réel.
    return exportedRows((string) file_get_contents($response->baseResponse->getFile()->getPathname()));
}

/** Ligne de l'export correspondant à une date, hors en-tête. */
function exportedRowForDate(array $rows, string $isoDate): array
{
    $expected = date('d/m/Y', strtotime($isoDate));

    foreach ($rows as $row) {
        if (($row[0] ?? null) === $expected) {
            return $row;
        }
    }

    return [];
}

// Rangs des colonnes, tels que l'en-tête les annonce.
const COL_TOTAL = 6;
const COL_OVERTIME = 7;
const COL_DESCRIPTION = 8;
const COL_VALIDATOR_1 = 13;
const COL_VALIDATOR_2 = 14;

/**
 * Journée saisie avec des horaires donnés, par un salarié rattaché à un groupe.
 *
 * @return array{0: App\Models\User, 1: App\Models\User, 2: App\Models\User, 3: HourSheet}
 */
function exportableDay(string $date, string $afternoonEnd = '18:00'): array
{
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    test()->actingAs($employee)->post(route('hours.store'), [
        'work_date' => $date,
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => $afternoonEnd,
        'description' => 'Travaux réalisés',
    ])->assertSessionHasNoErrors();

    $sheet = HourSheet::query()->where('user_id', $employee->id)->whereDate('work_date', $date)->firstOrFail();

    return [$v1, $v2, $employee, $sheet];
}

/*
|--------------------------------------------------------------------------
| Structure du fichier
|--------------------------------------------------------------------------
*/

it('place les nouvelles colonnes au bon rang', function (): void {
    exportableDay('2026-10-05');

    $rows = exportRowsFor();

    expect($rows[0])->toBe([
        'Date',
        'Jour',
        'Début matin',
        'Fin matin',
        'Début soir',
        'Fin soir',
        'Total heures travaillées',
        'Heures supplémentaires',
        'Description',
        'Casse-croûte (Avant 5h)',
        'Déjeuner',
        'Dîner (Après 21h)',
        'Nuit (Déplacement long)',
        'Valideur 1',
        'Valideur 2',
    ]);
});

it('conserve les colonnes existantes et leur contenu', function (): void {
    exportableDay('2026-10-05');

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[0])->toBe('05/10/2026')
        ->and($row[1])->toBe('Lundi')
        ->and($row[2])->toBe('08:00')
        ->and($row[3])->toBe('12:00')
        ->and($row[4])->toBe('14:00')
        ->and($row[5])->toBe('18:00')
        ->and($row[COL_TOTAL])->toBe('08h00')
        ->and($row[COL_DESCRIPTION])->toBe('Travaux réalisés')
        ->and($row[9])->toBe('Non')
        ->and($row[10])->toBe('Non')
        ->and($row[11])->toBe('Non')
        ->and($row[12])->toBe('Non');
});

/*
|--------------------------------------------------------------------------
| Heures supplémentaires
|--------------------------------------------------------------------------
*/

it('n\'annonce aucune heure supplémentaire sur une journée de référence', function (): void {
    // Lundi 5 octobre 2026, 8 h travaillées pour 8 h de référence.
    exportableDay('2026-10-05');

    expect(exportedRowForDate(exportRowsFor(), '2026-10-05')[COL_OVERTIME])->toBe('00h00');
});

it('compte une heure supplémentaire entière', function (): void {
    exportableDay('2026-10-05', '19:00');

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[COL_TOTAL])->toBe('09h00')
        ->and($row[COL_OVERTIME])->toBe('01h00');
});

it('compte une demi-heure supplémentaire', function (): void {
    exportableDay('2026-10-05', '18:30');

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[COL_TOTAL])->toBe('08h30')
        ->and($row[COL_OVERTIME])->toBe('00h30');
});

it('compte un quart d\'heure supplémentaire', function (): void {
    exportableDay('2026-10-05', '18:15');

    expect(exportedRowForDate(exportRowsFor(), '2026-10-05')[COL_OVERTIME])->toBe('00h15');
});

it('applique la référence de 7 h le vendredi', function (): void {
    // Vendredi 9 octobre 2026 : 8 h travaillées font 1 h de plus.
    exportableDay('2026-10-09');

    $row = exportedRowForDate(exportRowsFor(), '2026-10-09');

    expect($row[1])->toBe('Vendredi')
        ->and($row[COL_TOTAL])->toBe('08h00')
        ->and($row[COL_OVERTIME])->toBe('01h00');
});

it('n\'affiche jamais d\'écart négatif', function (): void {
    // Lundi, 7 h 30 travaillées pour 8 h de référence.
    exportableDay('2026-10-05', '17:30');

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[COL_TOTAL])->toBe('07h30')
        ->and($row[COL_OVERTIME])->toBe('00h00')
        ->and($row[COL_OVERTIME])->not->toContain('-');
});

it('ne calcule aucun écart sur une journée non travaillée', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'is_not_worked' => true,
        'description' => 'Arrêt maladie',
    ])->assertSessionHasNoErrors();

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[2])->toBe('Non travaillé')
        ->and($row[COL_TOTAL])->toBe('')
        ->and($row[COL_OVERTIME])->toBe('')
        ->and($row[COL_DESCRIPTION])->toBe('Arrêt maladie')
        // La journée reste dans le circuit : ses décisions sont exportées.
        ->and($row[COL_VALIDATOR_1])->toBe('En attente')
        ->and($row[COL_VALIDATOR_2])->toBe('En attente');
});

/*
|--------------------------------------------------------------------------
| Décisions des valideurs
|--------------------------------------------------------------------------
*/

it('exporte « En attente » tant qu\'aucun valideur ne s\'est prononcé', function (): void {
    exportableDay('2026-10-05');

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[COL_VALIDATOR_1])->toBe('En attente')
        ->and($row[COL_VALIDATOR_2])->toBe('En attente');
});

it('exporte les états réels des deux rangs, sans recomposer un statut global', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    // La journée est globalement « en validation », mais chaque rang dit ce
    // qu'il a réellement décidé.
    expect($sheet->fresh()->status)->toBe(ValidationStage::PENDING)
        ->and($row[COL_VALIDATOR_1])->toBe('Validé')
        ->and($row[COL_VALIDATOR_2])->toBe('En attente');
});

it('exporte « Refusé » sans motif quand aucun n\'a été saisi', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id));

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[COL_VALIDATOR_1])->toBe('Refusé')
        ->and($row[COL_VALIDATOR_2])->toBe('En attente');
});

it('accole le motif au refus', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Horaires incorrects',
    ]);

    expect(exportedRowForDate(exportRowsFor(), '2026-10-05')[COL_VALIDATOR_1])
        ->toBe('Refusé - Horaires incorrects');
});

it('accole le motif au refus du second valideur', function (): void {
    [$v1, $v2, , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Merci de corriger l\'heure de fin',
    ]);

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[COL_VALIDATOR_1])->toBe('Validé')
        ->and($row[COL_VALIDATOR_2])->toBe('Refusé - Merci de corriger l\'heure de fin');
});

it('ne nomme aucun valideur', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    // Noms explicites : la fabrique n'en pose pas, et chercher une chaîne vide
    // ne prouverait rien.
    $v1->forceFill(['first_name' => 'Alice', 'last_name' => 'Blanchet'])->save();

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    $flattened = implode(' | ', array_map(fn (array $row): string => implode(' | ', $row), exportRowsFor()));

    expect($flattened)->toContain('Validé')
        ->and($flattened)->not->toContain('Alice')
        ->and($flattened)->not->toContain('Blanchet');
});

/*
|--------------------------------------------------------------------------
| Journées hors du nouveau système
|--------------------------------------------------------------------------
*/

it('exporte sans erreur une journée antérieure au système de validation', function (): void {
    $v1 = hoursUser();
    $v2 = hoursUser();
    $employee = hoursUser();
    groupWith($v1, $v2, [$employee]);

    app(App\Services\Validation\ValidationRolloutService::class)->setEffectiveDate('2026-11-01');

    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '19:00',
        'description' => 'Travaux réalisés',
    ])->assertSessionHasNoErrors();

    $sheet = HourSheet::query()->whereDate('work_date', '2026-10-05')->firstOrFail();
    expect($sheet->status)->toBeNull();

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    // Les colonnes métier restent renseignées : seule la validation ne
    // s'applique pas.
    expect($row[COL_TOTAL])->toBe('09h00')
        ->and($row[COL_OVERTIME])->toBe('01h00')
        ->and($row[COL_VALIDATOR_1])->toBe('Non applicable')
        ->and($row[COL_VALIDATOR_2])->toBe('Non applicable');
});

it('marque le second rang non applicable quand le groupe n\'a pas de Valideur 2', function (): void {
    $employee = hoursUser();

    // Aucun groupe : le circuit se rabat sur un valideur unique.
    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '18:00',
        'description' => 'Travaux réalisés',
    ])->assertSessionHasNoErrors();

    $sheet = HourSheet::query()->whereDate('work_date', '2026-10-05')->firstOrFail();
    expect($sheet->hasSecondValidationLevel())->toBeFalse();

    $row = exportedRowForDate(exportRowsFor(), '2026-10-05');

    expect($row[COL_VALIDATOR_1])->toBe('En attente')
        ->and($row[COL_VALIDATOR_2])->toBe('Non applicable');
});

it('exporte une période sans aucune donnée', function (): void {
    $rows = exportRowsFor('2026-12-01', '2026-12-31');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveCount(15)
        ->and($rows[0][COL_OVERTIME])->toBe('Heures supplémentaires');
});

/*
|--------------------------------------------------------------------------
| Source unique du calcul
|--------------------------------------------------------------------------
*/

it('déduit la référence des horaires partagés avec le front', function (): void {
    $shared = json_decode((string) file_get_contents(resource_path('js/Support/hoursReference.json')), true);

    expect($shared)->toBeArray()
        ->and($shared['morning_start'])->toBe('08:00')
        ->and($shared['afternoon_end'])->toBe('18:00')
        ->and($shared['afternoon_end_friday'])->toBe('17:00');

    // 0 = lundi … 4 = vendredi. Le week-end n'a pas de durée normale.
    expect(WorkTimeReference::referenceMinutesForDayIndex(0))->toBe(480)
        ->and(WorkTimeReference::referenceMinutesForDayIndex(3))->toBe(480)
        ->and(WorkTimeReference::referenceMinutesForDayIndex(4))->toBe(420)
        ->and(WorkTimeReference::referenceMinutesForDayIndex(5))->toBeNull()
        ->and(WorkTimeReference::referenceMinutesForDayIndex(6))->toBeNull();
});

it('ne compte aucune heure supplémentaire le week-end, faute de référence', function (): void {
    // Samedi 10 octobre 2026 : le module ne définit pas de durée normale.
    expect(WorkTimeReference::referenceMinutesForDate('2026-10-10'))->toBeNull()
        ->and(WorkTimeReference::overtimeForDay(600, '2026-10-10'))->toBe(0);
});

it('résiste à une date absente ou illisible', function (): void {
    expect(WorkTimeReference::referenceMinutesForDate(null))->toBeNull()
        ->and(WorkTimeReference::referenceMinutesForDate(''))->toBeNull()
        ->and(WorkTimeReference::overtimeForDay(600, null))->toBe(0);
});

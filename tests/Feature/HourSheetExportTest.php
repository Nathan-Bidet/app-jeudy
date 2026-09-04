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

/**
 * Style réellement appliqué à chaque cellule du fichier, ligne par ligne.
 *
 * OpenSpout ne relit pas les styles : on ouvre donc le XLSX comme l'archive ZIP
 * qu'il est, et on résout, pour chaque cellule, l'index de style qu'elle porte
 * vers la couleur de fond et la couleur de police déclarées dans styles.xml.
 * C'est exactement ce que fera Excel à l'ouverture.
 *
 * La lecture passe par un vrai analyseur XML plutôt que par des expressions
 * régulières : la résolution index de style → remplissage → couleur repose sur
 * l'ORDRE des déclarations, qu'une regex approximative décale sans le dire.
 *
 * @return array<int, array<int, array{background:?string, font:?string, wrap:bool}>>
 */
function exportedCellStyles(string $path): array
{
    $zip = new ZipArchive();
    $zip->open($path);
    $stylesXml = (string) $zip->getFromName('xl/styles.xml');
    $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    $ooxml = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    $styleSheet = new DOMDocument();
    $styleSheet->loadXML($stylesXml);

    // Palette des remplissages et des polices, dans l'ordre de déclaration :
    // c'est ce rang que les cellules référencent.
    $colorOf = function (DOMElement $node, string $tag) use ($ooxml): ?string {
        $color = $node->getElementsByTagNameNS($ooxml, $tag)->item(0);

        return $color instanceof DOMElement && $color->hasAttribute('rgb')
            ? $color->getAttribute('rgb')
            : null;
    };

    $fills = [];
    foreach ($styleSheet->getElementsByTagNameNS($ooxml, 'fills') as $block) {
        foreach ($block->getElementsByTagNameNS($ooxml, 'fill') as $fill) {
            $fills[] = $colorOf($fill, 'fgColor');
        }
    }

    $fonts = [];
    foreach ($styleSheet->getElementsByTagNameNS($ooxml, 'fonts') as $block) {
        foreach ($block->getElementsByTagNameNS($ooxml, 'font') as $font) {
            $fonts[] = $colorOf($font, 'color');
        }
    }

    // cellXfs : chaque index de style pointe vers un remplissage et une police.
    $formats = [];
    foreach ($styleSheet->getElementsByTagNameNS($ooxml, 'cellXfs') as $block) {
        foreach ($block->getElementsByTagNameNS($ooxml, 'xf') as $xf) {
            $alignment = $xf->getElementsByTagNameNS($ooxml, 'alignment')->item(0);

            $formats[] = [
                'fill' => (int) $xf->getAttribute('fillId'),
                'font' => (int) $xf->getAttribute('fontId'),
                'wrap' => $alignment instanceof DOMElement && $alignment->getAttribute('wrapText') === '1',
            ];
        }
    }

    $worksheet = new DOMDocument();
    $worksheet->loadXML($sheetXml);

    $styles = [];

    foreach ($worksheet->getElementsByTagNameNS($ooxml, 'row') as $row) {
        $rowIndex = (int) $row->getAttribute('r') - 1;

        foreach ($row->getElementsByTagNameNS($ooxml, 'c') as $cell) {
            // La référence de la cellule (« N2 ») donne sa colonne : une cellule
            // omise ne doit pas décaler celles qui suivent.
            preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $reference);
            $column = 0;
            foreach (str_split($reference[1] ?? 'A') as $letter) {
                $column = ($column * 26) + (ord($letter) - 64);
            }
            $column--;

            $format = $formats[(int) $cell->getAttribute('s')] ?? ['fill' => 0, 'font' => 0, 'wrap' => false];

            $styles[$rowIndex][$column] = [
                'background' => $fills[$format['fill']] ?? null,
                'font' => $fonts[$format['font']] ?? null,
                'wrap' => $format['wrap'],
            ];
        }
    }

    return $styles;
}

/** Styles de l'export, indexés par ligne puis par colonne. */
function exportStylesFor(string $start = '2026-10-01', string $end = '2026-10-31'): array
{
    $exporter = hoursUser(['heures.view', 'heures.create', 'heures.export']);

    $response = test()->actingAs($exporter)
        ->get(route('hours.export', ['start_date' => $start, 'end_date' => $end]));

    $response->assertOk();

    return exportedCellStyles($response->baseResponse->getFile()->getPathname());
}

/** Rang de la ligne portant une date donnée, en-tête compris. */
function exportedRowIndexForDate(array $rows, string $isoDate): int
{
    $expected = date('d/m/Y', strtotime($isoDate));

    foreach ($rows as $index => $row) {
        if (($row[0] ?? null) === $expected) {
            return $index;
        }
    }

    return -1;
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

/*
|--------------------------------------------------------------------------
| Mise en évidence des refus
|--------------------------------------------------------------------------
|
| Les assertions portent sur le style RÉELLEMENT écrit dans le fichier, résolu
| comme Excel le fera : index de style de la cellule → remplissage et police
| déclarés dans styles.xml. Les couleurs sont en ARGB, préfixées de FF.
*/

const REFUSAL_BACKGROUND = 'FFFEF2F2';
const REFUSAL_FONT = 'FFB91C1C';

it('colore en rouge un refus sans motif', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id));

    $rows = exportRowsFor();
    $styles = exportStylesFor();
    $line = exportedRowIndexForDate($rows, '2026-10-05');

    expect($rows[$line][COL_VALIDATOR_1])->toBe('Refusé')
        ->and($styles[$line][COL_VALIDATOR_1]['background'])->toBe(REFUSAL_BACKGROUND)
        ->and($styles[$line][COL_VALIDATOR_1]['font'])->toBe(REFUSAL_FONT);
});

it('colore en rouge un refus motivé', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Horaires incorrects',
    ]);

    $rows = exportRowsFor();
    $styles = exportStylesFor();
    $line = exportedRowIndexForDate($rows, '2026-10-05');

    // Le motif suit le mot « Refusé » : c'est précisément le cas qu'une
    // égalité stricte laisserait passer.
    expect($rows[$line][COL_VALIDATOR_1])->toBe('Refusé - Horaires incorrects')
        ->and($styles[$line][COL_VALIDATOR_1]['background'])->toBe(REFUSAL_BACKGROUND)
        ->and($styles[$line][COL_VALIDATOR_1]['font'])->toBe(REFUSAL_FONT);
});

it('ne colore pas « Validé » ni « En attente »', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));

    $rows = exportRowsFor();
    $styles = exportStylesFor();
    $line = exportedRowIndexForDate($rows, '2026-10-05');

    expect($rows[$line][COL_VALIDATOR_1])->toBe('Validé')
        ->and($rows[$line][COL_VALIDATOR_2])->toBe('En attente')
        ->and($styles[$line][COL_VALIDATOR_1]['background'])->not->toBe(REFUSAL_BACKGROUND)
        ->and($styles[$line][COL_VALIDATOR_2]['background'])->not->toBe(REFUSAL_BACKGROUND);
});

it('ne colore pas « Non applicable »', function (): void {
    $employee = hoursUser();

    // Aucun groupe : le rang 2 n'est pas attendu.
    $this->actingAs($employee)->post(route('hours.store'), [
        'work_date' => '2026-10-05',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '18:00',
        'description' => 'Travaux réalisés',
    ])->assertSessionHasNoErrors();

    $rows = exportRowsFor();
    $styles = exportStylesFor();
    $line = exportedRowIndexForDate($rows, '2026-10-05');

    expect($rows[$line][COL_VALIDATOR_2])->toBe('Non applicable')
        ->and($styles[$line][COL_VALIDATOR_2]['background'])->not->toBe(REFUSAL_BACKGROUND);
});

it('ne colore que la colonne du valideur qui a refusé', function (): void {
    [$v1, $v2, , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.approve', $sheet->id));
    $this->actingAs($v2)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Merci de corriger la feuille',
    ]);

    $rows = exportRowsFor();
    $styles = exportStylesFor();
    $line = exportedRowIndexForDate($rows, '2026-10-05');

    expect($styles[$line][COL_VALIDATOR_1]['background'])->not->toBe(REFUSAL_BACKGROUND)
        ->and($styles[$line][COL_VALIDATOR_2]['background'])->toBe(REFUSAL_BACKGROUND);
});

it('ne déborde jamais sur les autres colonnes', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Horaires incorrects',
    ]);

    $styles = exportStylesFor();
    $line = exportedRowIndexForDate(exportRowsFor(), '2026-10-05');

    // Une seule colonne rouge sur toute la ligne : la mise en forme ne fuit ni
    // sur la description, ni sur les horaires.
    for ($column = 0; $column < COL_VALIDATOR_1; $column++) {
        expect($styles[$line][$column]['background'] ?? null)->not->toBe(REFUSAL_BACKGROUND);
    }
});

it('ne colore pas l\'en-tête', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id));

    $styles = exportStylesFor();

    expect($styles[0][COL_VALIDATOR_1]['background'])->not->toBe(REFUSAL_BACKGROUND)
        ->and($styles[0][COL_VALIDATOR_2]['background'])->not->toBe(REFUSAL_BACKGROUND);
});

it('conserve le retour à la ligne sur les cellules colorées', function (): void {
    [$v1, , , $sheet] = exportableDay('2026-10-05');

    $this->actingAs($v1)->post(route('hours.refuse', $sheet->id), [
        'refusal_reason' => 'Un motif suffisamment long pour que le retour à la ligne compte réellement dans la lisibilité de la cellule.',
    ]);

    $styles = exportStylesFor();
    $line = exportedRowIndexForDate(exportRowsFor(), '2026-10-05');

    // Colorer ne doit pas avoir remplacé le style de retour à la ligne.
    expect($styles[$line][COL_VALIDATOR_1]['wrap'])->toBeTrue()
        ->and($styles[$line][COL_VALIDATOR_1]['background'])->toBe(REFUSAL_BACKGROUND);
});

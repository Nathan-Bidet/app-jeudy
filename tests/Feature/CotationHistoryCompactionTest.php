<?php

use App\Services\Cotations\CotationMarketService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Compactage de l'historique des cotations.
 *
 * Repère temporel commun : « maintenant » est figé au 10/09/2026 à 17h00
 * (Europe/Paris). Avec 7 jours de rétention, la dernière journée compactable
 * est le 02/09 et les journées du 03/09 au 10/09 sont intégralement protégées.
 */
const NOW = '2026-09-10 17:00:00';

const OLD_DAY = '2026-08-01';

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse(NOW, 'Europe/Paris'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function cotationRefresh(string $fetchedAt, bool $success = true): int
{
    return (int) DB::table('cotation_market_refreshes')->insertGetId([
        'source_url' => CotationMarketService::EURONEXT_URL,
        'is_success' => $success,
        'http_status' => $success ? 200 : 500,
        'row_count' => 0,
        'error_message' => null,
        'fetched_at' => $fetchedAt,
        'created_at' => $fetchedAt,
        'updated_at' => $fetchedAt,
    ]);
}

/**
 * Insère une cotation. `$createdAt` est l'horodatage métier retenu par le
 * compactage ; `quoted_at` reste nul par défaut, comme 63 % des lignes de
 * production.
 */
function cotationPrice(int $refreshId, ?string $createdAt, array $overrides = []): int
{
    return (int) DB::table('cotation_market_prices')->insertGetId(array_merge([
        'refresh_id' => $refreshId,
        'product_code' => 'EBM',
        'product_name' => 'Blé',
        'product_sort' => 20,
        'contract_code' => 'EBM-DEC26',
        'maturity_label' => 'Décembre 2026',
        'maturity_month' => 12,
        'maturity_year' => 2026,
        'harvest_year' => 2026,
        'price' => 195.5,
        'raw_price' => '195,50',
        'maturity_sort' => 202612,
        'quoted_at' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $overrides));
}

/** Une actualisation complète : un horodatage, plusieurs cotations. */
function cotationSnapshot(string $at, ?array $identities = null): array
{
    $identities ??= [['product_code' => 'EBM', 'maturity_label' => 'Décembre 2026', 'maturity_month' => 12]];
    $refreshId = cotationRefresh($at);
    $ids = [];

    foreach ($identities as $identity) {
        $ids[] = cotationPrice($refreshId, $at, $identity);
    }

    return ['refresh_id' => $refreshId, 'ids' => $ids];
}

function dayRowCount(string $day): int
{
    return DB::table('cotation_market_prices')
        ->where('created_at', '>=', $day.' 00:00:00')
        ->where('created_at', '<', CarbonImmutable::parse($day)->addDay()->format('Y-m-d').' 00:00:00')
        ->count();
}

function runCompaction(array $options = []): int
{
    return test()->artisan('cotations:compact-history', $options)->run();
}

it('conserve intégralement les sept derniers jours', function (): void {
    // Une journée détaillée pour chacun des sept jours précédents.
    foreach (range(1, 7) as $offset) {
        $day = CarbonImmutable::parse(NOW, 'Europe/Paris')->subDays($offset)->format('Y-m-d');

        foreach (['09:00:00', '15:00:00', '17:30:00'] as $time) {
            cotationSnapshot($day.' '.$time);
        }
    }

    $before = DB::table('cotation_market_prices')->count();

    runCompaction();

    expect(DB::table('cotation_market_prices')->count())->toBe($before);
});

it('ne compacte pas une journée à cheval sur la fenêtre de rétention', function (): void {
    // 03/09 contient l'instant « maintenant - 7 jours » (17h00) : la journée
    // est partiellement protégée, elle doit donc l'être entièrement.
    foreach (['08:00:00', '15:00:00', '20:00:00'] as $time) {
        cotationSnapshot('2026-09-03 '.$time);
    }

    foreach (['08:00:00', '15:00:00', '20:00:00'] as $time) {
        cotationSnapshot('2026-09-02 '.$time);
    }

    runCompaction();

    expect(dayRowCount('2026-09-03'))->toBe(3)
        ->and(dayRowCount('2026-09-02'))->toBe(1);
});

it('conserve le relevé situé exactement à 15 h', function (): void {
    cotationSnapshot(OLD_DAY.' 09:12:00');
    $exact = cotationSnapshot(OLD_DAY.' 15:00:00');
    cotationSnapshot(OLD_DAY.' 18:40:00');

    runCompaction();

    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe([$exact['ids'][0]]);
});

it('conserve le relevé le plus proche avant 15 h quand rien ne suit', function (): void {
    cotationSnapshot(OLD_DAY.' 08:00:00');
    $closest = cotationSnapshot(OLD_DAY.' 14:52:00');

    runCompaction();

    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe([$closest['ids'][0]]);
});

it('conserve le relevé le plus proche après 15 h quand rien ne précède', function (): void {
    $closest = cotationSnapshot(OLD_DAY.' 15:07:00');
    cotationSnapshot(OLD_DAY.' 19:30:00');

    runCompaction();

    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe([$closest['ids'][0]]);
});

it('départage une égalité autour de 15 h en faveur du relevé postérieur', function (): void {
    cotationSnapshot(OLD_DAY.' 14:30:00');
    $after = cotationSnapshot(OLD_DAY.' 15:30:00');

    runCompaction();

    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe([$after['ids'][0]]);
});

it('départage deux relevés strictement identiques par l\'identifiant le plus élevé', function (): void {
    $first = cotationSnapshot(OLD_DAY.' 15:00:00');
    $second = cotationSnapshot(OLD_DAY.' 15:00:00');

    expect($second['ids'][0])->toBeGreaterThan($first['ids'][0]);

    runCompaction();

    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe([$second['ids'][0]]);
});

it('conserve un relevé par céréale et par échéance', function (): void {
    $identities = [
        ['product_code' => 'EBM', 'maturity_label' => 'Décembre 2026', 'maturity_month' => 12],
        ['product_code' => 'EBM', 'maturity_label' => 'Mars 2027', 'maturity_month' => 3, 'maturity_year' => 2027],
        ['product_code' => 'ECO', 'maturity_label' => 'Novembre 2026', 'maturity_month' => 11],
        ['product_code' => 'EMA', 'maturity_label' => 'Juin 2027', 'maturity_month' => 6, 'maturity_year' => 2027],
    ];

    cotationSnapshot(OLD_DAY.' 08:00:00', $identities);
    $winner = cotationSnapshot(OLD_DAY.' 15:02:00', $identities);
    cotationSnapshot(OLD_DAY.' 21:00:00', $identities);

    runCompaction();

    expect(DB::table('cotation_market_prices')->orderBy('id')->pluck('id')->all())->toBe($winner['ids'])
        ->and(DB::table('cotation_market_prices')->distinct()->count('product_code'))->toBe(3);
});

it('ne supprime jamais le seul relevé disponible d\'une combinaison', function (): void {
    $common = ['product_code' => 'EBM', 'maturity_label' => 'Décembre 2026', 'maturity_month' => 12];
    $rare = ['product_code' => 'ETR', 'maturity_label' => 'Mai 2027', 'maturity_month' => 5, 'maturity_year' => 2027];

    cotationSnapshot(OLD_DAY.' 09:00:00', [$common]);
    cotationSnapshot(OLD_DAY.' 15:00:00', [$common]);

    // Une seule apparition, très loin de 15 h : elle doit survivre.
    $only = cotationSnapshot(OLD_DAY.' 23:45:00', [$rare]);

    runCompaction();

    expect(DB::table('cotation_market_prices')->where('product_code', 'ETR')->pluck('id')->all())
        ->toBe([$only['ids'][0]])
        ->and(DB::table('cotation_market_prices')->count())->toBe(2);
});

it('gère une actualisation incomplète sans perdre de cotation', function (): void {
    $full = [
        ['product_code' => 'EBM', 'maturity_label' => 'Décembre 2026', 'maturity_month' => 12],
        ['product_code' => 'ECO', 'maturity_label' => 'Novembre 2026', 'maturity_month' => 11],
    ];

    // 14h59 : actualisation complète. 15h01 : actualisation amputée du colza.
    $complete = cotationSnapshot(OLD_DAY.' 14:59:00', $full);
    $partial = cotationSnapshot(OLD_DAY.' 15:01:00', [$full[0]]);

    runCompaction();

    $kept = DB::table('cotation_market_prices')->orderBy('id')->pluck('id')->all();

    // Le blé vient de l'actualisation postérieure (égalité départagée),
    // le colza de la seule actualisation qui le contenait.
    expect($kept)->toBe([$complete['ids'][1], $partial['ids'][0]])
        ->and(DB::table('cotation_market_prices')->where('product_code', 'ECO')->count())->toBe(1);
});

it('ignore quoted_at, y compris lorsqu\'il est incohérent', function (): void {
    // En production quoted_at est nul 63 % du temps et porte sinon des heures
    // sans rapport (20h28 pour une ligne créée à 17h27) : seul created_at fait foi.
    cotationSnapshot(OLD_DAY.' 09:00:00');
    $refresh = cotationRefresh(OLD_DAY.' 15:00:00');
    $winner = cotationPrice($refresh, OLD_DAY.' 15:00:00', ['quoted_at' => OLD_DAY.' 23:59:00']);

    runCompaction();

    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe([$winner]);
});

it('ne supprime jamais une ligne dont created_at est nul', function (): void {
    cotationSnapshot(OLD_DAY.' 09:00:00');
    cotationSnapshot(OLD_DAY.' 15:00:00');

    $orphanRefresh = cotationRefresh(OLD_DAY.' 10:00:00');
    $undated = cotationPrice($orphanRefresh, null);

    runCompaction();

    expect(DB::table('cotation_market_prices')->whereNull('created_at')->pluck('id')->all())->toBe([$undated])
        ->and(DB::table('cotation_market_prices')->count())->toBe(2);
});

it('traite une journée de changement d\'heure comme une journée civile complète', function (): void {
    // 25/10/2026 : passage à l'heure d'hiver, journée de 25 heures.
    Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Europe/Paris'));

    $early = cotationSnapshot('2026-10-25 00:30:00');
    cotationSnapshot('2026-10-25 14:00:00');
    $winner = cotationSnapshot('2026-10-25 15:00:00');
    $late = cotationSnapshot('2026-10-25 23:30:00');
    $nextDay = cotationSnapshot('2026-10-26 00:30:00');

    runCompaction();

    $kept = DB::table('cotation_market_prices')->orderBy('id')->pluck('id')->all();

    // Toute la journée locale est compactée en un seul relevé, minuit inclus
    // des deux côtés ; le 26/10 est une autre journée, compactée séparément.
    expect($kept)->toBe([$winner['ids'][0], $nextDay['ids'][0]])
        ->and($kept)->not->toContain($early['ids'][0])
        ->and($kept)->not->toContain($late['ids'][0]);
});

it('applique la même règle un jour de passage à l\'heure d\'été', function (): void {
    // 29/03/2026 : journée de 23 heures, 02h00 n'existe pas localement.
    Carbon::setTestNow(Carbon::parse('2026-04-20 12:00:00', 'Europe/Paris'));

    cotationSnapshot('2026-03-29 13:40:00');
    $winner = cotationSnapshot('2026-03-29 15:05:00');
    cotationSnapshot('2026-03-29 18:00:00');

    runCompaction();

    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe([$winner['ids'][0]]);
});

it('est idempotente : une seconde exécution ne supprime plus rien', function (): void {
    foreach (['08:00:00', '14:58:00', '15:03:00', '20:00:00'] as $time) {
        cotationSnapshot(OLD_DAY.' '.$time);
    }

    runCompaction();
    $afterFirst = DB::table('cotation_market_prices')->pluck('id')->all();

    runCompaction();
    runCompaction();

    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe($afterFirst)
        ->and($afterFirst)->toHaveCount(1);
});

it('reprend le traitement là où il s\'est arrêté', function (): void {
    foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $day) {
        foreach (['09:00:00', '15:00:00', '20:00:00'] as $time) {
            cotationSnapshot($day.' '.$time);
        }
    }

    // Interruption simulée : une seule journée traitée, la plus ancienne.
    runCompaction(['--max-days' => 1]);

    expect(dayRowCount('2026-08-01'))->toBe(1)
        ->and(dayRowCount('2026-08-02'))->toBe(3)
        ->and(dayRowCount('2026-08-03'))->toBe(3);

    // Relance : les journées restantes sont traitées, la première est intacte.
    runCompaction();

    expect(dayRowCount('2026-08-01'))->toBe(1)
        ->and(dayRowCount('2026-08-02'))->toBe(1)
        ->and(dayRowCount('2026-08-03'))->toBe(1);
});

it('ne supprime rien en mode simulation', function (): void {
    foreach (['09:00:00', '15:00:00', '20:00:00'] as $time) {
        cotationSnapshot(OLD_DAY.' '.$time);
    }

    $this->artisan('cotations:compact-history', ['--dry-run' => true, '--show-kept' => true])
        ->assertSuccessful();

    expect(dayRowCount(OLD_DAY))->toBe(3)
        ->and(DB::table('cotation_market_refreshes')->count())->toBe(3);
});

it('supprime les actualisations devenues orphelines et préserve les autres', function (): void {
    $dropped = cotationSnapshot(OLD_DAY.' 09:00:00');
    $kept = cotationSnapshot(OLD_DAY.' 15:00:00');

    // Actualisation en échec, sans aucune cotation : elle est orpheline dès
    // l'origine et disparaît avec la journée compactée.
    $failed = cotationRefresh(OLD_DAY.' 10:00:00', success: false);

    // Actualisation de 23h59 dont les prix sont tombés le lendemain : elle est
    // encore référencée, elle doit survivre.
    $straddling = cotationRefresh(OLD_DAY.' 23:59:59');
    cotationPrice($straddling, '2026-08-02 00:00:02');

    // Journée protégée : rien ne doit y être touché.
    $recent = cotationSnapshot('2026-09-08 09:00:00');

    runCompaction();

    $refreshes = DB::table('cotation_market_refreshes')->orderBy('id')->pluck('id')->all();

    expect($refreshes)->toContain($kept['refresh_id'])
        ->and($refreshes)->toContain($straddling)
        ->and($refreshes)->toContain($recent['refresh_id'])
        ->and($refreshes)->not->toContain($dropped['refresh_id'])
        ->and($refreshes)->not->toContain($failed);
});

it('ne laisse pas la suppression en cascade emporter une cotation conservée', function (): void {
    cotationSnapshot(OLD_DAY.' 09:00:00');
    $kept = cotationSnapshot(OLD_DAY.' 15:00:00');

    runCompaction();

    // La clé étrangère est en ON DELETE CASCADE : si une actualisation encore
    // référencée avait été supprimée, la cotation conservée aurait disparu.
    expect(DB::table('cotation_market_prices')->pluck('id')->all())->toBe([$kept['ids'][0]])
        ->and(DB::table('cotation_market_prices')
            ->join('cotation_market_refreshes', 'cotation_market_refreshes.id', '=', 'cotation_market_prices.refresh_id')
            ->count())->toBe(1);
});

it('peut conserver les actualisations si on le demande', function (): void {
    cotationSnapshot(OLD_DAY.' 09:00:00');
    cotationSnapshot(OLD_DAY.' 15:00:00');

    runCompaction(['--keep-refreshes' => true]);

    expect(DB::table('cotation_market_prices')->count())->toBe(1)
        ->and(DB::table('cotation_market_refreshes')->count())->toBe(2);
});

it('laisse les écrans de cotations afficher les mêmes lignes après compactage', function (): void {
    $identities = [
        ['product_code' => 'EBM', 'product_name' => 'Blé', 'product_sort' => 20, 'maturity_label' => 'Décembre 2026', 'maturity_month' => 12],
        ['product_code' => 'ECO', 'product_name' => 'Colza', 'product_sort' => 10, 'maturity_label' => 'Novembre 2026', 'maturity_month' => 11],
        ['product_code' => 'EMA', 'product_name' => 'Maïs', 'product_sort' => 30, 'maturity_label' => 'Juin 2027', 'maturity_month' => 6, 'maturity_year' => 2027, 'harvest_year' => 2027],
    ];

    // Historique ancien, puis un relevé récent : c'est lui que les écrans lisent.
    foreach (['2026-08-01', '2026-08-02'] as $day) {
        foreach (['09:00:00', '15:00:00', '20:00:00'] as $time) {
            cotationSnapshot($day.' '.$time, $identities);
        }
    }

    cotationSnapshot('2026-09-10 16:00:00', $identities);

    $service = app(CotationMarketService::class);
    $before = $service->marketOptions(2026, 2027);

    runCompaction();

    expect($service->marketOptions(2026, 2027))->toEqual($before);
});

it('rapporte les journées traitées dans la sortie de la commande', function (): void {
    foreach (['09:00:00', '15:00:00', '20:00:00'] as $time) {
        cotationSnapshot(OLD_DAY.' '.$time);
    }

    // Une attente par ligne écrite : le mock de sortie de Laravel ne fait
    // correspondre qu'une seule sous-chaîne attendue par appel d'écriture.
    $this->artisan('cotations:compact-history')
        ->expectsOutputToContain('2026-08-01 : 3 ligne(s), 1 conservée(s), 2 supprimée(s)')
        ->expectsOutputToContain('Compactage : 1 journée(s) sur')
        ->assertSuccessful();

    expect(dayRowCount(OLD_DAY))->toBe(1);
});

it('ne fait rien lorsque tout l\'historique est dans la fenêtre de rétention', function (): void {
    cotationSnapshot('2026-09-09 15:00:00');

    $this->artisan('cotations:compact-history')
        ->expectsOutputToContain('Aucune journée à compacter')
        ->assertSuccessful();

    expect(DB::table('cotation_market_prices')->count())->toBe(1);
});

it('respecte la borne --until', function (): void {
    foreach (['2026-08-01', '2026-08-05'] as $day) {
        foreach (['09:00:00', '15:00:00'] as $time) {
            cotationSnapshot($day.' '.$time);
        }
    }

    runCompaction(['--until' => '2026-08-01']);

    expect(dayRowCount('2026-08-01'))->toBe(1)
        ->and(dayRowCount('2026-08-05'))->toBe(2);
});

it('ne compacte jamais au-delà de la fenêtre protégée même avec --until', function (): void {
    foreach (['09:00:00', '15:00:00'] as $time) {
        cotationSnapshot('2026-09-09 '.$time);
    }

    // --until vise une journée protégée : elle doit être ignorée.
    runCompaction(['--until' => '2026-09-30']);

    expect(dayRowCount('2026-09-09'))->toBe(2);
});

it('supporte un volume important sans saturer la mémoire', function (): void {
    $identities = [
        ['product_code' => 'EBM', 'maturity_label' => 'Décembre 2026', 'maturity_month' => 12],
        ['product_code' => 'ECO', 'maturity_label' => 'Novembre 2026', 'maturity_month' => 11],
    ];

    // Une journée réaliste : 720 actualisations toutes les deux minutes.
    $rows = [];
    $base = CarbonImmutable::parse(OLD_DAY.' 00:00:00', 'Europe/Paris');
    $refreshId = cotationRefresh(OLD_DAY.' 00:00:00');

    for ($i = 0; $i < 720; $i++) {
        $at = $base->addMinutes($i * 2)->format('Y-m-d H:i:s');

        foreach ($identities as $identity) {
            $rows[] = array_merge([
                'refresh_id' => $refreshId,
                'product_code' => 'EBM',
                'product_name' => 'Blé',
                'product_sort' => 20,
                'contract_code' => 'EBM-DEC26',
                'maturity_label' => 'Décembre 2026',
                'maturity_month' => 12,
                'maturity_year' => 2026,
                'harvest_year' => 2026,
                'price' => 195.5,
                'raw_price' => '195,50',
                'maturity_sort' => 202612,
                'quoted_at' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ], $identity);
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('cotation_market_prices')->insert($chunk);
    }

    $peakBefore = memory_get_peak_usage(true);

    runCompaction(['--batch' => 100, '--chunk' => 250]);

    expect(dayRowCount(OLD_DAY))->toBe(2)
        ->and(memory_get_peak_usage(true) - $peakBefore)->toBeLessThan(32 * 1024 * 1024);
});

<?php

use App\Models\CotationMarketPrice;
use App\Models\CotationMarketRefresh;
use App\Services\Cotations\CotationMarketService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * cotations:refresh interroge chaque minute une source externe. Une indisponibilité brève de cette
 * source ne doit pas faire échouer la tâche planifiée ; un incident durable ou un échec définitif,
 * si. Une réponse HTTP 200 vide n'est jamais une actualisation réussie.
 */
beforeEach(function (): void {
    config([
        'cotations.refresh.attempts' => 3,
        'cotations.refresh.retry_delay_ms' => 0,
        'cotations.refresh.alert_after_minutes' => 15,
    ]);
});

const REFRESH_SOURCE = 'app2.jeudy-sa.fr/*';

function sourcePayload(): array
{
    return ['data' => [
        ['code' => 'EBM', 'expiry' => 'DEC26', 'value' => '243,25'],
        ['code' => 'EBM', 'expiry' => 'MAR27', 'value' => '244,50'],
    ]];
}

/** Délai dépassé à chaque appel ; $calls compte les tentatives effectuées. */
function sourceTimeout(?ArrayObject $calls = null): Closure
{
    return function () use ($calls): never {
        $calls?->append(1);

        throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds');
    };
}

/** Dernier relevé réussi, il y a $minutesAgo minutes. */
function lastSuccessfulRefresh(int $minutesAgo): void
{
    $at = now()->subMinutes($minutesAgo);

    DB::table('cotation_market_refreshes')->insert([
        'source_url' => 'test', 'is_success' => true, 'row_count' => 24, 'http_status' => 200,
        'fetched_at' => $at, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

it('enregistre les cours et réussit quand la source répond', function (): void {
    Http::fake([REFRESH_SOURCE => Http::response(sourcePayload())]);

    expect(Artisan::call('cotations:refresh'))->toBe(0);

    $refresh = CotationMarketRefresh::query()->latest('id')->first();
    expect($refresh->is_success)->toBeTrue()
        ->and($refresh->row_count)->toBe(2)
        ->and($refresh->error_message)->toBeNull()
        ->and(CotationMarketPrice::query()->where('refresh_id', $refresh->id)->count())->toBe(2);
});

it('retente une panne brève dans la même exécution et enregistre les cours', function (): void {
    Http::fake([REFRESH_SOURCE => Http::sequence()
        ->push('Bad gateway', 502)
        ->push('', 500)
        ->push(sourcePayload())]);

    expect(Artisan::call('cotations:refresh'))->toBe(0);

    Http::assertSentCount(3);
    $refresh = CotationMarketRefresh::query()->latest('id')->first();
    expect($refresh->is_success)->toBeTrue()
        ->and(CotationMarketPrice::query()->count())->toBe(2);
});

it('tolère une indisponibilité temporaire récente : avertissement, code 0, aucune erreur consignée', function (): void {
    lastSuccessfulRefresh(minutesAgo: 3);
    $calls = new ArrayObject;
    Http::fake([REFRESH_SOURCE => sourceTimeout($calls)]);
    Log::spy();

    expect(Artisan::call('cotations:refresh'))->toBe(0)
        ->and($calls)->toHaveCount(3);

    $refresh = CotationMarketRefresh::query()->latest('id')->first();
    expect($refresh->is_success)->toBeFalse()
        ->and($refresh->error_message)->toContain('Operation timed out')
        ->and($refresh->row_count)->toBe(0)
        ->and(CotationMarketPrice::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('error');
});

it('échoue une fois l\'indisponibilité installée depuis plus que le seuil', function (): void {
    lastSuccessfulRefresh(minutesAgo: 30);
    Http::fake([REFRESH_SOURCE => Http::response('Bad gateway', 502)]);
    Log::spy();

    expect(Artisan::call('cotations:refresh'))->toBe(1);

    Http::assertSentCount(3);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['minutes_without_update'] === 30 && $context['error'] === 'HTTP 502')->once();
});

it('échoue quand aucun cours n\'a jamais été récupéré', function (): void {
    Http::fake([REFRESH_SOURCE => Http::response('', 503)]);
    Log::spy();

    expect(Artisan::call('cotations:refresh'))->toBe(1);
    Log::shouldHaveReceived('error')->once();
});

it('échoue dès la première indisponibilité quand le seuil est à 0', function (): void {
    config(['cotations.refresh.alert_after_minutes' => 0]);
    lastSuccessfulRefresh(minutesAgo: 1);
    Http::fake([REFRESH_SOURCE => Http::response('', 500)]);

    expect(Artisan::call('cotations:refresh'))->toBe(1);
});

it('ne compte pas une réponse HTTP 200 sans cours comme une actualisation réussie', function (): void {
    Http::fake([REFRESH_SOURCE => Http::response(['data' => []])]);

    $refresh = app(CotationMarketService::class)->refreshFromEuronext();

    expect($refresh->is_success)->toBeFalse()
        ->and($refresh->isTransientFailure())->toBeTrue()
        ->and($refresh->error_message)->toContain('sans aucun cours')
        ->and($refresh->http_status)->toBe(200);
});

it('traite une erreur HTTP 4xx comme un échec définitif, sans nouvelle tentative', function (): void {
    lastSuccessfulRefresh(minutesAgo: 1);
    Http::fake([REFRESH_SOURCE => Http::response('Not found', 404)]);
    Log::spy();

    expect(Artisan::call('cotations:refresh'))->toBe(1);

    Http::assertSentCount(1);
    $refresh = CotationMarketRefresh::query()->latest('id')->first();
    expect($refresh->is_success)->toBeFalse()->and($refresh->error_message)->toBe('HTTP 404');
    Log::shouldHaveReceived('error')->once();
});

it('traite une erreur technique comme un échec définitif', function (): void {
    Http::fake([REFRESH_SOURCE => Http::response(sourcePayload())]);
    // La table des cours est absente : l'enregistrement échoue pour une raison qui n'a rien de temporaire.
    Schema::drop('cotation_market_prices');

    $refresh = app(CotationMarketService::class)->refreshFromEuronext();

    expect($refresh->is_success)->toBeFalse()
        ->and($refresh->isTransientFailure())->toBeFalse()
        ->and($refresh->failureKind)->toBe(CotationMarketRefresh::FAILURE_DEFINITIVE);
});

it('planifie l\'actualisation chaque minute avec un verrou qui expire rapidement', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'cotations:refresh'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
});

<?php

namespace App\Services\Cotations;

use App\Models\CotationCerealSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des cotations de céréales : versions complètes (instantanés).
 *
 * Stratégie : un instantané est enregistré (1) à chaque sauvegarde des
 * cotations qui change réellement le contenu visible, (2) chaque soir par
 * `cotations:snapshot-cereals`, pour que les journées sans modification
 * manuelle (les cours de marché évoluent seuls) aient aussi leur état.
 *
 * Règle pour une journée comportant plusieurs versions : on affiche la
 * DERNIÈRE de la journée (fuseau métier Europe/Paris) — l'état de fin de
 * journée ; toutes les versions restent en base.
 */
class CotationCerealHistoryService
{
    /** Champs volatils exclus de l'empreinte (changent à chaque actualisation sans changer les valeurs). */
    private const VOLATILE_KEYS = ['fetched_at', 'last_refresh'];

    public function available(): bool
    {
        return Schema::hasTable('cotation_cereal_snapshots');
    }

    public function businessTimezone(): string
    {
        return (string) config('cotations.compaction.timezone', 'Europe/Paris');
    }

    /**
     * Enregistre une version, sauf si le contenu est identique à la dernière.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(array $payload, string $source = 'edit', ?User $user = null, ?CarbonImmutable $now = null): ?CotationCerealSnapshot
    {
        if (! $this->available()) {
            return null;
        }

        $hash = $this->hash($payload);
        $latest = CotationCerealSnapshot::query()->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'payload_hash']);

        if ($latest && $latest->payload_hash === $hash) {
            return null;
        }

        $now ??= CarbonImmutable::now();

        return CotationCerealSnapshot::query()->create([
            'business_date' => $now->setTimezone($this->businessTimezone())->toDateString(),
            'payload' => $payload,
            'payload_hash' => $hash,
            'source' => $source,
            'created_by_user_id' => $user?->id,
        ]);
    }

    /**
     * Jours disposant d'au moins une version, du plus récent au plus ancien.
     *
     * @return array<int, string> dates Y-m-d
     */
    public function dates(): array
    {
        if (! $this->available()) {
            return [];
        }

        return CotationCerealSnapshot::query()
            ->select('business_date')
            ->distinct()
            ->orderByDesc('business_date')
            ->pluck('business_date')
            ->map(fn ($date) => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10))
            ->values()
            ->all();
    }

    /** Dernière version de la journée donnée (Y-m-d), ou null. */
    public function forDate(string $date): ?CotationCerealSnapshot
    {
        if (! $this->available() || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return CotationCerealSnapshot::query()
            ->with('createdBy:id,name,first_name,last_name')
            ->whereDate('business_date', $date)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        $stable = $payload;
        foreach (self::VOLATILE_KEYS as $key) {
            unset($stable[$key]);
            unset($stable['market'][$key]);
        }

        return hash('sha256', json_encode($stable, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}

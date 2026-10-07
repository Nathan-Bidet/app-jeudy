<?php

namespace App\Services\Cotations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages des cotations de céréales tels qu'ils étaient à un instant passé.
 *
 * Ces réglages (marges/bases, lignes manuelles, libellés, ordre, texte
 * d'information, grille de transport, années de récolte) sont modifiés sur
 * place : aucune table ne les versionne. Leur seule trace est le journal
 * d'audit (`audit_logs`, module « cotations »), qui enregistre pour chaque
 * sauvegarde l'état AVANT et APRÈS de ce qui a changé.
 *
 * Reconstitution sans copie ni valeur inventée : on part de l'état ACTUEL puis
 * on DÉFAIT, du plus récent au plus ancien, chaque modification enregistrée
 * après l'instant demandé (on remet son état « avant »). Ce qui n'a jamais été
 * modifié depuis garde sa valeur actuelle, qui est donc la valeur d'alors.
 *
 * Limites, signalées dans `warnings` : le journal tronque les chaînes à 4 000
 * caractères (un texte d'information plus long n'est pas fiable) et ne voit pas
 * les changements faits hors de l'interface (migrations, commandes).
 */
class CotationConfigHistoryService
{
    private const ACTIONS = ['update_cotation_settings', 'update_cotation_settings_and_manual_prices'];

    /** Longueur à laquelle AuditLogService tronque les chaînes. */
    private const AUDIT_STRING_LIMIT = 4000;

    /**
     * @param  array{
     *     manual_lines: array<string, array<string, mixed>>,
     *     custom_cereals: array<string, array<string, mixed>>,
     *     cereal_order: array<int, string>,
     *     cereal_labels: array<string, mixed>,
     *     cereal_table_labels: array<string, mixed>,
     *     cereal_info: string,
     *     transport_grid: array<string, mixed>,
     *     harvest_years: array{left:int,right:int}
     * }  $current  état actuel
     * @return array<string, mixed> même forme, plus `warnings` (liste de messages)
     */
    public function configAt(CarbonImmutable $at, array $current): array
    {
        $state = $current;
        $state['warnings'] = [];

        foreach ($this->logsAfter($at) as $payload) {
            $this->undo($state, $payload);
        }

        return $state;
    }

    /**
     * Réglages à plusieurs instants en UNE lecture du journal : on remonte le temps
     * du plus récent au plus ancien en défaisant les modifications au fur et à mesure.
     *
     * @param  array<string, CarbonImmutable>  $instants  clé (ex. date) => instant
     * @param  array<string, mixed>  $current  cf. configAt()
     * @return array<string, array<string, mixed>> clé => état
     */
    public function statesAt(array $instants, array $current): array
    {
        if ($instants === []) {
            return [];
        }

        $storage = (string) config('app.timezone');
        $format = static fn (CarbonImmutable $at): string => $at->setTimezone($storage)->format('Y-m-d H:i:s');
        uasort($instants, fn (CarbonImmutable $a, CarbonImmutable $b): int => $b <=> $a);
        $earliest = $format(end($instants));

        $logs = Schema::hasTable('audit_logs')
            ? DB::table('audit_logs')
                ->where('module', 'cotations')
                ->whereIn('action', self::ACTIONS)
                ->where('created_at', '>', $earliest)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['created_at', 'payload'])
            : collect();

        $state = $current;
        $state['warnings'] = [];
        $index = 0;
        $result = [];

        foreach ($instants as $key => $at) {
            $limit = $format($at);

            while ($index < $logs->count() && (string) $logs[$index]->created_at > $limit) {
                $payload = is_string($logs[$index]->payload) ? json_decode($logs[$index]->payload, true) : null;
                if (is_array($payload)) {
                    $this->undo($state, $payload);
                }
                $index++;
            }

            $result[$key] = $state;
        }

        return $result;
    }

    /** Premier enregistrement d'audit des cotations (début de la couverture), ou null. */
    public function firstAuditAt(): ?CarbonImmutable
    {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $first = DB::table('audit_logs')->where('module', 'cotations')->whereIn('action', self::ACTIONS)->min('created_at');

        return $first ? CarbonImmutable::parse((string) $first, (string) config('app.timezone')) : null;
    }

    /**
     * @return \Generator<int, array<string, mixed>> charges utiles, de la plus récente à la plus ancienne
     */
    private function logsAfter(CarbonImmutable $at): \Generator
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $rows = DB::table('audit_logs')
            ->where('module', 'cotations')
            ->whereIn('action', self::ACTIONS)
            ->where('created_at', '>', $at->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['payload']);

        foreach ($rows as $row) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;

            if (is_array($payload)) {
                yield $payload;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     */
    private function undo(array &$state, array $payload): void
    {
        foreach ((array) ($payload['manual_prices'] ?? []) as $change) {
            $before = is_array($change['before'] ?? null) ? $change['before'] : null;
            $after = is_array($change['after'] ?? null) ? $change['after'] : null;

            if ($after && isset($after['identity_hash'])) {
                unset($state['manual_lines'][$after['identity_hash']]);
            }
            if ($before && isset($before['identity_hash'])) {
                $state['manual_lines'][$before['identity_hash']] = $before;
            }
        }

        foreach ((array) ($payload['deleted_manual_prices'] ?? []) as $snapshot) {
            if (is_array($snapshot) && isset($snapshot['identity_hash'])) {
                $state['manual_lines'][$snapshot['identity_hash']] = $snapshot;
            }
        }

        foreach ((array) ($payload['custom_cereals'] ?? []) as $change) {
            $before = is_array($change['before'] ?? null) ? $change['before'] : null;
            $after = is_array($change['after'] ?? null) ? $change['after'] : null;

            if ($after && isset($after['code'])) {
                unset($state['custom_cereals'][$after['code']]);
            }
            if ($before && isset($before['code'])) {
                $state['custom_cereals'][$before['code']] = $before;
            }
        }

        foreach (['cereal_order', 'cereal_labels', 'cereal_table_labels'] as $key) {
            if (is_array($payload[$key]['before'] ?? null)) {
                $state[$key] = $payload[$key]['before'];
            }
        }

        if (is_array($payload['transport_grid'] ?? null) && is_array($payload['transport_grid']['before'] ?? null)) {
            $state['transport_grid'] = $payload['transport_grid']['before'];
        }

        if (is_array($payload['cereal_info'] ?? null) && array_key_exists('before', $payload['cereal_info'])) {
            $before = (string) $payload['cereal_info']['before'];
            $state['cereal_info'] = $before;

            if (mb_strlen($before) >= self::AUDIT_STRING_LIMIT && ! in_array('cereal_info_truncated', $state['warnings'], true)) {
                $state['warnings'][] = 'cereal_info_truncated';
            }
        }

        foreach ((array) ($payload['before'] ?? []) as $setting) {
            $key = $setting['key'] ?? null;

            if ($key === 'harvest_left_year' && isset($setting['value'])) {
                $state['harvest_years']['left'] = (int) $setting['value'];
            }
            if ($key === 'harvest_right_year' && isset($setting['value'])) {
                $state['harvest_years']['right'] = (int) $setting['value'];
            }
        }
    }
}

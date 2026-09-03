<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Lecture et écriture des réglages applicatifs.
 *
 * Source de vérité unique : la table `app_settings`. Le frontend reçoit la
 * valeur pour l'afficher, jamais pour en décider — toutes les règles qui
 * dépendent d'un réglage sont évaluées côté serveur.
 *
 * Les valeurs sont mémorisées le temps de la requête : un réglage consulté à
 * chaque enregistrement d'heures ou de congé ne doit pas coûter une requête
 * SQL à chaque fois.
 */
class AppSettings
{
    /** @var array<string, ?string> */
    private array $cache = [];

    private bool $tableChecked = false;

    private bool $tableExists = false;

    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if (! $this->tableIsAvailable()) {
            return $this->cache[$key] = null;
        }

        $value = AppSetting::query()->where('key', $key)->value('value');

        return $this->cache[$key] = ($value !== null ? (string) $value : null);
    }

    public function set(string $key, ?string $value, ?User $actor = null): void
    {
        $normalized = $value !== null && trim($value) !== '' ? trim($value) : null;

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $normalized, 'updated_by' => $actor?->id],
        );

        $this->cache[$key] = $normalized;
    }

    /**
     * Réglage interprété comme une date, dans le fuseau de l'application.
     *
     * Renvoie toujours un début de journée : les comparaisons portent sur le
     * jour, jamais sur l'heure.
     */
    public function date(string $key): ?CarbonImmutable
    {
        $raw = $this->get($key);

        if ($raw === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw, config('app.timezone', 'Europe/Paris'))->startOfDay();
        } catch (\Throwable) {
            // Une valeur illisible ne doit pas faire tomber une page : elle est
            // traitée comme absente, et l'administration pourra la corriger.
            return null;
        }
    }

    /**
     * Vide le cache de requête — utile après une écriture faite ailleurs, et
     * dans les tests.
     */
    public function forget(?string $key = null): void
    {
        if ($key === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$key]);
    }

    /**
     * La table peut manquer avant que les migrations ne soient jouées : lire un
     * réglage ne doit alors pas empêcher l'application de démarrer.
     */
    private function tableIsAvailable(): bool
    {
        if (! $this->tableChecked) {
            $this->tableExists = Schema::hasTable('app_settings');
            $this->tableChecked = true;
        }

        return $this->tableExists;
    }
}

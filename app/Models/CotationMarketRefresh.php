<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CotationMarketRefresh extends Model
{
    public const FAILURE_TRANSIENT = 'transient';

    public const FAILURE_DEFINITIVE = 'definitive';

    /**
     * Nature de l'échec de l'exécution en cours (non persistée) : indisponibilité
     * temporaire de la source ou échec définitif. Null si l'actualisation a réussi.
     */
    public ?string $failureKind = null;

    protected $fillable = [
        'source_url',
        'is_success',
        'http_status',
        'row_count',
        'error_message',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'is_success' => 'boolean',
            'http_status' => 'integer',
            'row_count' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function isTransientFailure(): bool
    {
        return ! $this->is_success && $this->failureKind === self::FAILURE_TRANSIENT;
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CotationMarketPrice::class, 'refresh_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce jointe temporaire d'un brouillon de courriel des cotations.
 * Le fichier vit sur un disque privé sous cotation-mail/{draft_id}/{stored_name}.
 */
class CotationMailAttachment extends Model
{
    public const KIND_PDF = 'pdf';

    public const KIND_FILE = 'file';

    protected $fillable = [
        'user_id',
        'draft_id',
        'kind',
        'original_name',
        'stored_name',
        'mime_type',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storagePath(): string
    {
        return 'cotation-mail/'.$this->draft_id.'/'.$this->stored_name;
    }

    /**
     * Représentation envoyée au navigateur : jamais de chemin de stockage.
     *
     * @return array<string, mixed>
     */
    public function toClient(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->original_name,
            'type' => $this->mime_type,
            'size' => $this->size_bytes,
        ];
    }
}

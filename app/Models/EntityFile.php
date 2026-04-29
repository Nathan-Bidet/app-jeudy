<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EntityFile extends Model
{
    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'uploaded_by_user_id',
        'original_name',
        'display_name',
        'disk',
        'path',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum_sha256',
        'version_group',
        'version_number',
    ];

    protected function casts(): array
    {
        return [
            'attachable_id' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'size_bytes' => 'integer',
            'version_number' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

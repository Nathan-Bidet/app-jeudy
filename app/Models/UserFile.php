<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFile extends Model
{
    protected $fillable = [
        'user_id',
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
            'size_bytes' => 'integer',
            'version_number' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

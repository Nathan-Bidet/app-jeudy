<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementRecipientGroup extends Model
{
    protected $fillable = [
        'name',
        'sector_ids',
        'user_ids',
        'excluded_user_ids',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'sector_ids' => 'array',
            'user_ids' => 'array',
            'excluded_user_ids' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

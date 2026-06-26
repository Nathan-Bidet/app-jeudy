<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Announcement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENT = 'sent';

    protected $fillable = [
        'created_by_user_id',
        'body_html',
        'status',
        'sector_ids',
        'user_ids',
        'excluded_user_ids',
        'scheduled_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sector_ids' => 'array',
            'user_ids' => 'array',
            'excluded_user_ids' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function poll(): HasOne
    {
        return $this->hasOne(AnnouncementPoll::class);
    }
}

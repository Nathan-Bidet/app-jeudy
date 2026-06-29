<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementPollResponse extends Model
{
    protected $fillable = [
        'announcement_id',
        'announcement_poll_id',
        'user_id',
        'selected_option_ids',
        'other_text',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'responded_at' => 'datetime',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(AnnouncementPoll::class, 'announcement_poll_id');
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

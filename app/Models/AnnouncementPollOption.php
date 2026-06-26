<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementPollOption extends Model
{
    protected $fillable = [
        'announcement_poll_id',
        'label',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(AnnouncementPoll::class, 'announcement_poll_id');
    }
}

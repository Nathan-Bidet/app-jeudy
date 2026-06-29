<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnnouncementPoll extends Model
{
    public const TYPE_SINGLE = 'single';

    public const TYPE_MULTIPLE = 'multiple';

    protected $fillable = [
        'announcement_id',
        'poll_type',
        'allow_other',
        'other_label',
    ];

    protected function casts(): array
    {
        return [
            'allow_other' => 'boolean',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(AnnouncementPollOption::class)->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AnnouncementPollResponse::class);
    }
}

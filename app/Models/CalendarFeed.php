<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarFeed extends Model
{
    protected $table = 'calendar_feeds';

    protected $fillable = [
        'name',
        'url',
        'color',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}


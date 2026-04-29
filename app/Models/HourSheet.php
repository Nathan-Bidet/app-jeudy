<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HourSheet extends Model
{
    protected $fillable = [
        'user_id',
        'work_date',
        'morning_start',
        'morning_end',
        'afternoon_start',
        'afternoon_end',
        'total_minutes',
        'has_breakfast_before_5',
        'has_lunch',
        'has_dinner_after_21',
        'has_long_night',
    ];

    protected $casts = [
        'work_date' => 'date',
        'has_breakfast_before_5' => 'boolean',
        'has_lunch' => 'boolean',
        'has_dinner_after_21' => 'boolean',
        'has_long_night' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

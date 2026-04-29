<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportDriverFreeMapping extends Model
{
    protected $fillable = [
        'old_driver_free',
        'new_user_id',
        'target_type',
        'target_id',
    ];

    protected function casts(): array
    {
        return [
            'new_user_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    public function newUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }
}

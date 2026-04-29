<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportUserMapping extends Model
{
    protected $fillable = [
        'old_user_id',
        'new_user_id',
        'source_column',
        'target_type',
        'target_id',
    ];

    protected function casts(): array
    {
        return [
            'old_user_id' => 'integer',
            'new_user_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    public function newUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTiersImportConfig extends Model
{
    protected $fillable = [
        'name',
        'original_filename',
        'columns',
        'identification_column',
        'reference_column',
        'options',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'options' => 'array',
            'created_by_user_id' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(TaskTiersRecord::class, 'import_config_id');
    }
}

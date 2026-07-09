<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiersImportJob extends Model
{
    protected $table = 'tiers_import_jobs';

    protected $fillable = [
        'import_config_id',
        'created_by_user_id',
        'status',
        'original_filename',
        'disk',
        'file_path',
        'progress',
        'current_line',
        'total_lines',
        'message',
        'options',
        'stats',
        'error',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'import_config_id' => 'integer',
            'created_by_user_id' => 'integer',
            'progress' => 'integer',
            'current_line' => 'integer',
            'total_lines' => 'integer',
            'options' => 'array',
            'stats' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(TaskTiersImportConfig::class, 'import_config_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

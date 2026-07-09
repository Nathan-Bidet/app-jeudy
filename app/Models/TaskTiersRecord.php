<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTiersRecord extends Model
{
    protected $fillable = [
        'import_config_id',
        'source_row_hash',
        'primary_identifier',
        'reference_value',
        'data',
        'search_text',
        'imported_by_user_id',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'import_config_id' => 'integer',
            'imported_by_user_id' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function importConfig(): BelongsTo
    {
        return $this->belongsTo(TaskTiersImportConfig::class, 'import_config_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}

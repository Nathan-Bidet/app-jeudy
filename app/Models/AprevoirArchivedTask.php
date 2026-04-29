<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AprevoirArchivedTask extends Model
{
    use HasFactory;

    protected $table = 'a_prevoir_tasks_archive';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'pointed' => 'boolean',
            'pointed_at' => 'datetime',
            'is_direct' => 'boolean',
            'is_boursagri' => 'boolean',
            'indicators' => 'array',
            'archived_at' => 'datetime',
            'archived_by_system' => 'boolean',
        ];
    }
}


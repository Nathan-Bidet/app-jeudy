<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotationSetting extends Model
{
    protected $fillable = [
        'section',
        'key',
        'label',
        'value',
        'unit',
        'note',
        'sort_order',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'sort_order' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

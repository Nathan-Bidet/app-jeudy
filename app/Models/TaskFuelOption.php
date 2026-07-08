<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskFuelOption extends Model
{
    public const KIND_SITE = 'site';
    public const KIND_PRODUCT_TYPE = 'product_type';

    protected $fillable = [
        'kind',
        'depot_id',
        'label',
        'active',
        'sort_order',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'depot_id' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}

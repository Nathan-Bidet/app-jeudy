<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormattingRule extends Model
{
    protected $table = 'a_prevoir_color_rules';

    protected $fillable = [
        'name',
        'scope',
        'match_type',
        'pattern',
        'text_color',
        'bg_color',
        'priority',
        'is_active',
        'applies_to_a_prevoir',
        'applies_to_ldt',
        'description',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'applies_to_a_prevoir' => 'boolean',
            'applies_to_ldt' => 'boolean',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForTarget(Builder $query, string $targetModule): Builder
    {
        return match ($targetModule) {
            'a_prevoir' => $query->where('applies_to_a_prevoir', true),
            'ldt' => $query->where('applies_to_ldt', true),
            default => $query->whereRaw('1 = 0'),
        };
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

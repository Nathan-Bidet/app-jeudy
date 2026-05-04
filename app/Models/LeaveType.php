<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class LeaveType extends Model
{
    protected $fillable = [
        'name',
        'max_days',
        'sort_order',
        'is_active',
    ];

    public function userVisibilities(): HasMany
    {
        return $this->hasMany(LeaveTypeUserVisibility::class);
    }

    public function scopeVisibleForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $builder) use ($userId): void {
            $builder
                ->whereDoesntHave('userVisibilities')
                ->orWhereHas('userVisibilities', fn (Builder $visibilityQuery) => $visibilityQuery->where('user_id', $userId));
        });
    }
}

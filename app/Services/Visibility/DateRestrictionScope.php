<?php

namespace App\Services\Visibility;

use App\Models\User;
use App\Support\Access\AccessManager;
use Illuminate\Database\Eloquent\Builder;

class DateRestrictionScope
{
    public static function apply(Builder $query, User $user, string $moduleKey): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        $ability = trim($moduleKey).'.view.current_week_only';

        if ($ability === '.view.current_week_only') {
            return $query;
        }

        $accessManager = app(AccessManager::class);

        if (! $accessManager->can($user, $ability)) {
            return $query;
        }

        $startOfWeek = now()->startOfWeek();

        return $query->whereDate('date', '>=', $startOfWeek);
    }
}


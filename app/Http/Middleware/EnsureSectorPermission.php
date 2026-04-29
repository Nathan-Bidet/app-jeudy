<?php

namespace App\Http\Middleware;

use App\Models\Sector;
use App\Models\User;
use App\Support\Access\AccessManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSectorPermission
{
    public function __construct(private readonly AccessManager $accessManager)
    {
    }

    public function handle(Request $request, Closure $next, string $ability, ?string $routeParameter = null): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $targetSectorId = $this->resolveTargetSectorId($request, $routeParameter);
        $abilities = array_values(array_filter(array_map('trim', explode('|', $ability))));
        $isAllowed = false;

        foreach ($abilities as $candidateAbility) {
            if ($this->accessManager->can($user, $candidateAbility, $targetSectorId)) {
                $isAllowed = true;
                break;
            }
        }

        if (! $isAllowed) {
            abort(403);
        }

        return $next($request);
    }

    private function resolveTargetSectorId(Request $request, ?string $routeParameter): ?int
    {
        if (! $routeParameter) {
            return null;
        }

        $value = $request->route($routeParameter);

        if ($value instanceof Sector) {
            return $value->id;
        }

        if ($value instanceof User) {
            return $value->sector_id;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs('two-factor.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        if (! $user->totp_secret || ! $user->totp_enabled_at) {
            return redirect()->route('two-factor.setup');
        }

        if (! $this->hasFreshTwoFactorSession($request, $user->id)) {
            $request->session()->forget([
                'two_factor_passed',
                'two_factor_passed_user_id',
                'two_factor_passed_at',
                'two_factor_last_activity_at',
            ]);

            return redirect()->route('two-factor.verify');
        }

        return $next($request);
    }

    private function hasFreshTwoFactorSession(Request $request, int $userId): bool
    {
        if (
            ! $request->session()->get('two_factor_passed')
            || (int) $request->session()->get('two_factor_passed_user_id') !== $userId
        ) {
            return false;
        }

        $graceMinutes = max(0, (int) config('auth.two_factor_grace_minutes', 0));
        if ($graceMinutes <= 0) {
            $request->session()->put('two_factor_last_activity_at', now()->getTimestamp());
            return true;
        }

        $lastActivity = (int) $request->session()->get(
            'two_factor_last_activity_at',
            (int) $request->session()->get('two_factor_passed_at', now()->getTimestamp())
        );

        if ($lastActivity <= 0) {
            $lastActivity = now()->getTimestamp();
        }

        $isFresh = (now()->getTimestamp() - $lastActivity) <= ($graceMinutes * 60);

        if ($isFresh) {
            $request->session()->put('two_factor_last_activity_at', now()->getTimestamp());
        }

        return $isFresh;
    }
}

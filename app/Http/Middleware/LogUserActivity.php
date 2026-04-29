<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldLog($request, $response)) {
            return $response;
        }

        $routeName = (string) ($request->route()?->getName() ?? '');

        $this->auditLogService->logAfterResponse([
            'action' => 'view_page',
            'module' => $this->moduleFromRoute($routeName),
            'description' => $routeName !== '' ? "Consultation de page: {$routeName}" : 'Consultation de page',
            'payload' => [
                'route_name' => $routeName !== '' ? $routeName : null,
            ],
        ]);

        return $response;
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if (! $request->user()) {
            return false;
        }

        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return false;
        }

        if ($request->header('X-Inertia-Partial-Component') || $request->header('X-Inertia-Partial-Data')) {
            return false;
        }

        $path = trim($request->path(), '/');
        if ($path === 'up' || str_contains($path, 'heartbeat') || str_contains($path, 'ping') || str_contains($path, 'reverb') || str_contains($path, 'broadcasting')) {
            return false;
        }

        $routeName = (string) ($request->route()?->getName() ?? '');
        if ($routeName === '' || str_contains($routeName, 'files.') || str_contains($routeName, 'download') || str_contains($routeName, 'preview') || str_contains($routeName, 'vcard')) {
            return false;
        }

        $varyHeader = (string) $response->headers->get('Vary', '');
        $isInertiaResponse = str_contains($varyHeader, 'X-Inertia') || (bool) $request->header('X-Inertia');

        return $isInertiaResponse;
    }

    private function moduleFromRoute(string $routeName): string
    {
        if (str_starts_with($routeName, 'a_prevoir.')) return 'a_prevoir';
        if (str_starts_with($routeName, 'ldt.')) return 'ldt';
        if (str_starts_with($routeName, 'admin.')) return 'admin';
        if (str_starts_with($routeName, 'task.formatting.')) return 'formatting';
        if (str_starts_with($routeName, 'directory.')) return 'directory';
        if (str_starts_with($routeName, 'profile.') || str_starts_with($routeName, 'settings.')) return 'profile';
        if (str_starts_with($routeName, 'login') || str_starts_with($routeName, 'password.') || str_starts_with($routeName, 'twofactor.')) return 'auth';
        if ($routeName === 'dashboard') return 'dashboard';

        return 'app';
    }
}

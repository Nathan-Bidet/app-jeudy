<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Http\Middleware\EnsureSectorPermission;
use App\Http\Middleware\LogUserActivity;
use App\Services\AuditLogService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'twofactor' => EnsureTwoFactorIsVerified::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'sector.access' => EnsureSectorPermission::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureTwoFactorIsVerified::class,
            LogUserActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $forbiddenMessage = 'Action non autorisee.';

        $exceptions->report(function (Throwable $exception): void {
            if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                return;
            }

            if ($exception instanceof HttpException && $exception->getStatusCode() < 500) {
                return;
            }

            app(AuditLogService::class)->log([
                'action' => 'error',
                'module' => 'system',
                'description' => $exception->getMessage(),
                'payload' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ],
            ]);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($forbiddenMessage) {
            if ($request->expectsJson()) {
                return null;
            }

            if (! $request->isMethod('GET')) {
                return back()->with('error', $forbiddenMessage);
            }

            return redirect()->route('dashboard')->with('error', 'Acces non autorise.');
        });

        $exceptions->render(function (HttpException $exception, Request $request) use ($forbiddenMessage) {
            if ($exception->getStatusCode() !== 403 || $request->expectsJson()) {
                return null;
            }

            if (! $request->isMethod('GET')) {
                return back()->with('error', $forbiddenMessage);
            }

            return redirect()->route('dashboard')->with('error', 'Acces non autorise.');
        });
    })->create();

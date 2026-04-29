<?php

namespace App\Providers;

use App\Events\AprevoirTaskChanged;
use App\Listeners\UpdateLdtProjectionListener;
use App\Models\User;
use App\Models\UserFile;
use App\Models\Garage;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\Depot;
use App\Models\FormattingRule;
use App\Models\Transporter;
use App\Policies\DirectoryPolicy;
use App\Policies\DepotPolicy;
use App\Policies\FormattingRulePolicy;
use App\Policies\GaragePolicy;
use App\Policies\TransporterPolicy;
use App\Policies\VehiclePolicy;
use App\Policies\VehicleTypePolicy;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        Event::listen(AprevoirTaskChanged::class, UpdateLdtProjectionListener::class);
        $resolveUserName = static function ($user): ?string {
            if (! $user) {
                return null;
            }

            $firstName = trim((string) ($user->first_name ?? ''));
            $lastName = trim((string) ($user->last_name ?? ''));
            $fullName = trim($firstName.' '.$lastName);

            if ($fullName !== '') {
                return $fullName;
            }

            $fallback = trim((string) ($user->name ?? ''));
            if ($fallback !== '') {
                return $fallback;
            }

            $email = trim((string) ($user->email ?? ''));

            return $email !== '' ? $email : null;
        };

        Event::listen(Login::class, function (Login $event) use ($resolveUserName): void {
            app(AuditLogService::class)->log([
                'action' => 'login',
                'module' => 'auth',
                'description' => 'Connexion reussie',
                'payload' => [
                    'guard' => $event->guard,
                    'user_id' => $event->user?->id,
                ],
                'user_name' => $resolveUserName($event->user),
            ]);
        });
        Event::listen(Failed::class, function (Failed $event): void {
            app(AuditLogService::class)->log([
                'action' => 'login_failed',
                'module' => 'auth',
                'description' => 'Echec de connexion',
                'payload' => [
                    'guard' => $event->guard,
                    'email' => $event->credentials['email'] ?? null,
                ],
                'user_name' => trim((string) ($event->credentials['email'] ?? '')) ?: null,
            ]);
        });
        Event::listen(Logout::class, function (Logout $event) use ($resolveUserName): void {
            app(AuditLogService::class)->log([
                'action' => 'logout',
                'module' => 'auth',
                'description' => 'Deconnexion',
                'payload' => [
                    'guard' => $event->guard,
                    'user_id' => $event->user?->id,
                ],
                'user_id' => $event->user?->id,
                'user_name' => $resolveUserName($event->user),
            ]);
        });
        Event::listen(PasswordReset::class, function (PasswordReset $event) use ($resolveUserName): void {
            app(AuditLogService::class)->log([
                'action' => 'reset_password',
                'module' => 'auth',
                'description' => 'Mot de passe reinitialise',
                'payload' => [
                    'user_id' => $event->user?->id,
                    'email' => $event->user?->email,
                ],
                'user_id' => $event->user?->id,
                'user_name' => $resolveUserName($event->user),
            ]);
        });

        $reverbScheme = env('REVERB_SCHEME', 'http');
        $reverbPort = (int) env('REVERB_PORT', 8080);
        $reverbHost = env('REVERB_BROADCAST_HOST', env('REVERB_HOST', 'reverb'));

        // Enforce Reverb target at runtime to avoid stale cached config pointing to localhost.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.options.host' => $reverbHost ?: 'reverb',
            'broadcasting.connections.reverb.options.port' => $reverbPort > 0 ? $reverbPort : 8080,
            'broadcasting.connections.reverb.options.scheme' => $reverbScheme,
            'broadcasting.connections.reverb.options.useTLS' => $reverbScheme === 'https',
        ]);

        Gate::policy(User::class, DirectoryPolicy::class);
        Gate::policy(UserFile::class, DirectoryPolicy::class);
        Gate::policy(VehicleType::class, VehicleTypePolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(Depot::class, DepotPolicy::class);
        Gate::policy(Garage::class, GaragePolicy::class);
        Gate::policy(Transporter::class, TransporterPolicy::class);
        Gate::policy(FormattingRule::class, FormattingRulePolicy::class);

        Gate::before(function ($user, string $ability) {
            if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
                return true;
            }

            return null;
        });

        RateLimiter::for('totp-setup', function (Request $request) {
            return Limit::perMinute(6)->by(
                ($request->user()?->id ?? 'guest').'|'.$request->ip()
            );
        });

        RateLimiter::for('totp-verify', function (Request $request) {
            return Limit::perMinute(6)->by(
                ($request->user()?->id ?? 'guest').'|'.$request->ip()
            );
        });
    }
}

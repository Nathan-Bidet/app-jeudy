<?php

namespace App\Http\Controllers;

use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $isResetFlow = (bool) $request->session()->get('two_factor_reset_flow', false);

        if ($this->isTotpEnabled($user) && ! $isResetFlow) {
            if ($this->isSessionVerified($request, $user)) {
                return redirect()->route('dashboard');
            }

            return redirect()->route('two-factor.verify');
        }

        $pendingSecret = $request->session()->get('two_factor_pending_secret');

        if (! is_string($pendingSecret) || $pendingSecret === '') {
            $pendingSecret = $this->totp->generateSecret();
            $request->session()->put('two_factor_pending_secret', $pendingSecret);
        }

        return Inertia::render('Auth/TwoFactorSetup', [
            'secret' => $this->totp->formatSecret($pendingSecret),
            'otpauthUri' => $this->totp->provisioningUri(
                accountName: $user->email,
                issuer: config('app.name', 'Laravel'),
                secret: $pendingSecret,
            ),
            'status' => session('status'),
            'lockedUntil' => $this->lockedUntilIso($user),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $isResetFlow = (bool) $request->session()->get('two_factor_reset_flow', false);

        if ($this->isTotpEnabled($user) && ! $isResetFlow) {
            return redirect()->route('two-factor.verify');
        }

        $this->ensureNotLocked($user);

        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
            'generate_recovery_codes' => ['nullable', 'boolean'],
        ]);

        $pendingSecret = $request->session()->get('two_factor_pending_secret');

        if (! is_string($pendingSecret) || $pendingSecret === '') {
            throw ValidationException::withMessages([
                'code' => __('Enrollment expired. Please refresh and scan again.'),
            ]);
        }

        if (! $this->totp->verifyCode($pendingSecret, $validated['code'])) {
            $this->registerFailedAttempt($user);

            throw ValidationException::withMessages([
                'code' => __('Invalid authenticator code.'),
            ]);
        }

        $recoveryCodes = $request->boolean('generate_recovery_codes')
            ? $this->totp->generateRecoveryCodes()
            : [];

        $user->forceFill([
            'totp_secret' => Crypt::encryptString($pendingSecret),
            'totp_enabled_at' => now(),
            'totp_attempts' => 0,
            'totp_locked_until' => null,
            'totp_recovery_codes' => $this->encryptRecoveryCodes($recoveryCodes),
        ])->save();

        $request->session()->forget('two_factor_pending_secret');
        $request->session()->forget('two_factor_reset_flow');
        $this->markSessionVerified($request, $user);

        $this->writeAudit($request, $user, $isResetFlow ? '2fa_reset_completed' : '2fa_enabled', [
            'recovery_codes_count' => count($recoveryCodes),
        ]);

        return redirect()
            ->route('two-factor.recovery-codes')
            ->with('status', __('Two-factor authentication is now active.'));
    }

    /**
     * @throws ValidationException
     */
    public function initiateReset(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->isTotpEnabled($user)) {
            return redirect()->route('two-factor.setup');
        }

        $this->ensureNotLocked($user);

        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $secret = $this->decryptSecret($user);

        if ($secret === null || ! $this->totp->verifyCode($secret, $validated['code'])) {
            $this->registerFailedAttempt($user);

            throw ValidationException::withMessages([
                'code' => __('Invalid authenticator code.'),
            ]);
        }

        $this->resetFailedAttempts($user);

        $request->session()->put('two_factor_reset_flow', true);
        $request->session()->put('two_factor_pending_secret', $this->totp->generateSecret());

        $this->writeAudit($request, $user, '2fa_reset_started');

        return redirect()
            ->route('two-factor.setup')
            ->with('status', __('Scan the new QR code to complete your TOTP reset.'));
    }

    public function showVerify(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $this->isTotpEnabled($user)) {
            return redirect()->route('two-factor.setup');
        }

        if ($this->isSessionVerified($request, $user)) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/TwoFactorVerify', [
            'status' => session('status'),
            'lockedUntil' => $this->lockedUntilIso($user),
            'hasRecoveryCodes' => count($this->getRecoveryCodes($user)) > 0,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->isTotpEnabled($user)) {
            return redirect()->route('two-factor.setup');
        }

        $this->ensureNotLocked($user);

        $validated = $request->validate([
            'code' => ['nullable', 'digits:6', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
        ]);

        $isValid = false;
        $usedRecoveryCode = false;

        if (! empty($validated['code'])) {
            $secret = $this->decryptSecret($user);
            $isValid = $secret !== null && $this->totp->verifyCode($secret, $validated['code']);
        }

        if (! $isValid && ! empty($validated['recovery_code'])) {
            $isValid = $this->consumeRecoveryCode($user, $validated['recovery_code']);
            $usedRecoveryCode = $isValid;
        }

        if (! $isValid) {
            $this->registerFailedAttempt($user);

            throw ValidationException::withMessages([
                'code' => __('Invalid authentication code.'),
                'recovery_code' => __('Invalid recovery code.'),
            ]);
        }

        $this->resetFailedAttempts($user);
        $this->markSessionVerified($request, $user);

        if ($usedRecoveryCode) {
            $this->writeAudit($request, $user, '2fa_recovery_code_used');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function showRecoveryCodes(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $this->isTotpEnabled($user)) {
            return redirect()->route('two-factor.setup');
        }

        return Inertia::render('Auth/TwoFactorRecoveryCodes', [
            'codes' => $this->getRecoveryCodes($user),
            'status' => session('status'),
        ]);
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->isTotpEnabled($user)) {
            return redirect()->route('two-factor.setup');
        }

        $codes = $this->totp->generateRecoveryCodes();

        $user->forceFill([
            'totp_recovery_codes' => $this->encryptRecoveryCodes($codes),
        ])->save();

        $this->writeAudit($request, $user, '2fa_recovery_codes_regenerated', [
            'recovery_codes_count' => count($codes),
        ]);

        return redirect()
            ->route('two-factor.recovery-codes')
            ->with('status', __('Recovery codes were regenerated.'));
    }

    /**
     * @throws ValidationException
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->isTotpEnabled($user)) {
            return redirect()->route('two-factor.setup');
        }

        $this->ensureNotLocked($user);

        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $secret = $this->decryptSecret($user);

        if ($secret === null || ! $this->totp->verifyCode($secret, $validated['code'])) {
            $this->registerFailedAttempt($user);

            throw ValidationException::withMessages([
                'code' => __('Invalid authenticator code.'),
            ]);
        }

        $user->forceFill([
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_attempts' => 0,
            'totp_locked_until' => null,
            'totp_recovery_codes' => null,
        ])->save();

        $request->session()->forget([
            'two_factor_passed',
            'two_factor_passed_user_id',
            'two_factor_passed_at',
            'two_factor_last_activity_at',
            'two_factor_pending_secret',
            'two_factor_reset_flow',
        ]);

        $this->writeAudit($request, $user, '2fa_disabled');

        return redirect()
            ->route('two-factor.setup')
            ->with('status', __('Two-factor authentication was disabled. Re-enrollment is required.'));
    }

    private function isSessionVerified(Request $request, User $user): bool
    {
        if (
            ! $request->session()->get('two_factor_passed')
            || (int) $request->session()->get('two_factor_passed_user_id') !== $user->id
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

        if (! $isFresh) {
            $request->session()->forget([
                'two_factor_passed',
                'two_factor_passed_user_id',
                'two_factor_passed_at',
                'two_factor_last_activity_at',
            ]);
            return false;
        }

        $request->session()->put('two_factor_last_activity_at', now()->getTimestamp());

        return true;
    }

    private function markSessionVerified(Request $request, User $user): void
    {
        $request->session()->put('two_factor_passed', true);
        $request->session()->put('two_factor_passed_user_id', $user->id);
        $request->session()->put('two_factor_passed_at', now()->getTimestamp());
        $request->session()->put('two_factor_last_activity_at', now()->getTimestamp());
    }

    private function isTotpEnabled(User $user): bool
    {
        return ! empty($user->totp_secret) && ! empty($user->totp_enabled_at);
    }

    private function decryptSecret(User $user): ?string
    {
        if (! $user->totp_secret) {
            return null;
        }

        try {
            return Crypt::decryptString($user->totp_secret);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function getRecoveryCodes(User $user): array
    {
        if (! $user->totp_recovery_codes) {
            return [];
        }

        try {
            $decoded = json_decode(
                Crypt::decryptString($user->totp_recovery_codes),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn ($code): bool => is_string($code) && $code !== ''));
    }

    private function consumeRecoveryCode(User $user, string $input): bool
    {
        $codes = $this->getRecoveryCodes($user);

        if ($codes === []) {
            return false;
        }

        $normalizedInput = $this->totp->normalizeRecoveryCode($input);

        foreach ($codes as $index => $code) {
            if (! hash_equals($this->totp->normalizeRecoveryCode($code), $normalizedInput)) {
                continue;
            }

            unset($codes[$index]);

            $user->forceFill([
                'totp_recovery_codes' => $this->encryptRecoveryCodes(array_values($codes)),
            ])->save();

            return true;
        }

        return false;
    }

    /**
     * @throws ValidationException
     */
    private function ensureNotLocked(User $user): void
    {
        if (! $user->totp_locked_until || ! now()->lt($user->totp_locked_until)) {
            return;
        }

        $seconds = now()->diffInSeconds($user->totp_locked_until);

        throw ValidationException::withMessages([
            'code' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds]),
        ]);
    }

    private function registerFailedAttempt(User $user): void
    {
        $attempts = ((int) $user->totp_attempts) + 1;

        if ($attempts >= 5) {
            $user->forceFill([
                'totp_attempts' => 0,
                'totp_locked_until' => now()->addMinutes(5),
            ])->save();

            return;
        }

        $user->forceFill([
            'totp_attempts' => $attempts,
        ])->save();
    }

    private function resetFailedAttempts(User $user): void
    {
        $user->forceFill([
            'totp_attempts' => 0,
            'totp_locked_until' => null,
        ])->save();
    }

    private function writeAudit(Request $request, User $user, string $action, array $metadata = []): void
    {
        SecurityAuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 65535),
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    private function encryptRecoveryCodes(array $codes): ?string
    {
        if ($codes === []) {
            return null;
        }

        try {
            return Crypt::encryptString(json_encode(array_values($codes), JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }
    }

    private function lockedUntilIso(User $user): ?string
    {
        if (! $user->totp_locked_until instanceof Carbon) {
            return null;
        }

        return $user->totp_locked_until->toIso8601String();
    }
}

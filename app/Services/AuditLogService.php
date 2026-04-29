<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditLogService
{
    /**
     * @param  array{action:string,module:string,description?:string|null,payload?:array<string,mixed>|null,user_id?:int|null,user_name?:string|null,route?:string|null,method?:string|null,url?:string|null,ip_address?:string|null,user_agent?:string|null}  $data
     */
    public function logAfterResponse(array $data): void
    {
        if (app()->runningInConsole()) {
            $this->log($data);

            return;
        }

        app()->terminating(fn () => $this->log($data));
    }

    /**
     * @param  array{action:string,module:string,description?:string|null,payload?:array<string,mixed>|null,user_id?:int|null,user_name?:string|null,route?:string|null,method?:string|null,url?:string|null,ip_address?:string|null,user_agent?:string|null}  $data
     */
    public function log(array $data): void
    {
        $action = trim((string) ($data['action'] ?? ''));
        $module = trim((string) ($data['module'] ?? ''));

        if ($action === '' || $module === '') {
            return;
        }

        try {
            $request = app()->bound('request') ? app('request') : null;
            $request = $request instanceof Request ? $request : null;

            $user = $this->resolveUser($request);
            $payload = $this->sanitizePayload($data['payload'] ?? null);
            $payloadJson = null;

            if ($payload !== null) {
                $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $payloadJson = is_string($encoded) ? $encoded : null;
            }

            DB::table('audit_logs')->insert([
                'user_id' => array_key_exists('user_id', $data) ? $this->nullableInt($data['user_id']) : $user?->id,
                'user_name' => array_key_exists('user_name', $data) ? $this->nullableString($data['user_name']) : $this->resolveUserName($user),
                'action' => $action,
                'module' => $module,
                'description' => $this->nullableString($data['description'] ?? null),
                'route' => $this->resolveRoute($data, $request),
                'method' => $this->resolveMethod($data, $request),
                'url' => $this->resolveUrl($data, $request),
                'ip_address' => $this->resolveIpAddress($data, $request),
                'user_agent' => $this->resolveUserAgent($data, $request),
                'payload' => $payloadJson,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Never block user actions if audit logging fails.
        }
    }

    private function resolveUser(?Request $request): ?User
    {
        $candidate = $request?->user();

        if ($candidate instanceof User) {
            return $candidate;
        }

        $authUser = Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }

    private function resolveUserName(?User $user): ?string
    {
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
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizePayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $sanitized = $this->sanitizeValue($payload);

        return is_array($sanitized) ? $sanitized : null;
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $output = [];
            foreach ($value as $childKey => $childValue) {
                $childKeyString = is_string($childKey) ? $childKey : (string) $childKey;
                if ($this->isSensitiveKey($childKeyString)) {
                    $output[$childKey] = '[REDACTED]';
                    continue;
                }

                $output[$childKey] = $this->sanitizeValue($childValue, $childKeyString);
            }

            return $output;
        }

        if (is_object($value)) {
            return $this->sanitizeValue((array) $value, $key);
        }

        if (is_string($value)) {
            if ($key !== null && $this->isSensitiveKey($key)) {
                return '[REDACTED]';
            }

            return mb_substr($value, 0, 4000);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower(trim($key));

        foreach (['password', 'pass', 'token', 'secret', 'authorization', 'cookie', 'totp', 'otp'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolveRoute(array $data, ?Request $request): ?string
    {
        if (array_key_exists('route', $data)) {
            return $this->nullableString($data['route']);
        }

        return $this->nullableString($request?->route()?->getName());
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolveMethod(array $data, ?Request $request): ?string
    {
        if (array_key_exists('method', $data)) {
            return $this->nullableString($data['method']);
        }

        return $this->nullableString($request?->method());
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolveUrl(array $data, ?Request $request): ?string
    {
        if (array_key_exists('url', $data)) {
            return $this->nullableString($data['url']);
        }

        return $this->nullableString($request?->fullUrl());
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolveIpAddress(array $data, ?Request $request): ?string
    {
        if (array_key_exists('ip_address', $data)) {
            return $this->nullableString($data['ip_address']);
        }

        return $this->nullableString($request?->ip());
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolveUserAgent(array $data, ?Request $request): ?string
    {
        if (array_key_exists('user_agent', $data)) {
            return $this->nullableString($data['user_agent']);
        }

        return $this->nullableString($request?->userAgent());
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

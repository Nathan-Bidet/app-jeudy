<?php

namespace App\Services;

use Illuminate\Support\Str;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 32): string
    {
        $secret = '';

        for ($index = 0; $index < $length; $index++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public function provisioningUri(string $accountName, string $issuer, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($accountName);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $normalizedCode = preg_replace('/\s+/', '', $code);

        if (! is_string($normalizedCode) || ! preg_match('/^\d{6}$/', $normalizedCode)) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->generateCode($secret, $timeSlice + $offset), $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($index = 0; $index < $count; $index++) {
            $codes[] = strtoupper(Str::random(5)).'-'.strtoupper(Str::random(5));
        }

        return $codes;
    }

    public function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', $code));
    }

    private function generateCode(string $secret, int $timeSlice): string
    {
        $decodedSecret = $this->base32Decode($secret);
        $time = pack('N*', 0).pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $decodedSecret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;

        $binaryCode =
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binaryCode % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $normalizedSecret = strtoupper($secret);
        $normalizedSecret = preg_replace('/[^A-Z2-7]/', '', $normalizedSecret);

        if (! is_string($normalizedSecret)) {
            return '';
        }

        $binary = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($normalizedSecret) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);

            if ($value === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $binary;
    }
}

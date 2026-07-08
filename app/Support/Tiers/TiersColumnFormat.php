<?php

namespace App\Support\Tiers;

use DateTimeImmutable;
use Illuminate\Support\Str;

class TiersColumnFormat
{
    public static function normalize(mixed $value, ?string $format): string
    {
        $normalized = trim((string) $value);
        $format = (string) $format;

        if ($normalized === '') {
            return '';
        }

        return match ($format) {
            'postal_code_00000' => self::normalizePostalCode($normalized),
            'phone_fr' => self::normalizeFrenchPhone($normalized),
            'email' => Str::lower($normalized),
            'uppercase' => Str::upper($normalized),
            'date_fr' => self::normalizeFrenchDate($normalized),
            default => $normalized,
        };
    }

    public static function formatForDisplay(mixed $value, ?string $format): string
    {
        return self::normalize($value, $format);
    }

    public static function isValid(mixed $value, ?string $format): bool
    {
        $value = self::normalize($value, $format);
        if ($value === '') {
            return true;
        }

        return match ((string) $format) {
            'postal_code_00000' => preg_match('/^\d{5}$/', $value) === 1,
            'phone_fr' => preg_match('/^(?:\+33 ?[1-9](?: ?\d{2}){4}|0[1-9](?: ?\d{2}){4})$/', $value) === 1,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'date_fr' => preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) === 1,
            default => true,
        };
    }

    public static function validationMessage(?string $format): string
    {
        return match ((string) $format) {
            'postal_code_00000' => 'Code postal invalide, format attendu 00000.',
            'phone_fr' => 'Téléphone invalide.',
            'email' => 'Adresse email invalide.',
            'date_fr' => 'Date invalide, format attendu jj/mm/aaaa.',
            default => 'Valeur invalide.',
        };
    }

    private static function normalizePostalCode(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits !== '' && strlen($digits) <= 5) {
            return str_pad($digits, 5, '0', STR_PAD_LEFT);
        }

        return $value;
    }

    private static function normalizeFrenchPhone(string $value): string
    {
        $compact = preg_replace('/[^\d+]+/', '', $value) ?? '';

        if (preg_match('/^0033([1-9]\d{8})$/', $compact, $matches)) {
            $compact = '+33'.$matches[1];
        }

        if (preg_match('/^\+33([1-9]\d{8})$/', $compact, $matches)) {
            return '+33 '.trim(chunk_split($matches[1], 2, ' '));
        }

        $digits = preg_replace('/\D+/', '', $compact) ?? '';
        if (preg_match('/^0[1-9]\d{8}$/', $digits)) {
            return trim(chunk_split($digits, 2, ' '));
        }

        return $value;
    }

    private static function normalizeFrenchDate(string $value): string
    {
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('d/m/Y');
            }
        }

        return $value;
    }
}

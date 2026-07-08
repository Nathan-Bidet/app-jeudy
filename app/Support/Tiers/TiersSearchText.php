<?php

namespace App\Support\Tiers;

class TiersSearchText
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function build(array $data, mixed $primaryIdentifier = null, mixed $referenceValue = null): string
    {
        $parts = [
            (string) $primaryIdentifier,
            (string) $referenceValue,
        ];

        foreach ($data as $value) {
            if (is_array($value)) {
                $parts[] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                continue;
            }

            $parts[] = (string) $value;
        }

        return self::normalize(implode(' ', array_filter($parts, static fn (string $value): bool => trim($value) !== '')));
    }

    public static function normalize(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';

        return strtr($normalized, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c',
            'ď' => 'd', 'đ' => 'd',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
            'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
            'ĥ' => 'h',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
            'ĵ' => 'j',
            'ķ' => 'k',
            'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
            'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
            'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
            'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's',
            'ß' => 'ss',
            'ť' => 't', 'ţ' => 't', 'ŧ' => 't',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
            'ẃ' => 'w',
            'ẍ' => 'x',
            'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
            'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
            'œ' => 'oe',
            'æ' => 'ae',
        ]);
    }
}

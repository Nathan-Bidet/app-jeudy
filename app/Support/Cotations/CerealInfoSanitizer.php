<?php

namespace App\Support\Cotations;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allow-list HTML sanitizer for the rich-text "Information" block on the
 * Cotations page. Keeps only the tags/styles produced by the front-end
 * toolbar (bold, italic, underline, strike, alignment, font size, colors)
 * and strips everything else to prevent script/markup injection.
 */
class CerealInfoSanitizer
{
    private const ALLOWED_TAGS = ['p', 'div', 'span', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike'];

    private const ALLOWED_STYLE_PROPERTIES = [
        'font-weight',
        'font-style',
        'text-decoration',
        'text-decoration-line',
        'text-align',
        'font-size',
        'color',
        'background-color',
    ];

    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="cereal-info-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $document->getElementById('cereal-info-root');
        if (! $root) {
            return '';
        }

        self::sanitizeChildren($document, $root);

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private static function sanitizeChildren(DOMDocument $document, DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                $node->removeChild($child);
                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                self::sanitizeChildren($document, $child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            self::sanitizeAttributes($child);
            self::sanitizeChildren($document, $child);
        }
    }

    private static function sanitizeAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if ($attribute->name !== 'style') {
                $element->removeAttribute($attribute->name);
                continue;
            }

            $safeStyle = self::sanitizeStyle($attribute->value);
            if ($safeStyle === '') {
                $element->removeAttribute('style');
            } else {
                $element->setAttribute('style', $safeStyle);
            }
        }
    }

    private static function sanitizeStyle(string $style): string
    {
        $safeDeclarations = [];

        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if (! in_array($property, self::ALLOWED_STYLE_PROPERTIES, true)) {
                continue;
            }

            if (! self::isSafeStyleValue($property, $value)) {
                continue;
            }

            $safeDeclarations[] = "{$property}: {$value}";
        }

        return implode('; ', $safeDeclarations);
    }

    private static function isSafeStyleValue(string $property, string $value): bool
    {
        if ($value === '' || stripos($value, 'url(') !== false || stripos($value, 'expression(') !== false) {
            return false;
        }

        return (bool) match ($property) {
            'font-weight' => preg_match('/^(normal|bold|[1-9]00)$/i', $value),
            'font-style' => preg_match('/^(normal|italic)$/i', $value),
            'text-decoration', 'text-decoration-line' => preg_match('/^(none|underline|line-through)(\s+(underline|line-through))*$/i', $value),
            'text-align' => preg_match('/^(left|center|right|justify)$/i', $value),
            'font-size' => preg_match('/^\d{1,3}(\.\d+)?(px|pt|em|rem)$/i', $value),
            'color', 'background-color' => preg_match('/^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\))$/i', $value),
            default => false,
        };
    }
}

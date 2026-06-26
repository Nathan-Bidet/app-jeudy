<?php

namespace App\Support\RichText;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allow-list HTML sanitizer for lightweight rich-text fields (gras, italique,
 * souligné, barré, retours à la ligne uniquement). Aucun attribut de style
 * n'est autorisé : tout ce qui n'est pas un tag de mise en forme simple est
 * désimbriqué (le contenu texte est conservé, le tag supprimé).
 */
class SimpleHtmlSanitizer
{
    private const ALLOWED_TAGS = ['p', 'div', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike'];

    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="rich-text-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $document->getElementById('rich-text-root');
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

    /**
     * Version texte brut (pour SMS / aperçu), retours à la ligne conservés.
     */
    public static function toPlainText(?string $html): string
    {
        $sanitized = self::sanitize($html);
        if ($sanitized === '') {
            return '';
        }

        $withBreaks = preg_replace('/<\s*(br|\/p|\/div)\s*\/?>/i', "\n", $sanitized);
        $text = trim(html_entity_decode(strip_tags((string) $withBreaks), ENT_QUOTES, 'UTF-8'));

        return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
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

            self::stripAttributes($child);
            self::sanitizeChildren($document, $child);
        }
    }

    private static function stripAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $element->removeAttribute($attribute->name);
        }
    }
}

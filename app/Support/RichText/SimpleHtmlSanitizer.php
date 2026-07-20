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
        self::flattenBlocks($document, $root);

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

    /**
     * Rendu structurel pour affichage : reconstruit de vrais paragraphes
     * (<p>) séparés par les lignes vides, des <br> pour les simples retours
     * à la ligne à l'intérieur d'un paragraphe, et de vraies listes
     * (<ul>/<ol>) pour les lignes commençant par "-", "•" ou une puce
     * numérotée. Ainsi le navigateur applique un espacement normal et
     * cohérent (marge de paragraphe, interligne de liste) au lieu de tout
     * mettre à plat avec des <br>.
     */
    public static function render(?string $html): string
    {
        $flat = self::sanitize($html);
        if ($flat === '') {
            return '';
        }

        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="rich-text-root">'.$flat.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $document->getElementById('rich-text-root');
        if (! $root) {
            return '';
        }

        $lines = self::splitIntoLines($root);
        $blocks = self::groupLinesIntoBlocks($lines);

        $output = '';
        foreach ($blocks as $block) {
            $output .= self::renderBlock($document, $block);
        }

        return $output;
    }

    /**
     * @return array<int, array<int, DOMNode>>
     */
    private static function splitIntoLines(DOMElement $root): array
    {
        $lines = [];
        $current = [];

        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'br') {
                $lines[] = $current;
                $current = [];
                continue;
            }

            $current[] = $node;
        }
        $lines[] = $current;

        return $lines;
    }

    /**
     * Regroupe les lignes en blocs (paragraphes) séparés par les lignes
     * vides ; une ligne vide isolée sépare deux blocs sans être rendue.
     *
     * @param  array<int, array<int, DOMNode>>  $lines
     * @return array<int, array<int, array<int, DOMNode>>>
     */
    private static function groupLinesIntoBlocks(array $lines): array
    {
        $blocks = [];
        $current = [];

        foreach ($lines as $line) {
            if (self::lineText($line) === '') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }

                continue;
            }

            $current[] = $line;
        }

        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Une ligne isolée par un simple retour à la ligne (donc sans ligne
     * vide autour) peut malgré tout être une liste : on segmente le bloc
     * en groupes de lignes consécutives de même nature (liste à puces,
     * liste numérotée, texte normal) plutôt que d'exiger que tout le bloc
     * soit uniforme.
     *
     * @param  array<int, array<int, DOMNode>>  $block
     */
    private static function renderBlock(DOMDocument $document, array $block): string
    {
        $output = '';
        foreach (self::segmentBlock($block) as $segment) {
            $output .= $segment['type'] === null
                ? self::renderParagraph($document, $segment['lines'])
                : self::renderList($document, $segment['type'], $segment['lines']);
        }

        return $output;
    }

    /**
     * @param  array<int, array<int, DOMNode>>  $block
     * @return array<int, array{type: ?string, lines: array<int, array<int, DOMNode>>}>
     */
    private static function segmentBlock(array $block): array
    {
        $segments = [];
        $currentType = false; // false = pas encore initialisé (distinct de null = "texte normal")
        $currentLines = [];

        foreach ($block as $line) {
            $marker = self::matchListMarker(self::lineText($line));

            if ($marker !== $currentType) {
                if ($currentLines !== []) {
                    $segments[] = ['type' => $currentType === false ? null : $currentType, 'lines' => $currentLines];
                }
                $currentType = $marker;
                $currentLines = [];
            }

            $currentLines[] = $line;
        }

        if ($currentLines !== []) {
            $segments[] = ['type' => $currentType === false ? null : $currentType, 'lines' => $currentLines];
        }

        // Une "liste" d'une seule ligne n'en est pas vraiment une (ex. une
        // phrase contenant un tiret) : on la fond dans le texte normal
        // voisin pour éviter les faux positifs.
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment['type'] !== null && count($segment['lines']) < 2) {
                $segment['type'] = null;
            }

            $previous = $normalized === [] ? null : $normalized[count($normalized) - 1];
            if ($previous !== null && $previous['type'] === null && $segment['type'] === null) {
                $merged = array_pop($normalized);
                $segment['lines'] = array_merge($merged['lines'], $segment['lines']);
            }

            $normalized[] = $segment;
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<int, DOMNode>>  $lines
     */
    private static function renderParagraph(DOMDocument $document, array $lines): string
    {
        $paragraph = $document->createElement('p');
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $paragraph->appendChild($document->createElement('br'));
            }
            foreach ($line as $node) {
                $paragraph->appendChild($node);
            }
        }

        return $document->saveHTML($paragraph);
    }

    /**
     * @param  array<int, array<int, DOMNode>>  $lines
     */
    private static function renderList(DOMDocument $document, string $type, array $lines): string
    {
        $list = $document->createElement($type);
        foreach ($lines as $line) {
            $item = $document->createElement('li');
            self::stripLeadingMarker($line);
            foreach ($line as $node) {
                $item->appendChild($node);
            }
            $list->appendChild($item);
        }

        return $document->saveHTML($list);
    }

    private static function matchListMarker(string $text): ?string
    {
        if (preg_match('/^[-•‣▪]\s+\S/u', $text) === 1) {
            return 'ul';
        }

        if (preg_match('/^\d+[.)]\s+\S/u', $text) === 1) {
            return 'ol';
        }

        return null;
    }

    /**
     * @param  array<int, DOMNode>  $line
     */
    private static function stripLeadingMarker(array $line): void
    {
        if ($line === [] || ! $line[0] instanceof DOMText) {
            return;
        }

        $trimmed = ltrim($line[0]->textContent);

        if (preg_match('/^(?:[-•‣▪]|\d+[.)])\s+/u', $trimmed, $m) === 1) {
            $line[0]->textContent = substr($trimmed, strlen($m[0]));
        }
    }

    /**
     * @param  array<int, DOMNode>  $line
     */
    private static function lineText(array $line): string
    {
        $text = '';
        foreach ($line as $node) {
            $text .= $node->textContent;
        }

        return trim($text);
    }

    /**
     * Les navigateurs encapsulent chaque ligne saisie dans un <div>/<p> lors
     * de l'appui sur Entrée dans le champ contenteditable. Conservés tels
     * quels, ces blocs héritent de marges (CSS "prose" ou navigateur) et
     * transforment chaque ligne en paragraphe très espacé. On aplatit donc
     * la structure : un bloc = une ligne = un <br> séparateur, sans marge.
     */
    private static function flattenBlocks(DOMDocument $document, DOMElement $root): void
    {
        $children = iterator_to_array($root->childNodes);

        foreach ($children as $index => $child) {
            if (! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), ['p', 'div'], true)) {
                continue;
            }

            if ($index > 0) {
                $root->insertBefore($document->createElement('br'), $child);
            }

            if (! self::isEmptyLineBlock($child)) {
                while ($child->firstChild) {
                    $root->insertBefore($child->firstChild, $child);
                }
            }

            $root->removeChild($child);
        }
    }

    private static function isEmptyLineBlock(DOMElement $block): bool
    {
        foreach (iterator_to_array($block->childNodes) as $child) {
            if ($child instanceof DOMText) {
                if (trim($child->textContent) !== '') {
                    return false;
                }

                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->tagName) === 'br') {
                continue;
            }

            return false;
        }

        return true;
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

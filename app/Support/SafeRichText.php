<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class SafeRichText
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'a'];

    private const REMOVED_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select'];

    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousSetting = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        $root = $document->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }

        foreach (iterator_to_array($root->childNodes) as $child) {
            self::sanitizeNode($child);
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }

    private static function sanitizeNode(DOMNode $node): void
    {
        if ($node instanceof DOMText) {
            return;
        }

        if (! $node instanceof DOMElement) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::REMOVED_TAGS, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            self::sanitizeNode($child);
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            $parent = $node->parentNode;
            if ($parent === null) {
                return;
            }

            while ($node->firstChild !== null) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);

            return;
        }

        $href = $tag === 'a' ? self::safeHref($node->getAttribute('href')) : null;
        foreach (iterator_to_array($node->attributes) as $attribute) {
            $node->removeAttribute($attribute->name);
        }

        if ($tag === 'a' && $href !== null) {
            $node->setAttribute('href', $href);
            $node->setAttribute('rel', 'nofollow noopener noreferrer');
        }
    }

    private static function safeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '//')) {
            return null;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if ($scheme === null) {
            return str_starts_with($href, '/') ? $href : null;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true) ? $href : null;
    }
}

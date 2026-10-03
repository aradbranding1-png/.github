<?php
declare(strict_types=1);

namespace App\Core;

/** Allow-list HTML sanitizer for lesson/course rich text (XSS protection). */
final class HtmlSanitizer
{
    private const TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'a', 'code', 'pre', 'hr', 'span', 'div', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'sub', 'sup', 'mark'];
    private const ATTRS = ['href', 'title', 'src', 'alt', 'colspan', 'rowspan', 'target', 'dir'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        if (!class_exists(\DOMDocument::class)) return nl2br(e(strip_tags($html)));
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $root = $doc->getElementById('__root') ?? $doc->documentElement;
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
        return $out;
    }

    private static function walk(\DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $c = $node->childNodes->item($i);
            if ($c instanceof \DOMElement) {
                $tag = strtolower($c->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'svg', 'math'], true)) {
                    $node->removeChild($c);
                    continue;
                }
                if (!in_array($tag, self::TAGS, true)) {
                    self::walk($c);
                    while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                    $node->removeChild($c);
                    continue;
                }
                for ($a = $c->attributes->length - 1; $a >= 0; $a--) {
                    $attr = $c->attributes->item($a);
                    $name = strtolower($attr->nodeName);
                    $val = trim($attr->nodeValue ?? '');
                    if (!in_array($name, self::ATTRS, true)) { $c->removeAttribute($attr->nodeName); continue; }
                    if (in_array($name, ['href', 'src'], true) && !preg_match('~^(https?://|/|#|mailto:)~i', $val)) $c->removeAttribute($attr->nodeName);
                }
                if ($tag === 'a') { $c->setAttribute('rel', 'noopener noreferrer'); if ($c->getAttribute('target') !== '') $c->setAttribute('target', '_blank'); }
                self::walk($c);
            } elseif ($c instanceof \DOMComment) {
                $node->removeChild($c);
            }
        }
    }
}

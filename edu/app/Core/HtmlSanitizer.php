<?php
declare(strict_types=1);

namespace App\Core;

/** Allow-list HTML sanitizer for lesson/course rich text (XSS protection). */
final class HtmlSanitizer
{
    private const TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'a', 'code', 'pre', 'hr', 'span', 'div', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'sub', 'sup', 'mark'];
    private const ATTRS = ['href', 'title', 'src', 'alt', 'colspan', 'rowspan', 'target', 'dir', 'style'];
    /** tags renamed instead of dropped (pasted from Word / Google Docs / the editor) */
    private const RENAME = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4', 'font' => 'span', 'center' => 'p'];
    /** inline styles that are kept — everything else (sizes, fonts, positions, url(), …) is removed */
    private const STYLE_PROPS = ['color', 'background-color', 'background', 'text-align', 'font-weight', 'font-style', 'text-decoration', 'text-decoration-line'];

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
                if (isset(self::RENAME[$tag])) {
                    $c = self::rename($c, self::RENAME[$tag], $tag);
                    $tag = strtolower($c->tagName);
                }
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
                    if ($name === 'style') { $st = self::style($val); if ($st === '') $c->removeAttribute($attr->nodeName); else $c->setAttribute('style', $st); }
                }
                if ($tag === 'a') { $c->setAttribute('rel', 'noopener noreferrer'); if ($c->getAttribute('target') !== '') $c->setAttribute('target', '_blank'); }
                self::walk($c);
            } elseif ($c instanceof \DOMComment) {
                $node->removeChild($c);
            }
        }
    }

    /** Replace an element by another tag, keeping its children (and turning <font color> into a colour style) */
    private static function rename(\DOMElement $el, string $to, string $from): \DOMElement
    {
        $new = $el->ownerDocument->createElement($to);
        $style = $el->getAttribute('style');
        if ($from === 'font' && $el->getAttribute('color') !== '') $style = 'color:' . $el->getAttribute('color') . ';' . $style;
        if ($from === 'center') $style = 'text-align:center;' . $style;
        if ($style !== '') $new->setAttribute('style', $style);
        if ($el->getAttribute('dir') !== '') $new->setAttribute('dir', $el->getAttribute('dir'));
        while ($el->firstChild) $new->appendChild($el->firstChild);
        $el->parentNode->replaceChild($new, $el);
        return $new;
    }

    /** Keep only safe, meaningful declarations: colours, highlight, bold/italic/underline, alignment */
    public static function style(string $css): string
    {
        $out = [];
        foreach (explode(';', $css) as $decl) {
            if (!str_contains($decl, ':')) continue;
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            $val = strtolower(trim(str_ireplace('!important', '', $val)));
            if (!in_array($prop, self::STYLE_PROPS, true) || $val === '') continue;
            if (preg_match('~url|expression|javascript|var\(|attr\(|[<>\\\\{}@]~i', $val)) continue;
            switch ($prop) {
                case 'color':
                    if (self::isColor($val) && !self::isNeutral($val, 'text')) $out['color'] = $val;
                    break;
                case 'background':
                case 'background-color':
                    if (self::isColor($val) && !self::isNeutral($val, 'bg')) $out['background-color'] = $val;
                    break;
                case 'text-align':
                    if (in_array($val, ['right', 'left', 'center', 'justify', 'start', 'end'], true)) $out['text-align'] = $val;
                    break;
                case 'font-weight':
                    if (in_array($val, ['bold', 'bolder', '600', '700', '800', '900'], true)) $out['font-weight'] = '700';
                    break;
                case 'font-style':
                    if ($val === 'italic') $out['font-style'] = 'italic';
                    break;
                default: // text-decoration(-line)
                    if (preg_match('~\b(underline|line-through)\b~', $val, $m)) $out['text-decoration'] = $m[1];
            }
        }
        $s = '';
        foreach ($out as $k => $v) $s .= $k . ':' . $v . ';';
        return $s;
    }

    private static function isColor(string $v): bool
    {
        return (bool)preg_match('~^(#[0-9a-f]{3,8}|rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*)?\)|[a-z]{3,20})$~', $v);
    }

    /**
     * Black / dark-grey text and white backgrounds are dropped: they are the page defaults when pasted
     * from Word or Google Docs, and would make text invisible in dark mode.
     */
    private static function isNeutral(string $v, string $kind): bool
    {
        if (in_array($v, ['inherit', 'initial', 'unset', 'currentcolor', 'transparent', 'windowtext', 'auto', 'none'], true)) return true;
        $named = ['black' => [0, 0, 0], 'white' => [255, 255, 255]];
        $rgb = null; $alpha = 1.0;
        if (isset($named[$v])) $rgb = $named[$v];
        elseif (preg_match('~^#([0-9a-f]{3,8})$~', $v, $m)) {
            $h = $m[1];
            if (strlen($h) === 3 || strlen($h) === 4) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            $rgb = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        } elseif (preg_match('~^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$~', $v, $m)) {
            $rgb = [(float)$m[1], (float)$m[2], (float)$m[3]];
            if (isset($m[4]) && $m[4] !== '') $alpha = (float)$m[4];
        }
        if ($rgb === null) return false; // other named colours (red, navy, …) are kept
        if ($alpha < 0.05) return true;
        $spread = max($rgb) - min($rgb);
        $light = array_sum($rgb) / 3;
        return $kind === 'text' ? ($spread < 30 && $light < 90) : ($spread < 20 && $light > 235);
    }
}

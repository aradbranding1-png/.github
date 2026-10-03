<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Light Markdown → HTML for text pasted from AI tools (ChatGPT, Claude, …).
 * Handles: # headings, **bold**, __bold__, `code`, [text](https://…), bullet lists (* - • +),
 * numbered lists (1. / ۱. / 1)), > quotes and --- rules. Works on plain text and on text
 * already wrapped in <p>/<br> by the editor, so old content is fixed at display time too.
 * Output always goes through HtmlSanitizer afterwards (see clean_html()).
 */
final class Markdown
{
    private const BLOCK_START = '~^<(ul|ol|table|thead|tbody|tr|h[1-6]|blockquote|pre|hr|img|div)\b~i';

    public static function looksLike(string $s): bool
    {
        return (bool)preg_match('~\*\*[^*\n]+?\*\*|__[^_\n]+?__|(?:^|\n|>)[ \t]*(?:#{1,6}[ \t]|[*\-•+][ \t]|[0-9۰-۹]{1,3}[.)][ \t]|(?:>|&gt;)[ \t]|(?:-{3,}|\*{3,})[ \t]*(?:$|\n|<))~u', $s);
    }

    public static function render(string $s): string
    {
        if ($s === '' || !self::looksLike($s)) return $s;

        // flatten paragraph-level HTML produced by the editor into plain lines
        $t = preg_replace('~<br\s*/?>~i', "\n", $s);
        $t = preg_replace('~</(p|div)>~i', "\n\n", $t);
        $t = preg_replace('~<(p|div)(\s[^>]*)?>~i', "\n", $t);
        $lines = preg_split("/\r\n|\r|\n/", (string)$t) ?: [];

        $out = [];
        $para = [];
        $list = null;   // 'ul' | 'ol'
        $quote = [];

        $flushPara = function () use (&$para, &$out) {
            if ($para) { $out[] = '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
        };
        $flushList = function () use (&$list, &$out) {
            if ($list) { $out[] = '</' . $list . '>'; $list = null; }
        };
        $flushQuote = function () use (&$quote, &$out) {
            if ($quote) { $out[] = '<blockquote>' . implode('<br>', $quote) . '</blockquote>'; $quote = []; }
        };
        $flushAll = function () use ($flushPara, $flushList, $flushQuote) { $flushPara(); $flushList(); $flushQuote(); };

        foreach ($lines as $raw) {
            $line = trim(str_replace("\u{00A0}", ' ', $raw));
            if ($line === '') { $flushAll(); continue; }

            if (preg_match('~^(#{1,6})\s+(.+?)\s*#*$~u', $line, $m)) {
                $flushAll();
                $lvl = strlen($m[1]);
                $tag = $lvl <= 2 ? 'h2' : ($lvl === 3 ? 'h3' : 'h4');
                $out[] = "<$tag>" . self::inline(self::stripStrong($m[2])) . "</$tag>";
                continue;
            }
            if (preg_match('~^(?:-{3,}|\*{3,}|_{3,})$~', $line)) { $flushAll(); $out[] = '<hr>'; continue; }
            if (preg_match('~^[*\-•+]\s+(.+)$~u', $line, $m)) {
                $flushPara(); $flushQuote();
                if ($list !== 'ul') { $flushList(); $out[] = '<ul>'; $list = 'ul'; }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if (preg_match('~^[0-9۰-۹]{1,3}[.)]\s+(.+)$~u', $line, $m)) {
                $flushPara(); $flushQuote();
                if ($list !== 'ol') { $flushList(); $out[] = '<ol>'; $list = 'ol'; }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if (preg_match('~^(?:>|&gt;)\s?(.*)$~u', $line, $m)) {
                $flushPara(); $flushList();
                $quote[] = self::inline($m[1]);
                continue;
            }
            if (preg_match(self::BLOCK_START, $line)) { $flushAll(); $out[] = $line; continue; }

            // plain text: a list item continues only via its own marker, so close lists first
            $flushList(); $flushQuote();
            $para[] = self::inline($line);
        }
        $flushAll();
        return implode("\n", $out);
    }

    private static function stripStrong(string $s): string
    {
        return preg_replace('~^\*\*(.+)\*\*$~u', '$1', $s) ?? $s;
    }

    private static function inline(string $s): string
    {
        $s = preg_replace('~\*\*(\S(?:.*?\S)?)\*\*~u', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('~(?<![\w\p{L}])__(\S(?:.*?\S)?)__(?![\w\p{L}])~u', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('~`([^`\n]+)`~u', '<code>$1</code>', $s) ?? $s;
        $s = preg_replace('~\[([^\]\n]+)\]\((https?://[^\s)]+)\)~u', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $s) ?? $s;
        // leftover unmatched ** from a broken copy
        return str_replace('**', '', $s);
    }
}

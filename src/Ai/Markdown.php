<?php
declare(strict_types=1);

namespace Glue\Ai;

/**
 * The assistant answers in Markdown; the chat shows HTML. This is the small
 * subset a CRM answer actually uses — headings, bold/italic, inline code, code
 * blocks, bullet and numbered lists, tables, links — rendered from text that
 * is HTML-escaped FIRST, so nothing the model (or a tool result it quotes)
 * writes can ever become markup. Links are kept only for http(s).
 */
final class Markdown
{
    public static function toHtml(string $md): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $md) ?: [];
        $out = [];
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];

            // fenced code
            if (preg_match('/^\s*```/', $line)) {
                $buf = [];
                $i++;
                while ($i < $n && !preg_match('/^\s*```/', $lines[$i])) {
                    $buf[] = $lines[$i];
                    $i++;
                }
                $i++; // closing fence
                $out[] = '<pre class="ai-code">' . htmlspecialchars(implode("\n", $buf), ENT_QUOTES, 'UTF-8') . '</pre>';
                continue;
            }

            // table: a header row, a separator row, then rows
            if (self::isTableRow($line) && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1])) {
                $head = self::cells($line);
                $i += 2;
                $rows = [];
                while ($i < $n && self::isTableRow($lines[$i])) {
                    $rows[] = self::cells($lines[$i]);
                    $i++;
                }
                $html = '<div class="ai-tablewrap"><table class="ai-table"><thead><tr>';
                foreach ($head as $c) {
                    $html .= '<th>' . self::inline($c) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $r) {
                    $html .= '<tr>';
                    foreach ($head as $k => $_) {
                        $html .= '<td>' . self::inline($r[$k] ?? '') . '</td>';
                    }
                    $html .= '</tr>';
                }
                $out[] = $html . '</tbody></table></div>';
                continue;
            }

            // lists (a run of bullet or numbered lines; indentation is flattened)
            if (preg_match('/^\s*([-*•]|\d+[.)])\s+/', $line)) {
                $ordered = (bool)preg_match('/^\s*\d+[.)]\s+/', $line);
                $items = [];
                while ($i < $n && preg_match('/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/', $lines[$i], $m)) {
                    $item = $m[1];
                    // a wrapped continuation line (indented, not a new item) belongs to the item
                    while ($i + 1 < $n && preg_match('/^\s{2,}(?![-*•]|\d+[.)])(\S.*)$/', $lines[$i + 1], $c)) {
                        $item .= ' ' . $c[1];
                        $i++;
                    }
                    $items[] = '<li>' . self::inline($item) . '</li>';
                    $i++;
                }
                $tag = $ordered ? 'ol' : 'ul';
                $out[] = "<$tag>" . implode('', $items) . "</$tag>";
                continue;
            }

            // headings
            if (preg_match('/^\s*(#{1,6})\s+(.*)$/', $line, $m)) {
                $lvl = min(4, strlen($m[1]) + 1); // # → h2 … keep the chat's hierarchy
                $out[] = "<h$lvl>" . self::inline(rtrim($m[2], ' #')) . "</h$lvl>";
                $i++;
                continue;
            }

            // blank line
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // paragraph: consecutive plain lines, single newlines kept as breaks
            $buf = [];
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^\s*(```|#{1,6}\s|[-*•]\s|\d+[.)]\s|\|)/', $lines[$i])) {
                $buf[] = self::inline($lines[$i]);
                $i++;
            }
            if ($buf) {
                $out[] = '<p>' . implode('<br>', $buf) . '</p>';
            } else {
                $i++; // a line that opens a block we did not consume — never loop forever
            }
        }
        return implode("\n", $out);
    }

    private static function isTableRow(string $line): bool
    {
        return (bool)preg_match('/^\s*\|.*\|\s*$/', $line);
    }

    /** @return string[] */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line) ?? $line;
        return array_map('trim', explode('|', $line));
    }

    /** Inline marks over an escaped string. */
    public static function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // code first, so its contents are never re-marked
        $s = preg_replace_callback('/`([^`]+)`/', fn($m) => '<code>' . $m[1] . '</code>', $s) ?? $s;
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $s) ?? $s;
        $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<i>$1</i>', $s) ?? $s;
        $s = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<i>$1</i>', $s) ?? $s;
        // [text](https://…) — the URL was escaped too, so &amp; is already safe in an attribute
        $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', static function ($m) {
            return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
        }, $s) ?? $s;
        return $s;
    }
}

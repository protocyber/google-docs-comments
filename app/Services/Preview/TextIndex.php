<?php

namespace App\Services\Preview;

use Generator;

/**
 * Text of a Docs body as the API counts it: UTF-16 units, every non-text
 * element one U+FFFC. Works on raw documents.get JSON arrays.
 */
final class TextIndex
{
    /**
     * @return Generator<array{int, int, string}> [start, end, text]
     */
    public static function runs(array $content): Generator
    {
        foreach ($content as $element) {
            if (isset($element['paragraph'])) {
                foreach ($element['paragraph']['elements'] ?? [] as $part) {
                    $s = (int) ($part['startIndex'] ?? 0);
                    $e = (int) ($part['endIndex'] ?? $s);
                    yield [$s, $e, isset($part['textRun'])
                        ? (string) ($part['textRun']['content'] ?? '')
                        : str_repeat("\u{FFFC}", $e - $s)];
                }
            } elseif (isset($element['table'])) {
                foreach ($element['table']['tableRows'] ?? [] as $row) {
                    foreach ($row['tableCells'] ?? [] as $cell) {
                        yield from self::runs($cell['content'] ?? []);
                    }
                }
            } elseif (isset($element['tableOfContents'])) {
                yield from self::runs($element['tableOfContents']['content'] ?? []);
            }
        }
    }

    public static function textBetween(array $content, int $start, int $end): string
    {
        $out = '';
        foreach (self::runs($content) as [$s, $e, $text]) {
            if ($e <= $start || $s >= $end) {
                continue;
            }
            $from = max($start, $s) - $s;
            $out .= self::utf16Substr($text, $from, min($end, $e) - $s - $from);
        }

        return $out;
    }

    public static function endIndex(array $content): int
    {
        $last = end($content);

        return $last ? (int) ($last['endIndex'] ?? 0) : 0;
    }

    public static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function utf16Substr(string $text, int $from, int $length): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return mb_convert_encoding(substr($utf16, $from * 2, $length * 2), 'UTF-8', 'UTF-16LE');
    }

    /**
     * UTF-16LE byte string, 2 bytes per Docs index covering [1, endIndex):
     * every text/atom run goes at its own index, structural gaps (table and
     * row starts, cell starts, TOC start/end markers, section breaks) are
     * left as U+0000. Index i (1-based) occupies bytes [(i-1)*2, i*2), so a
     * later splice() is a plain byte substr_replace.
     */
    public static function indexed(array $content): string
    {
        $length = max(0, self::endIndex($content) - 1);
        $buffer = str_repeat("\0", $length * 2);

        foreach (self::runs($content) as [$s, $e, $text]) {
            $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
            $buffer = substr_replace($buffer, $utf16, ($s - 1) * 2, ($e - $s) * 2);
        }

        return $buffer;
    }

    /**
     * Replaces indexes [$start, $end) in an `indexed()` string with $text
     * (UTF-8, converted to UTF-16LE), the same edit a `batchUpdate` of
     * EditRequest::build($start, $end, $text) performs on the document.
     */
    public static function splice(string $indexed, int $start, int $end, string $text): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return substr_replace($indexed, $utf16, ($start - 1) * 2, ($end - $start) * 2);
    }
}

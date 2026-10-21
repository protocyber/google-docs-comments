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
}

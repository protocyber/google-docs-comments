<?php

namespace App\Services\Preview;

use InvalidArgumentException;

/**
 * One text replacement in a Docs document, validated and turned into the
 * plain-text request pair the Docs API `batchUpdate` accepts: a delete of
 * the old range followed by an insert of the new text at the same start
 * index. Only `deleteContentRange` and `insertText` are produced: no request
 * here may touch text or paragraph style.
 */
final class EditRequest
{
    /**
     * @return list<array>
     *
     * @throws InvalidArgumentException when the range is invalid or the text
     *                                  would delete a non-text object (U+FFFC)
     */
    public static function build(int $start, int $end, string $text): array
    {
        if ($start < 1) {
            throw new InvalidArgumentException('start must be >= 1.');
        }
        if ($end < $start) {
            throw new InvalidArgumentException('end must be >= start.');
        }
        if ($start === $end && $text === '') {
            throw new InvalidArgumentException('An edit must delete or insert something.');
        }
        if (str_contains($text, "\u{FFFC}")) {
            throw new InvalidArgumentException('Cannot insert an object placeholder (U+FFFC).');
        }

        $requests = [];
        if ($end > $start) {
            $requests[] = ['deleteContentRange' => ['range' => ['startIndex' => $start, 'endIndex' => $end]]];
        }
        if ($text !== '') {
            $requests[] = ['insertText' => ['location' => ['index' => $start], 'text' => $text]];
        }

        return $requests;
    }
}

<?php

namespace App\Support;

/**
 * Google Docs document URL <-> document ID.
 */
final class GoogleDocsUrl
{
    /** Document IDs are long base64url-ish strings (44 chars today). */
    private const ID = '[A-Za-z0-9_-]{25,}';

    /**
     * Document ID from a Google Docs URL (any account/workspace prefix, any
     * trailing path, query or fragment) or from a bare ID. Null when the input
     * is not a Google Docs document (Sheets, Slides, Drive files included).
     */
    public static function extractId(?string $input): ?string
    {
        $input = trim((string) $input);

        if (preg_match('/^'.self::ID.'$/', $input)) {
            return $input;
        }

        $pattern = '#^(?:https?://)?docs\.google\.com/(?:a/[^/]+/)?document/(?:u/\d+/)?d/('.self::ID.')(?:[/?\#]|$)#i';

        return preg_match($pattern, $input, $m) ? $m[1] : null;
    }

    public static function editUrl(string $id): string
    {
        return "https://docs.google.com/document/d/{$id}/edit";
    }
}

<?php

namespace App\Services\Preview;

use Illuminate\Support\Facades\Cache;

/**
 * The last text this user saw for a document revision, kept just long enough
 * to recover from a stale `requiredRevisionId` (Task 2+4): when `applyEdit`
 * reports the revision moved, the saved base lets the caller tell an
 * unrelated remote change apart from a revision bump it caused itself
 * (comment-only edits also bump `revisionId`).
 */
final class EditBase
{
    private const TTL_SECONDS = 2 * 60 * 60;

    public static function put(int $userId, string $docId, string $revisionId, string $indexed): void
    {
        Cache::put(self::key($userId, $docId, $revisionId), $indexed, self::TTL_SECONDS);
    }

    public static function get(int $userId, string $docId, string $revisionId): ?string
    {
        return Cache::get(self::key($userId, $docId, $revisionId));
    }

    private static function key(int $userId, string $docId, string $revisionId): string
    {
        return "gd-edit:{$userId}:{$docId}:{$revisionId}";
    }
}

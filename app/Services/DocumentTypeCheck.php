<?php

namespace App\Services;

use App\Models\User;
use App\Support\GoogleDocsFile;

/**
 * Save-time check that a project's file is a native Google Doc.
 *
 * Bound in the container so tests can replace the Drive lookup.
 */
class DocumentTypeCheck
{
    /**
     * Problem with the file for the end user, or null when it is fine.
     * Null as well when the check cannot run (the user has no Google token):
     * the preview and the actions repeat the check before using the file.
     */
    public function problem(?User $user, string $fileId): ?string
    {
        if (! $user?->socialiteUser?->google_access_token) {
            return null;
        }

        try {
            return GoogleDocsFile::problem((new GoogleDocsCommenter($user))->mimeType($fileId));
        } catch (UnsupportedDocument $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            report($e);

            return null; // Google unreachable: do not block saving
        }
    }
}

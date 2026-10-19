<?php

namespace App\Services;

use App\Support\GoogleDocsFile;
use RuntimeException;

/**
 * The project's file is not something we can comment on. The message is meant
 * for the end user.
 */
class UnsupportedDocument extends RuntimeException
{
    public static function forMime(string $mimeType): self
    {
        return new self((string) GoogleDocsFile::problem($mimeType));
    }

    public static function notFound(): self
    {
        return new self('Dokumen tidak ditemukan, atau akun Google Anda tidak punya akses ke dokumen ini.');
    }
}

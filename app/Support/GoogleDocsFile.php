<?php

namespace App\Support;

use App\Services\UnsupportedDocument;

/**
 * What kind of Drive file a project may point at, and what to fetch from it.
 *
 * Only native Google Docs are writable through the Docs API. Office files
 * opened in Docs (.docx etc.) share the docs.google.com/document URL and can
 * even be read, but every write (comments included) fails with "The document
 * must not be an Office file", so they are refused up front.
 */
final class GoogleDocsFile
{
    public const MIME = 'application/vnd.google-apps.document';

    private const OFFICE_TEXT = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word (.docx)',
        'application/msword' => 'Word (.doc)',
        'application/vnd.oasis.opendocument.text' => 'OpenDocument (.odt)',
        'application/rtf' => 'RTF',
        'text/plain' => 'teks (.txt)',
    ];

    private const OTHER_GOOGLE = [
        'application/vnd.google-apps.spreadsheet' => 'Google Sheets',
        'application/vnd.google-apps.presentation' => 'Google Slides',
        'application/vnd.google-apps.form' => 'Google Forms',
        'application/vnd.google-apps.drawing' => 'Google Drawings',
        'application/vnd.google-apps.folder' => 'folder Google Drive',
    ];

    /**
     * documents.get field mask with only what paragraphsOf() reads.
     * A converted .docx carries megabytes of per-paragraph styling; without the
     * mask the PHP client needs ~140 MB to hydrate it. Tables are fetched three
     * levels deep (a mask cannot recurse).
     */
    public const PREVIEW_FIELDS = 'title,revisionId,inlineObjects,body('.self::CONTENT_L1.')';

    private const PARAGRAPH = 'paragraph(elements(startIndex,endIndex,'
        .'textRun(content,textStyle(bold,italic,underline,link,backgroundColor)),'
        .'inlineObjectElement(inlineObjectId),person(personProperties(name)),'
        .'richLink(richLinkProperties(title)),pageBreak,horizontalRule,'
        .'footnoteReference(footnoteNumber),equation),'
        .'paragraphStyle(namedStyleType),bullet(nestingLevel))';

    private const CONTENT_L3 = 'content(startIndex,endIndex,'.self::PARAGRAPH.')';

    private const CONTENT_L2 = 'content(startIndex,endIndex,'.self::PARAGRAPH.',table(tableRows(tableCells('.self::CONTENT_L3.'))))';

    private const CONTENT_L1 = 'content(startIndex,endIndex,'.self::PARAGRAPH.',table(tableRows(tableCells('.self::CONTENT_L2.'))),tableOfContents(content(startIndex)))';

    /**
     * Null when the MIME type is a native Google Doc, otherwise a message the
     * user can act on.
     */
    public static function problem(string $mimeType): ?string
    {
        if ($mimeType === self::MIME) {
            return null;
        }

        if (isset(self::OFFICE_TEXT[$mimeType])) {
            return 'File '.self::OFFICE_TEXT[$mimeType].' tidak didukung, hanya Google Docs asli. '
                .'Buka file itu di Google Docs, pilih File > Save as Google Docs, lalu gunakan URL dokumen baru tersebut.';
        }

        if (isset(self::OTHER_GOOGLE[$mimeType])) {
            return 'Ini '.self::OTHER_GOOGLE[$mimeType].', bukan Google Docs. Project ini hanya mendukung Google Docs.';
        }

        return "Jenis file {$mimeType} tidak didukung. Project ini hanya mendukung Google Docs.";
    }

    public static function assertSupported(string $mimeType): void
    {
        if (self::problem($mimeType) !== null) {
            throw UnsupportedDocument::forMime($mimeType);
        }
    }
}

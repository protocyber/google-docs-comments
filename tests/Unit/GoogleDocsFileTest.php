<?php

namespace Tests\Unit;

use App\Services\UnsupportedDocument;
use App\Support\GoogleDocsFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleDocsFileTest extends TestCase
{
    public function test_native_google_doc_is_supported(): void
    {
        $this->assertNull(GoogleDocsFile::problem('application/vnd.google-apps.document'));
    }

    public static function unsupported(): array
    {
        return [
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Save as Google Docs'],
            'doc' => ['application/msword', 'Save as Google Docs'],
            'odt' => ['application/vnd.oasis.opendocument.text', 'Save as Google Docs'],
            'sheets' => ['application/vnd.google-apps.spreadsheet', 'Google Sheets'],
            'slides' => ['application/vnd.google-apps.presentation', 'Google Slides'],
            'pdf' => ['application/pdf', 'application/pdf'],
        ];
    }

    #[DataProvider('unsupported')]
    public function test_other_types_get_a_specific_message(string $mime, string $hint): void
    {
        $message = GoogleDocsFile::problem($mime);

        $this->assertNotNull($message);
        $this->assertStringContainsString($hint, $message);
    }

    public function test_exception_carries_the_message(): void
    {
        $e = UnsupportedDocument::forMime('application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertStringContainsString('Word', $e->getMessage());
    }

    public function test_not_found_message_mentions_access(): void
    {
        $this->assertStringContainsString('akses', UnsupportedDocument::notFound()->getMessage());
    }

    public function test_preview_field_mask_requests_only_what_the_preview_uses(): void
    {
        $fields = GoogleDocsFile::PREVIEW_FIELDS;

        foreach (['title', 'revisionId', 'inlineObjects', 'body(content(', 'textRun(content', 'table(tableRows(tableCells(content('] as $part) {
            $this->assertStringContainsString($part, $fields);
        }
        // the heavy parts of a converted Office file
        foreach (['documentStyle', 'namedStyles', 'paragraphStyle(lineSpacing'] as $part) {
            $this->assertStringNotContainsString($part, $fields);
        }
    }
}

<?php

namespace Tests\Unit;

use App\Support\GoogleDocsUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleDocsUrlTest extends TestCase
{
    private const ID = '1-wX4DWNISgrL822lo__G-JhVav3pfT3siBrCwBoOOo4';

    public static function accepted(): array
    {
        return [
            'edit url with tab' => ['https://docs.google.com/document/d/'.self::ID.'/edit?tab=t.0'],
            'edit url with fragment' => ['https://docs.google.com/document/d/'.self::ID.'/edit#heading=h.abc'],
            'view url' => ['https://docs.google.com/document/d/'.self::ID.'/view'],
            'no trailing path' => ['https://docs.google.com/document/d/'.self::ID],
            'account-scoped url' => ['https://docs.google.com/document/u/1/d/'.self::ID.'/edit'],
            'workspace domain url' => ['https://docs.google.com/a/example.com/document/d/'.self::ID.'/edit'],
            'without scheme' => ['docs.google.com/document/d/'.self::ID.'/edit'],
            'surrounding whitespace' => ['  https://docs.google.com/document/d/'.self::ID.'/edit  '],
            'bare id' => [self::ID],
        ];
    }

    #[DataProvider('accepted')]
    public function test_extracts_document_id(string $input): void
    {
        $this->assertSame(self::ID, GoogleDocsUrl::extractId($input));
    }

    public static function rejected(): array
    {
        return [
            'empty' => [''],
            'spreadsheet' => ['https://docs.google.com/spreadsheets/d/'.self::ID.'/edit'],
            'slides' => ['https://docs.google.com/presentation/d/'.self::ID.'/edit'],
            'drive file' => ['https://drive.google.com/file/d/'.self::ID.'/view'],
            'other host' => ['https://evil.example/document/d/'.self::ID.'/edit'],
            'too short id' => ['abc123'],
            'random text' => ['bukan url dokumen'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejects_non_docs_input(string $input): void
    {
        $this->assertNull(GoogleDocsUrl::extractId($input));
    }

    public function test_builds_edit_url_from_id(): void
    {
        $this->assertSame('https://docs.google.com/document/d/'.self::ID.'/edit', GoogleDocsUrl::editUrl(self::ID));
    }
}

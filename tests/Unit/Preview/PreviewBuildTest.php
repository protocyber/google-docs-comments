<?php

namespace Tests\Unit\Preview;

use App\Services\GoogleDocsPreview;
use PHPUnit\Framework\TestCase;
use Tests\Support\DocFixture;

class PreviewBuildTest extends TestCase
{
    public function test_build(): void
    {
        $doc = DocFixture::rich()->fetched(true, null);
        $r = GoogleDocsPreview::build($doc);

        $this->assertStringStartsWith('<div class="gd-page"', $r->html);
        $this->assertSame(['Roboto'], $r->fonts);
        $this->assertSame(['https://fonts.googleapis.com/css2?family=Roboto&display=swap'], $r->fontUrls());
        $this->assertCount(7, $r->threads);
        $this->assertSame('Fixture', $r->title);
        $this->assertSame('r1', $r->revisionId);
        $this->assertSame(2, $r->tabCount);
        $this->assertNull($r->commentsError);
    }

    public function test_build_without_comments_carries_error(): void
    {
        $r = GoogleDocsPreview::build(DocFixture::rich()->fetched(false, 'Komentar tidak bisa dimuat: tidak ada akses.'));
        $this->assertSame([], $r->threads);
        $this->assertSame('Komentar tidak bisa dimuat: tidak ada akses.', $r->commentsError);
        $this->assertStringNotContainsString('data-c=', $r->html);
    }

    public function test_font_urls_encode_family(): void
    {
        $r = new \App\Services\Preview\PreviewResult('', ['Open Sans', 'Roboto Mono'], [], '', null, 1, null);
        $this->assertSame([
            'https://fonts.googleapis.com/css2?family=Open+Sans&display=swap',
            'https://fonts.googleapis.com/css2?family=Roboto+Mono&display=swap',
        ], $r->fontUrls());
    }
}

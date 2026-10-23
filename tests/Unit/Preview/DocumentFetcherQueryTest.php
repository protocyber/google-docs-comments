<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\DocumentFetcher;
use App\Services\Preview\FetchedDocument;
use PHPUnit\Framework\TestCase;
use Tests\Support\DocFixture;

class DocumentFetcherQueryTest extends TestCase
{
    public function test_query_with_comments(): void
    {
        $q = DocumentFetcher::query(true);
        $this->assertSame('true', $q['includeTabsContent']);
        $this->assertSame('SUGGESTIONS_INLINE', $q['suggestionsViewMode']);
        $this->assertSame('COMMENTS_VIEW_MODE_INCLUDED', $q['commentsViewMode']);
        $this->assertSame(DocumentFetcher::fields(true), $q['fields']);
    }

    public function test_query_without_comments(): void
    {
        $q = DocumentFetcher::query(false);
        $this->assertArrayNotHasKey('commentsViewMode', $q);
        $this->assertArrayNotHasKey('suggestionsViewMode', $q);
    }

    public function test_field_mask(): void
    {
        $with = DocumentFetcher::fields(true);
        foreach (['tabs(', 'documentTab(', 'commentAnchors', 'comments(', 'headPost(', 'plainTextQuote', 'namedStyles', 'documentStyle', 'lists', 'tableCellStyle', 'suggestedInsertionIds'] as $part) {
            $this->assertStringContainsString($part, $with);
        }
        foreach (['contentHtml', 'suggestedTextStyleChanges', 'suggestedParagraphStyleChanges', 'emailAddress', 'user,', 'body(content(startIndex,endIndex,paragraph(elements(startIndex,endIndex,textRun(content,textStyle(bold'] as $part) {
            $this->assertStringNotContainsString($part, $with);
        }
        $without = DocumentFetcher::fields(false);
        $this->assertStringNotContainsString('comments(', $without);
        $this->assertStringNotContainsString('commentAnchors', $without);
        foreach ([$with, $without] as $mask) {
            $this->assertSame(substr_count($mask, '('), substr_count($mask, ')'));
        }
    }

    public function test_from_api_picks_first_tab_and_collects_other_tab_anchors(): void
    {
        $doc = DocFixture::rich()->fetched();
        $this->assertSame('Fixture', $doc->title);
        $this->assertSame('r1', $doc->revisionId);
        $this->assertSame('t.0', $doc->tabId);
        $this->assertSame(2, $doc->tabCount);
        $this->assertSame(['kix.c5'], $doc->otherTabAnchorIds);
        $this->assertCount(7, $doc->comments);
        $this->assertSame('Laporan Hasil Pemeriksaan', mb_substr($doc->content()[1]['paragraph']['elements'][0]['textRun']['content'], 0, 25));

        $plain = DocFixture::rich()->fetched(false);
        $this->assertNull($plain->comments);
    }

    public function test_from_api_counts_child_tabs(): void
    {
        $body = DocFixture::rich()->api();
        $body['tabs'][0]['childTabs'] = [['tabProperties' => ['tabId' => 't.0.1'], 'documentTab' => [
            'commentAnchors' => ['kix.child' => ['anchorId' => 'kix.child', 'ranges' => []]],
        ]]];
        $doc = FetchedDocument::fromApi($body, true);
        $this->assertSame(3, $doc->tabCount);
        $this->assertContains('kix.child', $doc->otherTabAnchorIds);
    }

    public function test_from_api_parses_real_probe_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 2).'/Fixtures/docs/probe-tes.json';
        $body = json_decode(file_get_contents($fixturePath), true);
        $doc = FetchedDocument::fromApi($body, true);

        $this->assertNotEmpty($doc->tabId);
        $this->assertSame(1, $doc->tabCount);
        $this->assertCount(10, $doc->comments);
        $this->assertCount(4, $doc->tab['commentAnchors'] ?? []);
    }
}

<?php

namespace Tests\Unit;

use App\Services\GoogleDocsCommenter;
use Google\Service\Docs\Document;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GoogleDocsCommenterTest extends TestCase
{
    /** Body as the Docs API returns it: section break, two paragraphs, an inline image, emoji. */
    private function document(): Document
    {
        return new Document([
            'body' => ['content' => [
                ['startIndex' => 0, 'endIndex' => 1, 'sectionBreak' => []],
                ['startIndex' => 1, 'endIndex' => 13, 'paragraph' => ['elements' => [
                    ['startIndex' => 1, 'endIndex' => 13, 'textRun' => ['content' => "Hello world\n"]],
                ]]],
                ['startIndex' => 13, 'endIndex' => 14, 'paragraph' => ['elements' => [
                    ['startIndex' => 13, 'endIndex' => 14, 'textRun' => ['content' => "\n"]],
                ]]],
                // "😀 ab" + image + " Temuan\n": the emoji is 2 UTF-16 units, the image 1.
                ['startIndex' => 14, 'endIndex' => 28, 'paragraph' => ['elements' => [
                    ['startIndex' => 14, 'endIndex' => 19, 'textRun' => ['content' => '😀 ab']],
                    ['startIndex' => 19, 'endIndex' => 20, 'inlineObjectElement' => ['inlineObjectId' => 'img']],
                    ['startIndex' => 20, 'endIndex' => 28, 'textRun' => ['content' => " Temuan\n"]],
                ]]],
            ]],
        ]);
    }

    public function test_lists_text_paragraphs_keyed_by_start_and_skips_empty_ones(): void
    {
        $paragraphs = GoogleDocsCommenter::paragraphsOf($this->document());

        $this->assertSame([1, 14], array_keys($paragraphs));
        $this->assertSame("Hello world\n", $paragraphs[1]['text']);
        $this->assertSame(28, $paragraphs[14]['end']);
    }

    public function test_locates_quote_by_utf16_index(): void
    {
        $paragraphs = GoogleDocsCommenter::paragraphsOf($this->document());

        $this->assertSame(['start' => 7, 'end' => 12, 'quote' => 'world'],
            GoogleDocsCommenter::locate($paragraphs[1], 'world'));

        // emoji (2) + " ab" (3) + image (1) + " " (1) => "Temuan" starts at 14 + 7.
        $this->assertSame(['start' => 21, 'end' => 27, 'quote' => 'Temuan'],
            GoogleDocsCommenter::locate($paragraphs[14], 'Temuan'));
    }

    public function test_empty_quote_selects_whole_paragraph_without_newline(): void
    {
        $paragraphs = GoogleDocsCommenter::paragraphsOf($this->document());

        $this->assertSame(['start' => 1, 'end' => 12, 'quote' => 'Hello world'],
            GoogleDocsCommenter::locate($paragraphs[1], ''));
    }

    public function test_missing_quote_is_rejected(): void
    {
        $paragraphs = GoogleDocsCommenter::paragraphsOf($this->document());

        $this->expectException(RuntimeException::class);
        GoogleDocsCommenter::locate($paragraphs[1], 'World');
    }

    public function test_native_comment_request_targets_the_exact_range(): void
    {
        $this->assertSame(
            ['requests' => [['insertComment' => [
                'content' => 'Perlu bukti',
                'range' => ['startIndex' => 7, 'endIndex' => 12],
            ]]]],
            GoogleDocsCommenter::insertCommentPayload(['start' => 7, 'end' => 12, 'quote' => 'world'], 'Perlu bukti'),
        );
    }

    public function test_native_comment_response_yields_id_and_anchor(): void
    {
        $response = [
            'replies' => [['insertComment' => ['commentThread' => [
                'commentId' => 'AAACI3Q2YPc', 'anchorId' => 'kix.w58z03ryceki',
                'headPost' => ['postId' => 'AAACI3Q2YPc'], 'status' => 'OPEN', 'plainTextQuote' => 'world',
            ]]]],
            'commentUpdateState' => 'ALL_SAVED',
        ];

        $this->assertSame(
            ['comment_id' => 'AAACI3Q2YPc', 'anchor' => 'kix.w58z03ryceki', 'quote' => 'world'],
            GoogleDocsCommenter::parseInsertCommentResponse($response),
        );
    }

    public function test_native_comment_partial_failure_is_an_error(): void
    {
        // Docs API may commit the batch but fail to save the comment thread.
        $this->expectException(RuntimeException::class);
        GoogleDocsCommenter::parseInsertCommentResponse([
            'replies' => [['insertComment' => ['commentThread' => ['commentId' => 'x']]]],
            'commentUpdateState' => 'ALL_FAILED_UNKNOWN_REASON',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Services\DocumentChanged;
use App\Services\GoogleDocsCommenter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Tests\TestCase;

class GoogleDocsCommenterWriteTest extends TestCase
{
    private array $history = [];

    private function commenter(Response ...$responses): GoogleDocsCommenter
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);

        $commenter = $this->getMockBuilder(GoogleDocsCommenter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['http'])
            ->getMock();
        $commenter->method('http')->willReturn($client);

        return $commenter;
    }

    private function body(int $n): array
    {
        return json_decode((string) $this->history[$n]['request']->getBody(), true);
    }

    public function test_apply_edit_sends_required_revision(): void
    {
        $requests = [['insertText' => ['location' => ['index' => 1], 'text' => 'x']]];
        $commenter = $this->commenter(new Response(200, [], json_encode([
            'writeControl' => ['requiredRevisionId' => 'r2'],
        ])));

        $newRevision = $commenter->applyEdit('DOC', 'r1', $requests);

        $this->assertSame('r2', $newRevision);
        $this->assertSame([
            'requests' => $requests,
            'writeControl' => ['requiredRevisionId' => 'r1'],
        ], $this->body(0));
        $this->assertStringEndsWith('/v1/documents/DOC:batchUpdate', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_stale_revision_throws_document_changed(): void
    {
        $commenter = $this->commenter(new Response(400, [], json_encode([
            'error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'message' => "The required revision ID 'r1' does not match the latest revision.",
            ],
        ])));

        $this->expectException(DocumentChanged::class);
        $commenter->applyEdit('DOC', 'r1', []);
    }

    public function test_other_invalid_argument_is_not_document_changed(): void
    {
        $commenter = $this->commenter(new Response(400, [], json_encode([
            'error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'message' => 'Invalid requests[0].insertText: text field is required',
            ],
        ])));

        try {
            $commenter->applyEdit('DOC', 'r1', []);
            $this->fail('expected RuntimeException');
        } catch (DocumentChanged $e) {
            $this->fail('should not be DocumentChanged');
        } catch (RuntimeException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_other_error_keeps_google_message(): void
    {
        $commenter = $this->commenter(new Response(400, [], json_encode([
            'error' => ['message' => 'Invalid requests[0]'],
        ])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Docs API: Invalid requests[0]');
        $commenter->applyEdit('DOC', 'r1', []);
    }

    public function test_resolve_posts_drive_reply(): void
    {
        $commenter = $this->commenter(new Response(200, [], json_encode(['id' => 'reply1'])));

        $commenter->resolveComment('DOC', 'c1', 'Sudah diperbaiki');

        $this->assertSame([
            'action' => 'resolve',
            'content' => 'Sudah diperbaiki',
        ], $this->body(0));
        $this->assertStringEndsWith('/drive/v3/files/DOC/comments/c1/replies', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_resolve_with_blank_reply_sends_only_action(): void
    {
        $commenter = $this->commenter(new Response(200, [], json_encode(['id' => 'reply1'])));

        $commenter->resolveComment('DOC', 'c1', '');

        $this->assertSame(['action' => 'resolve'], $this->body(0));
    }

    public function test_file_info_reads_capabilities(): void
    {
        $commenter = $this->commenter(new Response(200, [], json_encode([
            'mimeType' => 'application/vnd.google-apps.document',
            'capabilities' => ['canEdit' => true, 'canComment' => true],
        ])));

        $this->assertSame([
            'mimeType' => 'application/vnd.google-apps.document',
            'canEdit' => true,
            'canComment' => true,
        ], $commenter->fileInfo('DOC'));
    }

    public function test_file_info_missing_capabilities_is_false(): void
    {
        $commenter = $this->commenter(new Response(200, [], json_encode([
            'mimeType' => 'application/vnd.google-apps.document',
        ])));

        $this->assertSame([
            'mimeType' => 'application/vnd.google-apps.document',
            'canEdit' => false,
            'canComment' => false,
        ], $commenter->fileInfo('DOC'));
    }
}

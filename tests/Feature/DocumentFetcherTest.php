<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDocsCommenter;
use App\Services\Preview\DocumentFetcher;
use App\Services\UnsupportedDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\Support\DocFixture;
use Tests\TestCase;

class DocumentFetcherTest extends TestCase
{
    private array $history = [];

    private function fakeGoogle(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('assertCommentable')->once()->with('DOC');
        $commenter->shouldReceive('http')->andReturn(new Client(['handler' => $stack]));
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);
    }

    private function query(int $n): array
    {
        parse_str($this->history[$n]['request']->getUri()->getQuery(), $q);

        return $q;
    }

    public function test_fetches_with_comments(): void
    {
        $this->fakeGoogle(new Response(200, [], json_encode(DocFixture::rich()->api())));

        $doc = app(DocumentFetcher::class)->fetch(User::factory()->make(), 'DOC');

        $this->assertCount(7, $doc->comments);
        $this->assertNull($doc->commentsError);
        $this->assertSame('COMMENTS_VIEW_MODE_INCLUDED', $this->query(0)['commentsViewMode']);
        $this->assertStringEndsWith('/v1/documents/DOC', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_403_on_comments_falls_back_without_comments(): void
    {
        $this->fakeGoogle(
            new Response(403, [], json_encode(['error' => ['message' => 'forbidden']])),
            new Response(200, [], json_encode(DocFixture::rich()->api(false))),
        );

        $doc = app(DocumentFetcher::class)->fetch(User::factory()->make(), 'DOC');

        $this->assertNull($doc->comments);
        $this->assertSame('Komentar tidak bisa dimuat: tidak ada akses.', $doc->commentsError);
        $this->assertArrayNotHasKey('commentsViewMode', $this->query(1));
    }

    public function test_404_is_unsupported_document(): void
    {
        $this->fakeGoogle(new Response(404, [], '{}'));
        $this->expectException(UnsupportedDocument::class);
        app(DocumentFetcher::class)->fetch(User::factory()->make(), 'DOC');
    }

    public function test_other_errors_carry_google_message(): void
    {
        $this->fakeGoogle(new Response(400, [], json_encode(['error' => ['message' => 'Invalid field mask']])));
        $this->expectExceptionMessage('Google Docs API: Invalid field mask');
        app(DocumentFetcher::class)->fetch(User::factory()->make(), 'DOC');
    }
}

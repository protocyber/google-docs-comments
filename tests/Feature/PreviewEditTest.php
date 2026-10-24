<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\Pages\CommentFromPreview;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentChanged;
use App\Services\GoogleDocsCommenter;
use App\Services\Preview\DocumentFetcher;
use App\Services\Preview\EditRequest;
use App\Services\Preview\FetchedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery;
use Tests\Support\DocFixture;
use Tests\TestCase;

class PreviewEditTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->project = Project::create(['name' => 'PRD', 'google_docs_id' => 'DOC']);
    }

    /** Each fetch returns the next document; the last one repeats. */
    private function fakeFetcher(FetchedDocument ...$docs): void
    {
        $this->app->instance(DocumentFetcher::class, new class($docs) extends DocumentFetcher
        {
            public function __construct(private array $docs) {}

            public function fetch(User $user, string $documentId): FetchedDocument
            {
                return count($this->docs) > 1 ? array_shift($this->docs) : $this->docs[0];
            }
        });
    }

    private function page()
    {
        return Livewire::test(CommentFromPreview::class, ['record' => $this->project->getRouteKey()]);
    }

    public function test_save_edit_applies_requests_with_revision(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched());

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('applyEdit')->once()
            ->with('DOC', 'r1', EditRequest::build(10, 12, 'xy'))
            ->andReturn('r2');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page();
        $return = $testable->instance()->saveEdit('r1', 10, 12, 'xy');
        $this->assertSame(['ok' => true, 'revisionId' => 'r2'], $return);
        $testable->assertSet('revisionId', 'r2');
    }

    public function test_conflict_is_reported_not_thrown(): void
    {
        $f = DocFixture::rich();
        $other = DocFixture::rich('Baris baru di atas');
        $this->fakeFetcher($f->fetched(), $other->fetched());

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('applyEdit')->once()->with('DOC', 'r1', Mockery::type('array'))
            ->andThrow(new DocumentChanged('stale'));
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page();
        $result = $testable->instance()->saveEdit('r1', 10, 12, 'xy');
        $this->assertFalse($result['ok']);
        $this->assertSame('conflict', $result['reason']);
    }

    /** Same text, different revisionId (e.g. only a comment was added/resolved). */
    private function withRevision(DocFixture $f, string $revisionId): FetchedDocument
    {
        $body = $f->api();
        $body['revisionId'] = $revisionId;

        return FetchedDocument::fromApi($body, true, null, ['canEdit' => true, 'canComment' => true]);
    }

    public function test_comment_only_revision_retries_silently(): void
    {
        $f = DocFixture::rich();
        // Re-fetch returns the *same* text, just a new revisionId (comment bump).
        $same = $this->withRevision($f, 'r9');
        $this->fakeFetcher($f->fetched(), $same);

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('applyEdit')->once()->with('DOC', 'r1', Mockery::type('array'))
            ->andThrow(new DocumentChanged('stale'));
        $commenter->shouldReceive('applyEdit')->once()->with('DOC', 'r9', Mockery::type('array'))
            ->andReturn('r10');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page();
        $result = $testable->instance()->saveEdit('r1', 10, 12, 'xy');
        $this->assertSame(['ok' => true, 'revisionId' => 'r10'], $result);
    }

    public function test_retry_stale_again_is_conflict(): void
    {
        $f = DocFixture::rich();
        $same = $this->withRevision($f, 'r9');
        $this->fakeFetcher($f->fetched(), $same);

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('applyEdit')->twice()->andThrow(new DocumentChanged('stale'));
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page();
        $result = $testable->instance()->saveEdit('r1', 10, 12, 'xy');
        $this->assertFalse($result['ok']);
        $this->assertSame('conflict', $result['reason']);
    }

    public function test_missing_base_is_conflict(): void
    {
        $f = DocFixture::rich();
        $this->fakeFetcher($f->fetched(), $f->fetched());

        Cache::flush();

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('applyEdit')->once()->andThrow(new DocumentChanged('stale'));
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page();
        Cache::flush();
        $result = $testable->instance()->saveEdit('r1', 10, 12, 'xy');
        $this->assertFalse($result['ok']);
        $this->assertSame('conflict', $result['reason']);
    }

    private function fetchedFrom(array $content, string $revisionId): FetchedDocument
    {
        return new FetchedDocument(
            title: 'Fixture',
            revisionId: $revisionId,
            tabId: 't.0',
            tab: ['body' => ['content' => $content]],
            tabCount: 1,
            comments: [],
            otherTabAnchorIds: [],
            canEdit: true,
            canComment: true,
        );
    }

    public function test_base_follows_saves(): void
    {
        $original = [['startIndex' => 1, 'endIndex' => 13, 'paragraph' => ['elements' => [
            ['startIndex' => 1, 'endIndex' => 13, 'textRun' => ['content' => "Hello world\n"]],
        ]]]];
        // After saveEdit('r1', 1, 1, 'X') the text becomes "XHello world\n".
        $edited = [['startIndex' => 1, 'endIndex' => 14, 'paragraph' => ['elements' => [
            ['startIndex' => 1, 'endIndex' => 14, 'textRun' => ['content' => "XHello world\n"]],
        ]]]];

        $this->fakeFetcher(
            $this->fetchedFrom($original, 'r1'),
            $this->fetchedFrom($edited, 'r3'), // re-fetch during the second save's recovery
        );

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('applyEdit')->once()->with('DOC', 'r1', Mockery::type('array'))->andReturn('r2');
        $commenter->shouldReceive('applyEdit')->once()->with('DOC', 'r2', Mockery::type('array'))->andThrow(new DocumentChanged('stale'));
        $commenter->shouldReceive('applyEdit')->once()->with('DOC', 'r3', Mockery::type('array'))->andReturn('r4');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page();
        $first = $testable->instance()->saveEdit('r1', 1, 1, 'X');
        $this->assertSame(['ok' => true, 'revisionId' => 'r2'], $first);

        // r2's base (stored above) is "XHello world\n"; the stale re-fetch at
        // r3 matches it, so this retries instead of reporting a conflict.
        $second = $testable->instance()->saveEdit('r2', 2, 2, 'Y');
        $this->assertSame(['ok' => true, 'revisionId' => 'r4'], $second);
    }

    public function test_invalid_edit_never_calls_google(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched());

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldNotReceive('applyEdit');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page();
        $result = $testable->instance()->saveEdit('r1', 10, 10, "\u{FFFC}");
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid', $result['reason']);
    }

    public function test_read_only_user_cannot_save(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched(canEdit: false));

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldNotReceive('applyEdit');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page();
        $result = $testable->instance()->saveEdit('r1', 10, 12, 'xy');
        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['reason']);

        $testable
            ->assertSee('Anda tidak punya akses edit ke dokumen ini.')
            ->assertDontSeeHtml('id="gd-mode-edit"');
    }

    public function test_toolbar_has_version_history(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched());

        $this->page()
            ->assertSee('Riwayat versi')
            ->assertSeeHtml('https://docs.google.com/document/d/DOC/edit')
            ->assertSeeHtml('target="_blank"')
            ->assertSee('Ctrl+Alt+Shift+H');
    }

    public function test_resolve_reloads_and_activates_next(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched(), DocFixture::rich()->fetched());

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('resolveComment')->once()->with('DOC', 'c2', 'Sudah');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $this->page()
            ->call('resolveComment', 'c2', 'Sudah', 'c3')
            ->assertDispatched('gd-preview-reloaded', activate: 'c3');
    }

    public function test_resolve_without_comment_access_is_refused(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched(canComment: false));

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldNotReceive('resolveComment');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $testable = $this->page()
            ->call('resolveComment', 'c2', '', 'c3')
            ->assertNotDispatched('gd-preview-reloaded');
        $testable
            ->assertDontSeeHtml('class="gd-resolve-btn"')
            ->assertDontSee('Tandai selesai');
    }
}

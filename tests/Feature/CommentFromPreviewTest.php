<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\Pages\CommentFromPreview;
use App\Models\Project;
use App\Models\User;
use App\Services\GoogleDocsCommenter;
use App\Services\Preview\DocumentFetcher;
use App\Services\Preview\FetchedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Support\DocFixture;
use Tests\TestCase;

class CommentFromPreviewTest extends TestCase
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

    public function test_lists_comments_and_highlights_their_text(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched());

        $this->page()
            ->assertSet('loadError', null)
            ->assertSee('Komentar (6)')
            ->assertSee('Tampilkan yang selesai (1)')
            ->assertSeeHtml('data-comment-id="c1"')
            ->assertSeeHtml('data-c="c2 c3"')
            ->assertSee('Lihat 2 balasan lain')
            ->assertSee('di tab lain')
            ->assertSee('teks asli sudah dihapus')
            ->assertSee('lokasi tidak diketahui')
            ->assertSee('Dokumen ini punya 2 tab');
    }

    public function test_comment_content_is_escaped(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched());

        $this->page()
            ->assertSee('Komentar <script>alert(1)</script>')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_without_comment_access_preview_still_renders(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched(false, 'Komentar tidak bisa dimuat: tidak ada akses.'));

        $this->page()
            ->assertSee('Laporan Hasil Pemeriksaan')
            ->assertSee('Komentar tidak bisa dimuat: tidak ada akses.')
            ->assertDontSeeHtml('data-comment-id=');
    }

    public function test_add_comment_activates_the_new_comment(): void
    {
        $f = DocFixture::rich();
        $this->fakeFetcher($f->fetched());
        [$s, $e] = $f->pos('Paragraf pengisi nomor 3 untuk menguji scroll.');

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldReceive('commentOnRange')->once()
            ->with('DOC', ['start' => $s, 'end' => $e, 'quote' => 'Paragraf pengisi nomor 3 untuk menguji scroll.'], 'Cek lagi')
            ->andReturn(['comment_id' => 'new1', 'anchor' => 'kix.new1', 'quote' => 'x', 'link' => 'https://docs.google.com/document/d/DOC/edit?disco=new1']);
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $this->page()
            ->call('addComment', $s, $e, 'Paragraf pengisi nomor 3 untuk menguji scroll.', 'Cek lagi')
            ->assertDispatched('gd-preview-reloaded', activate: 'new1');
    }

    public function test_changed_document_refuses_and_reloads(): void
    {
        $f = DocFixture::rich();
        [$s, $e] = $f->pos('Paragraf pengisi nomor 3 untuk menguji scroll.');
        $this->fakeFetcher($f->fetched(), DocFixture::rich('Baris baru di atas')->fetched());

        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldNotReceive('commentOnRange');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $this->page()
            ->call('addComment', $s, $e, 'Paragraf pengisi nomor 3 untuk menguji scroll.', 'Cek lagi')
            ->assertDispatched('gd-preview-reloaded')
            ->assertSee('Baris baru di atas');
    }

    public function test_empty_comment_is_rejected(): void
    {
        $this->fakeFetcher(DocFixture::rich()->fetched());
        $commenter = Mockery::mock(GoogleDocsCommenter::class);
        $commenter->shouldNotReceive('commentOnRange');
        $this->app->bind(GoogleDocsCommenter::class, fn () => $commenter);

        $this->page()->call('addComment', 1, 8, 'Laporan', '   ')->assertNotDispatched('gd-preview-reloaded');
    }

    public function test_load_error_is_shown(): void
    {
        $this->app->instance(DocumentFetcher::class, new class extends DocumentFetcher
        {
            public function fetch(User $user, string $documentId): FetchedDocument
            {
                throw new \RuntimeException('Sesi Google kedaluwarsa.');
            }
        });

        $this->page()->assertSet('loadError', 'Sesi Google kedaluwarsa.')->assertSee('Coba lagi');
    }
}

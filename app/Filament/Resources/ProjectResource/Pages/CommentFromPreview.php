<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Services\GoogleDocsCommenter;
use App\Services\GoogleDocsPreview;
use App\Services\Preview\DocumentFetcher;
use App\Services\Preview\FetchedDocument;
use App\Services\Preview\PreviewResult;
use App\Services\Preview\TextIndex;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;

/**
 * Comment on a Google Doc by selecting text in a preview that mirrors the
 * editor; existing comments are listed and linked to their text.
 * See docs/superpowers/specs/2026-10-20-gdocs-preview-fidelity-comments-design.md (root repo).
 */
class CommentFromPreview extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static string $view = 'filament.pages.comment-from-preview';

    public string $html = '';

    /** @var list<string> */
    public array $fontUrls = [];

    /** @var list<array> */
    public array $threads = [];

    public string $documentTitle = '';

    public ?string $revisionId = null;

    public int $tabCount = 1;

    public ?string $commentsError = null;

    public ?string $loadError = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->loadDocument();
    }

    public function getTitle(): string
    {
        return 'Komentar dari preview: '.$this->record->name;
    }

    public function loadDocument(): void
    {
        try {
            $this->apply(GoogleDocsPreview::build($this->fetch()));
            $this->loadError = null;
        } catch (\Throwable $e) {
            $this->loadError = self::message($e);
        }
    }

    /**
     * Called from the browser with the Docs indexes of the selection and the
     * text the user saw selected.
     */
    public function addComment(int $start, int $end, string $seen, string $comment): void
    {
        $comment = trim($comment);
        if ($comment === '' || $end <= $start) {
            Notification::make()->title('Pilih teks dan isi komentar dulu')->warning()->send();

            return;
        }

        try {
            $document = $this->fetch();

            // Indexes belong to the rendered revision. Accept the range only if
            // it still holds the same text; otherwise refuse and reload.
            $quote = TextIndex::textBetween($document->content(), $start, $end);
            if (self::normalize($quote) !== self::normalize($seen)) {
                $this->apply(GoogleDocsPreview::build($document));
                $this->dispatch('gd-preview-reloaded');
                Notification::make()
                    ->title('Dokumen berubah')
                    ->body('Isi dokumen di lokasi itu sudah berbeda. Preview dimuat ulang, silakan pilih lagi.')
                    ->warning()->persistent()->send();

                return;
            }

            $result = app(GoogleDocsCommenter::class, ['user' => auth()->user()])->commentOnRange(
                $this->record->google_docs_id,
                ['start' => $start, 'end' => $end, 'quote' => rtrim($quote, "\n")],
                $comment,
            );

            Notification::make()
                ->title('Komentar ditambahkan')
                ->body('Teks: "'.Str::limit(trim($quote), 80).'"')
                ->actions([NotificationAction::make('open')->label('Buka di Google Docs')->url($result['link'], shouldOpenInNewTab: true)])
                ->success()->send();

            $this->loadDocument();
            $this->dispatch('gd-preview-reloaded', activate: $result['comment_id']);
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body(self::message($e))->danger()->persistent()->send();
        }
    }

    private function apply(PreviewResult $r): void
    {
        $this->html = $r->html;
        $this->fontUrls = $r->fontUrls();
        $this->threads = $r->threads;
        $this->documentTitle = $r->title;
        $this->revisionId = $r->revisionId;
        $this->tabCount = $r->tabCount;
        $this->commentsError = $r->commentsError;
    }

    private function fetch(): FetchedDocument
    {
        return app(DocumentFetcher::class)->fetch(auth()->user(), $this->record->google_docs_id);
    }

    private static function normalize(string $text): string
    {
        // Paragraph ends, soft breaks and table cells differ between browser
        // and API; atoms are U+FFFC on both sides. Compare letters, digits, atoms.
        return preg_replace('/[^\p{L}\p{N}\x{FFFC}]+/u', '', $text);
    }

    private static function message(\Throwable $e): string
    {
        if ($e instanceof \Google\Service\Exception) {
            return json_decode($e->getMessage(), true)['error']['message'] ?? $e->getMessage();
        }

        return $e->getMessage();
    }
}

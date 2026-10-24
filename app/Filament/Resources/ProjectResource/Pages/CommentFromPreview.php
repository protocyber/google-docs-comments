<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Services\DocumentChanged;
use App\Services\GoogleDocsCommenter;
use App\Services\GoogleDocsPreview;
use App\Services\Preview\DocumentFetcher;
use App\Services\Preview\EditBase;
use App\Services\Preview\EditRequest;
use App\Services\Preview\FetchedDocument;
use App\Services\Preview\PreviewResult;
use App\Services\Preview\TextIndex;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

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

    #[Locked]
    public bool $canEdit = false;

    #[Locked]
    public bool $canComment = false;

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
            $document = $this->fetch();
            $this->apply(GoogleDocsPreview::build($document));
            $this->storeBase($document);
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
                $this->storeBase($document);
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

    /**
     * Applies one text replacement to the live Google Doc. Always skips the
     * Livewire render: a mid-edit re-render would overwrite the user's DOM.
     *
     * @return array{ok: true, revisionId: string}|array{ok: false, reason: string, message?: string}
     */
    public function saveEdit(string $revisionId, int $start, int $end, string $text): array
    {
        $this->skipRender();

        if (! $this->canEdit) {
            return ['ok' => false, 'reason' => 'forbidden', 'message' => 'Anda tidak punya akses edit ke dokumen ini.'];
        }

        try {
            $requests = EditRequest::build($start, $end, $text);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'reason' => 'invalid', 'message' => $e->getMessage()];
        }

        $docId = $this->record->google_docs_id;
        $commenter = app(GoogleDocsCommenter::class, ['user' => auth()->user()]);

        try {
            $newRevision = $commenter->applyEdit($docId, $revisionId, $requests);
            $this->advanceBase($revisionId, $newRevision, $start, $end, $text);
            $this->revisionId = $newRevision;

            return ['ok' => true, 'revisionId' => $newRevision];
        } catch (DocumentChanged $e) {
            return $this->recoverStaleRevision($commenter, $docId, $revisionId, $start, $end, $text);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'error', 'message' => self::message($e)];
        }
    }

    /**
     * Only comments changed since $revisionId (an insertComment/resolve also
     * bumps revisionId): re-fetch and compare the indexed text against the
     * cached base. Equal -> the document text itself did not move, so retry
     * the same edit once against the fresh revision. Anything else -
     * different text, missing base, or stale again - is a genuine conflict.
     */
    private function recoverStaleRevision(
        GoogleDocsCommenter $commenter,
        string $docId,
        string $staleRevision,
        int $start,
        int $end,
        string $text,
    ): array {
        $base = EditBase::get(auth()->id(), $docId, $staleRevision);
        if ($base === null) {
            return ['ok' => false, 'reason' => 'conflict'];
        }

        try {
            $document = $this->fetch();
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'error', 'message' => self::message($e)];
        }

        $fresh = TextIndex::indexed($document->content());
        if ($fresh !== $base || $document->revisionId === null) {
            return ['ok' => false, 'reason' => 'conflict'];
        }

        $freshRevision = $document->revisionId;
        EditBase::put(auth()->id(), $docId, $freshRevision, $fresh);

        try {
            $requests = EditRequest::build($start, $end, $text);
            $newRevision = $commenter->applyEdit($docId, $freshRevision, $requests);
            $this->advanceBase($freshRevision, $newRevision, $start, $end, $text);
            $this->revisionId = $newRevision;

            return ['ok' => true, 'revisionId' => $newRevision];
        } catch (DocumentChanged $e) {
            return ['ok' => false, 'reason' => 'conflict'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'error', 'message' => self::message($e)];
        }
    }

    /** After a successful save, the base for the new revision is the old base spliced by the same edit. */
    private function advanceBase(string $oldRevision, string $newRevision, int $start, int $end, string $text): void
    {
        $userId = auth()->id();
        $base = EditBase::get($userId, $this->record->google_docs_id, $oldRevision);
        if ($base === null) {
            return;
        }

        EditBase::put($userId, $this->record->google_docs_id, $newRevision, TextIndex::splice($base, $start, $end, $text));
    }

    private function storeBase(FetchedDocument $document): void
    {
        if ($document->revisionId === null) {
            return;
        }

        EditBase::put(auth()->id(), $this->record->google_docs_id, $document->revisionId, TextIndex::indexed($document->content()));
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
        $this->canEdit = $r->canEdit;
        $this->canComment = $r->canComment;
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

<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Services\GoogleDocsCommenter;
use App\Services\GoogleDocsPreview;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Google\Service\Docs;
use Google\Service\Docs\Document;
use Illuminate\Support\Str;

/**
 * Spike: pick the comment location by selecting text in a document preview.
 * See docs/spikes/gdocs-preview-selection.md in the ewasgo root repo.
 */
class CommentFromPreview extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static string $view = 'filament.pages.comment-from-preview';

    public string $html = '';

    public string $documentTitle = '';

    public ?string $revisionId = null;

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
            $document = $this->fetch();
            $this->html = GoogleDocsPreview::render($document);
            $this->documentTitle = (string) $document->getTitle();
            $this->revisionId = $document->getRevisionId();
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

            // Indexes are only valid for the revision that was rendered. If the
            // document changed, accept the range only if it still holds the
            // same text; otherwise refuse instead of commenting the wrong place.
            $quote = GoogleDocsPreview::textBetween($document, $start, $end);
            if (self::normalize($quote) !== self::normalize($seen)) {
                $this->loadDocument();
                $this->dispatch('gd-preview-reloaded');
                Notification::make()
                    ->title('Dokumen berubah')
                    ->body('Isi dokumen di lokasi itu sudah berbeda. Preview dimuat ulang, silakan pilih lagi.')
                    ->warning()->persistent()->send();

                return;
            }

            $result = (new GoogleDocsCommenter(auth()->user()))->commentOnRange(
                $this->record->google_docs_id,
                ['start' => $start, 'end' => $end, 'quote' => rtrim($quote, "\n")],
                $comment,
            );

            Notification::make()
                ->title('Komentar ditambahkan')
                ->body('Teks: "'.Str::limit(trim($quote), 80).'"')
                ->actions([NotificationAction::make('open')->label('Buka di Google Docs')->url($result['link'], shouldOpenInNewTab: true)])
                ->success()->send();

            $this->loadDocument(); // refresh indexes for the next comment
            $this->dispatch('gd-preview-reloaded');
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body(self::message($e))->danger()->persistent()->send();
        }
    }

    private function fetch(): Document
    {
        $commenter = new GoogleDocsCommenter(auth()->user());
        $client = (fn () => $this->client)->call($commenter);

        return (new Docs($client))->documents->get($this->record->google_docs_id);
    }

    private static function normalize(string $text): string
    {
        // The browser renders paragraph ends and soft breaks as \n and table
        // cells with extra blank lines; atoms are U+FFFC on both sides.
        // Compare letters, digits and atoms only.
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

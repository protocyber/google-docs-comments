<?php

namespace App\Filament\Resources\ProjectResource\Actions;

use App\Models\Project;
use App\Services\GoogleDocsCommenter;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Str;

/**
 * Spike: positioned Google Docs comments. See docs/spikes/gdocs-positioned-comments.md in the ewasgo root repo.
 */
class PositionedCommentActions
{
    public static function insertText(): Action
    {
        return Action::make('insertTextAtPosition')
            ->label('Sisipkan Teks di Posisi')
            ->icon('heroicon-o-bars-arrow-down')
            ->modalDescription('Teks disisipkan sebagai paragraf baru di sebelum/sesudah paragraf yang dipilih.')
            ->form(fn (Project $record) => [
                self::paragraphSelect($record),
                Forms\Components\Radio::make('placement')
                    ->label('Posisi')
                    ->options(['after' => 'Sesudah paragraf', 'before' => 'Sebelum paragraf'])
                    ->default('after')
                    ->inline()
                    ->required(),
                Forms\Components\Textarea::make('text')->label('Teks')->required()->rows(3),
            ])
            ->action(function (Project $record, array $data) {
                self::run(function () use ($record, $data) {
                    $commenter = new GoogleDocsCommenter(auth()->user());
                    $paragraph = self::freshParagraph($commenter, $record, $data);
                    $commenter->insertText($record->google_docs_id, $paragraph, $data['text'], $data['placement']);

                    Notification::make()->title('Teks disisipkan')->success()->send();
                });
            })
            ->visible(fn (Project $record) => filled($record->google_docs_id));
    }

    public static function commentOnText(): Action
    {
        return Action::make('commentOnText')
            ->label('Komentar di Teks')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->modalDescription('Komentar asli Google Docs, menempel pada teks yang dipilih.')
            ->form(fn (Project $record) => [
                self::paragraphSelect($record),
                Forms\Components\TextInput::make('quote')
                    ->label('Teks yang dikomentari (opsional)')
                    ->helperText('Salin persis bagian dari paragraf itu. Kosongkan untuk mengomentari seluruh paragraf.'),
                Forms\Components\Textarea::make('comment')->label('Komentar')->required()->rows(3),
            ])
            ->action(function (Project $record, array $data) {
                self::run(function () use ($record, $data) {
                    $commenter = new GoogleDocsCommenter(auth()->user());
                    $paragraph = self::freshParagraph($commenter, $record, $data);
                    $range = GoogleDocsCommenter::locate($paragraph, $data['quote'] ?? null);
                    $result = $commenter->commentOnRange($record->google_docs_id, $range, $data['comment']);

                    Notification::make()
                        ->title('Komentar ditambahkan')
                        ->body('Teks: "'.Str::limit($range['quote'], 80).'"')
                        ->actions([
                            \Filament\Notifications\Actions\Action::make('open')
                                ->label('Buka di Google Docs')
                                ->url($result['link'], shouldOpenInNewTab: true),
                        ])
                        ->success()->send();
                });
            })
            ->visible(fn (Project $record) => filled($record->google_docs_id));
    }

    /**
     * Option 3: let the user pick a paragraph read live from the document.
     */
    private static function paragraphSelect(Project $record): Forms\Components\Select
    {
        return Forms\Components\Select::make('paragraph')
            ->label('Paragraf')
            ->options(function () use ($record) {
                try {
                    $paragraphs = (new GoogleDocsCommenter(auth()->user()))->paragraphs($record->google_docs_id);
                } catch (\Throwable $e) {
                    return ['' => 'Gagal membaca dokumen: '.Str::limit($e->getMessage(), 120)];
                }

                $options = [];
                $n = 0;
                foreach ($paragraphs as $start => $p) {
                    $options[$start] = '#'.(++$n).'  '.Str::limit(trim(str_replace("\u{FFFC}", '', $p['text'])), 90);
                }

                return $options;
            })
            ->searchable()
            ->required();
    }

    /**
     * Re-read the document at submit time: the paragraph is identified by its
     * start index, which shifts whenever someone edits text above it. Refuse
     * instead of writing to the wrong place.
     */
    private static function freshParagraph(GoogleDocsCommenter $commenter, Project $record, array $data): array
    {
        $paragraphs = $commenter->paragraphs($record->google_docs_id);
        $paragraph = $paragraphs[(int) $data['paragraph']] ?? null;

        if (! $paragraph) {
            throw new \RuntimeException('Dokumen berubah sejak form dibuka. Tutup dan buka lagi form ini.');
        }

        return $paragraph;
    }

    private static function run(callable $callback): void
    {
        try {
            $callback();
        } catch (\Google\Service\Exception $e) {
            $error = json_decode($e->getMessage(), true)['error']['message'] ?? $e->getMessage();
            Notification::make()->title('Error Google API')->body($error)->danger()->persistent()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}

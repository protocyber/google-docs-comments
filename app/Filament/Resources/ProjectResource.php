<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers;
use App\Models\Project;
use App\Services\DocumentTypeCheck;
use App\Support\GoogleDocsUrl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Google\Client;
use Google\Service\Docs;
use Google\Service\Docs\BatchUpdateDocumentRequest;
use Google\Service\Docs\Request;
use Google\Service\Drive;
use Google\Service\Drive\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required(),
                Forms\Components\Textarea::make('description'),
                Forms\Components\TextInput::make('google_docs_id')
                    ->label('URL Google Docs')
                    ->placeholder('https://docs.google.com/document/d/…/edit')
                    ->helperText('Tempel link dokumen dari address bar atau tombol Share. ID dokumen saja juga diterima.')
                    // Stored as the document ID; shown and edited as a URL.
                    ->formatStateUsing(fn (?string $state) => filled($state) ? GoogleDocsUrl::editUrl($state) : null)
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? GoogleDocsUrl::extractId($state) : null)
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if (blank($value)) {
                            return;
                        }
                        $id = GoogleDocsUrl::extractId($value);
                        if ($id === null) {
                            $fail('Bukan URL Google Docs yang valid. Contoh: https://docs.google.com/document/d/…/edit');

                            return;
                        }
                        // The URL looks right; make sure the file is a native Google Doc
                        // (an Office file opened in Docs has the same URL shape).
                        if ($problem = app(DocumentTypeCheck::class)->problem(auth()->user(), $id)) {
                            $fail($problem);
                        }
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('google_docs_id')
                    ->label('Dokumen')
                    ->formatStateUsing(fn () => 'Buka di Google Docs')
                    ->url(fn (Project $record) => filled($record->google_docs_id) ? GoogleDocsUrl::editUrl($record->google_docs_id) : null, shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('addComment')
                    ->label('Tambah Komentar Docs')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->color('primary')
                    ->form([
                        Forms\Components\TextInput::make('comment_text')
                            ->label('Komentar')
                            ->required(),
                    ])
                    ->action(function (Project $record, array $data)
                    {
                        if (empty($record->google_docs_id))
                        {
                            Notification::make()
                                ->title('Gagal')
                                ->body('Dokumen Google Docs ID belum diatur untuk proyek ini.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Pastikan ada user yang login
                        $user = auth()->user();
                        $googleAccessToken = $user->socialiteUser->google_access_token ?? null;
                        $googleRefreshToken = $user->socialiteUser->google_refresh_token ?? null;
                        if (!$user || !$googleAccessToken)
                        {
                            Notification::make()
                                ->title('Otentikasi Diperlukan')
                                ->body('Anda perlu login dengan akun Google yang memiliki akses ke dokumen ini.')
                                ->danger()
                                ->send();
                            return;
                        }

                        try
                        {
                            $client = new Client();
                            $client->setApplicationName('Nama Aplikasi Anda');
                            // Gunakan file kredensial untuk konfigurasi dasar
                            $client->setAuthConfig(storage_path('app/private/google-credentials.json'));
                            // Tambahkan scope yang diperlukan
                            $client->addScope(Docs::DOCUMENTS); // Atau Docs::DOCUMENTS_READONLY jika hanya membaca

                            // Set token akses pengguna yang sedang login
                            $client->setAccessToken($googleAccessToken);

                            // Periksa apakah token akses sudah kadaluarsa dan refresh jika perlu
                            if ($client->isAccessTokenExpired())
                            {
                                if ($googleRefreshToken)
                                {
                                    $client->fetchAccessTokenWithRefreshToken($googleRefreshToken);
                                    // Simpan token akses yang baru dan waktu kadaluarsanya
                                    $newAccessToken = $client->getAccessToken();
                                    $user->update([
                                        'google_access_token' => $newAccessToken['access_token'],
                                        'google_expires_at' => now()->addSeconds($newAccessToken['expires_in']),
                                    ]);
                                }
                                else
                                {
                                    // Tidak ada refresh token, user harus login ulang
                                    Notification::make()
                                        ->title('Token Kadaluarsa')
                                        ->body('Sesi Google Anda telah kadaluarsa. Mohon login ulang.')
                                        ->danger()
                                        ->send();
                                    return;
                                }
                            }

                            $service = new Docs($client);
                            $documentId = $record->google_docs_id;

                            // --- BAGIAN YANG DIPERBAIKI ---
                            $document = $service->documents->get($documentId);
                            $contentElements = $document->getBody()->getContent();

                            $endIndex = 1; // Default fallback for truly empty doc if content is not properly returned
                            if (!empty($contentElements))
                            {
                                $lastStructuralElement = end($contentElements);
                                $endIndex = $lastStructuralElement->getEndIndex();
                            }

                            // Inilah perbaikannya: kurangi 1 dari endIndex yang didapat.
                            // Ini akan menempatkan teks tepat sebelum karakter terakhir (seringkali newline implisit).
                            // Gunakan max(1, ...) untuk memastikan indeks tidak menjadi 0 atau negatif
                            // jika dokumen sangat singkat atau kosong.
                            $insertionIndex = max(1, $endIndex - 1);
                            // --- AKHIR BAGIAN YANG DIPERBAIKI ---

                            $textToInsert = "\n\n--- Komentar dari " . $user->name . " pada " . now()->format('Y-m-d H:i:s') . " ---\n"
                                . $data['comment_text'] . "\n\n";

                            $requests = [
                                new Request([
                                    'insertText' => [
                                        'location' => [
                                            'index' => $insertionIndex, // Gunakan indeks yang sudah disesuaikan
                                        ],
                                        'text' => $textToInsert
                                    ]
                                ])
                            ];

                            $batchUpdateRequest = new BatchUpdateDocumentRequest([
                                'requests' => $requests
                            ]);

                            $service->documents->batchUpdate($documentId, $batchUpdateRequest);

                            Notification::make()
                                ->title('Berhasil')
                                ->body('Teks berhasil ditambahkan ke Google Docs sebagai komentar.')
                                ->success()
                                ->send();
                        }
                        catch (\Google\Service\Exception $e)
                        {
                            Notification::make()
                                ->title('Error')
                                ->body('Gagal menambahkan komentar: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                        catch (\Exception $e)
                        {
                            Notification::make()
                                ->title('Error Umum')
                                ->body('Terjadi kesalahan: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn(Project $record) => !empty($record->google_docs_id)),
                Tables\Actions\Action::make('addCommentToDocsSidebar') // Ganti nama action
                    ->label('Tambah Komentar Docs (Sidebar)')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->color('primary')
                    ->form([
                        Forms\Components\TextInput::make('comment_text')
                            ->label('Isi Komentar')
                            ->required(),
                    ])
                    ->action(function (Project $record, array $data)
                    {
                        if (empty($record->google_docs_id))
                        {
                            Notification::make()
                                ->title('Gagal')
                                ->body('Dokumen Google Docs ID belum diatur untuk proyek ini.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Pastikan ada user yang login
                        $user = auth()->user();
                        $googleAccessToken = $user->socialiteUser->google_access_token ?? null;
                        $googleRefreshToken = $user->socialiteUser->google_refresh_token ?? null;
                        if (!$user || !$googleAccessToken)
                        {
                            Notification::make()
                                ->title('Otentikasi Diperlukan')
                                ->body('Anda perlu login dengan akun Google yang memiliki akses ke dokumen ini.')
                                ->danger()
                                ->send();
                            return;
                        }

                        try
                        {
                            $client = new Client();
                            $client->setApplicationName('Nama Aplikasi Anda');
                            $client->setAuthConfig(storage_path('app/private/google-credentials.json'));
                            // Tambahkan scope Drive API
                            $client->addScope(Drive::DRIVE); // Scope untuk akses penuh ke Drive

                            $client->setAccessToken($googleAccessToken);

                            if ($client->isAccessTokenExpired())
                            {
                                if ($googleRefreshToken)
                                {
                                    $client->fetchAccessTokenWithRefreshToken($googleRefreshToken);
                                    $newAccessToken = $client->getAccessToken();
                                    $user->update([
                                        'google_access_token' => $newAccessToken['access_token'],
                                        'google_expires_at' => now()->addSeconds($newAccessToken['expires_in']),
                                    ]);
                                }
                                else
                                {
                                    Notification::make()
                                        ->title('Token Kadaluarsa')
                                        ->body('Sesi Google Anda telah kadaluarsa. Mohon login ulang.')
                                        ->danger()
                                        ->send();
                                    return;
                                }
                            }

                            // --- MENGGUNAKAN GOOGLE DRIVE API UNTUK KOMENTAR ---
                            $driveService = new Drive($client);
                            $fileId = $record->google_docs_id;

                            $comment = new Comment();
                            // Isi konten komentar (ini akan menjadi body dari komentar di sidebar)
                            $comment->setContent($data['comment_text']);

                            // Perbaikan ada di baris ini: tambahkan array parameter 'fields'
                            $createdComment = $driveService->comments->create(
                                $fileId,
                                $comment,
                                ['fields' => 'id,content,author'] // Menentukan field yang ingin diterima kembali
                            );

                            Notification::make()
                                ->title('Berhasil')
                                ->body('Komentar berhasil ditambahkan ke Google Docs sidebar.')
                                ->success()
                                ->send();
                        }
                        catch (\Google\Service\Exception $e)
                        {
                            Notification::make()
                                ->title('Error API Google')
                                ->body('Gagal menambahkan komentar: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                        catch (\Exception $e)
                        {
                            Notification::make()
                                ->title('Error Umum')
                                ->body('Terjadi kesalahan yang tidak terduga: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn(Project $record) => !empty($record->google_docs_id)),
                ProjectResource\Actions\PositionedCommentActions::insertText(),
                ProjectResource\Actions\PositionedCommentActions::commentOnText(),
                Tables\Actions\Action::make('commentFromPreview')
                    ->label('Komentar dari Preview')
                    ->icon('heroicon-o-cursor-arrow-rays')
                    ->url(fn (Project $record) => ProjectResource::getUrl('preview-comment', ['record' => $record]))
                    ->visible(fn (Project $record) => filled($record->google_docs_id)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
            'preview-comment' => Pages\CommentFromPreview::route('/{record}/preview-comment'),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\SocialiteUser;
use App\Models\User;
use App\Support\GoogleDocsFile;
use Google\Client;
use Google\Service\Docs;
use Google\Service\Docs\BatchUpdateDocumentRequest;
use Google\Service\Docs\Document;
use Google\Service\Docs\Request as DocsRequest;
use Google\Service\Drive;
use Google\Service\Exception as GoogleServiceException;
use RuntimeException;

/**
 * Positioned comments on Google Docs (spike).
 *
 * Comments are created with the Docs API `insertComment` request, which
 * anchors them natively to a text range (see commentOnRange). The positions
 * used here are Docs API indexes.
 *
 * All positions are Docs API indexes, which count UTF-16 code units.
 */
class GoogleDocsCommenter
{
    private Client $client;

    public function __construct(private User $user)
    {
        $this->client = $this->makeClient();
    }

    /**
     * Top-level body paragraphs that contain text.
     *
     * @return array<int, array{start: int, end: int, text: string}> keyed by paragraph start index
     */
    public function paragraphs(string $documentId): array
    {
        return self::paragraphsOf($this->document($documentId));
    }

    /**
     * The document, trimmed to what the preview and the comment code read.
     * Refuses anything that is not a native Google Doc before fetching it.
     *
     * @throws UnsupportedDocument
     */
    public function document(string $documentId): Document
    {
        $this->assertCommentable($documentId);

        try {
            return (new Docs($this->client))->documents->get($documentId, ['fields' => GoogleDocsFile::PREVIEW_FIELDS]);
        } catch (GoogleServiceException $e) {
            throw $e->getCode() === 404 ? UnsupportedDocument::notFound() : $e;
        }
    }

    /**
     * Drive MIME type of the file, which tells a native Google Doc apart from
     * an Office file opened in Docs (both live under docs.google.com/document).
     *
     * @throws UnsupportedDocument when the file is missing or not accessible
     */
    public function mimeType(string $fileId): string
    {
        try {
            return (string) (new Drive($this->client))->files
                ->get($fileId, ['fields' => 'mimeType', 'supportsAllDrives' => true])
                ->getMimeType();
        } catch (GoogleServiceException $e) {
            throw in_array($e->getCode(), [403, 404], true) ? UnsupportedDocument::notFound() : $e;
        }
    }

    /**
     * @throws UnsupportedDocument
     */
    public function assertCommentable(string $fileId): void
    {
        GoogleDocsFile::assertSupported($this->mimeType($fileId));
    }

    /**
     * Drive file metadata plus what the current user may do with it.
     *
     * @return array{mimeType: string, canEdit: bool, canComment: bool}
     *
     * @throws UnsupportedDocument when the file is missing or not accessible
     */
    public function fileInfo(string $fileId): array
    {
        $response = $this->http()->request(
            'GET',
            'https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId),
            ['query' => ['fields' => 'mimeType,capabilities(canEdit,canComment)', 'supportsAllDrives' => 'true'], 'http_errors' => false],
        );

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true) ?? [];

        if (in_array($status, [403, 404], true)) {
            throw UnsupportedDocument::notFound();
        }
        if ($status >= 400) {
            throw new RuntimeException('Google Drive API: '.($body['error']['message'] ?? 'HTTP '.$status));
        }

        return [
            'mimeType' => (string) ($body['mimeType'] ?? ''),
            'canEdit' => (bool) ($body['capabilities']['canEdit'] ?? false),
            'canComment' => (bool) ($body['capabilities']['canComment'] ?? false),
        ];
    }

    /**
     * Plain-text edit: `batchUpdate` with the given requests, guarded by
     * `writeControl.requiredRevisionId` so Google refuses the write (rather
     * than silently merging) when the document changed since the caller
     * last fetched it.
     *
     * @param  list<array>  $requests  EditRequest::build() output
     * @return string new revisionId
     *
     * @throws DocumentChanged when $revisionId is no longer the latest
     * @throws RuntimeException on any other API error
     */
    public function applyEdit(string $documentId, string $revisionId, array $requests): string
    {
        $response = $this->http()->request(
            'POST',
            'https://docs.googleapis.com/v1/documents/'.rawurlencode($documentId).':batchUpdate',
            [
                'json' => ['requests' => $requests, 'writeControl' => ['requiredRevisionId' => $revisionId]],
                'http_errors' => false,
            ],
        );

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if ($response->getStatusCode() >= 400) {
            $error = $body['error'] ?? [];
            if (($error['status'] ?? null) === 'INVALID_ARGUMENT'
                && str_contains((string) ($error['message'] ?? ''), 'does not match the latest revision')) {
                throw new DocumentChanged((string) $error['message']);
            }

            throw new RuntimeException('Google Docs API: '.($error['message'] ?? 'HTTP '.$response->getStatusCode()));
        }

        return (string) ($body['writeControl']['requiredRevisionId'] ?? '');
    }

    /**
     * Marks a Drive comment resolved, optionally with a reply (Task 1: an
     * empty reply sends only {action: resolve}).
     */
    public function resolveComment(string $documentId, string $commentId, string $reply): void
    {
        $json = ['action' => 'resolve'];
        if (trim($reply) !== '') {
            $json['content'] = $reply;
        }

        $response = $this->http()->request(
            'POST',
            'https://www.googleapis.com/drive/v3/files/'.rawurlencode($documentId).'/comments/'.rawurlencode($commentId).'/replies',
            ['json' => $json, 'http_errors' => false],
        );

        $body = json_decode((string) $response->getBody(), true) ?? [];
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Google Drive API: '.($body['error']['message'] ?? 'HTTP '.$response->getStatusCode()));
        }
    }

    /**
     * Authorized Guzzle client, for Docs API calls the installed PHP client
     * cannot model (comments, comment anchors, insertComment).
     */
    public function http(): \GuzzleHttp\ClientInterface
    {
        return $this->client->authorize();
    }

    /**
     * @return array<int, array{start: int, end: int, text: string}>
     */
    public static function paragraphsOf(Document $document): array
    {
        $paragraphs = [];

        foreach ($document->getBody()->getContent() ?? [] as $element) {
            $paragraph = $element->getParagraph();
            if (! $paragraph) {
                continue; // tables, section breaks, TOC: out of scope for the spike
            }

            // Elements of a paragraph are contiguous. Non-text elements (inline
            // images, auto text) are padded with U+FFFC, one per index, so that
            // a UTF-16 offset in $text maps 1:1 onto a document index.
            $text = '';
            foreach ($paragraph->getElements() ?? [] as $part) {
                $run = $part->getTextRun();
                $text .= $run
                    ? $run->getContent()
                    : str_repeat("\u{FFFC}", $part->getEndIndex() - $part->getStartIndex());
            }

            if (trim(str_replace("\u{FFFC}", '', $text)) === '') {
                continue;
            }

            $paragraphs[$element->getStartIndex()] = [
                'start' => $element->getStartIndex(),
                'end' => $element->getEndIndex(),
                'text' => $text,
            ];
        }

        return $paragraphs;
    }

    /**
     * Document range of $quote inside a paragraph (first occurrence), or of
     * the whole paragraph without its trailing newline when $quote is empty.
     *
     * @param  array{start: int, end: int, text: string}  $paragraph
     * @return array{start: int, end: int, quote: string}
     */
    public static function locate(array $paragraph, ?string $quote): array
    {
        $text = $paragraph['text'];

        if ($quote === null || trim($quote) === '') {
            $quote = rtrim($text, "\n");

            return [
                'start' => $paragraph['start'],
                'end' => $paragraph['start'] + self::utf16Length($quote),
                'quote' => $quote,
            ];
        }

        $offset = mb_strpos($text, $quote);
        if ($offset === false) {
            throw new RuntimeException('Teks yang dipilih tidak ditemukan di paragraf tersebut (pencarian peka huruf besar/kecil).');
        }

        $start = $paragraph['start'] + self::utf16Length(mb_substr($text, 0, $offset));

        return [
            'start' => $start,
            'end' => $start + self::utf16Length($quote),
            'quote' => $quote,
        ];
    }

    /**
     * Option 1: insert a new paragraph before or after the chosen paragraph.
     *
     * @param  array{start: int, end: int, text: string}  $paragraph
     */
    public function insertText(string $documentId, array $paragraph, string $text, string $placement): void
    {
        [$index, $payload] = $placement === 'before'
            ? [$paragraph['start'], $text."\n"]
            // end - 1 is the paragraph's own newline; inserting "\n<text>" there
            // ends the paragraph and starts a new one that inherits its style.
            : [$paragraph['end'] - 1, "\n".$text];

        $this->batchUpdate($documentId, [
            new DocsRequest(['insertText' => ['location' => ['index' => $index], 'text' => $payload]]),
        ]);
    }

    /**
     * Option 2: native anchored comment on [start, end).
     *
     * Uses the Docs API `insertComment` request (public preview since 2026),
     * which creates a real anchored thread: the text is marked in the editor
     * and the sidebar card jumps to it. Drive API `comments.create` cannot do
     * this; its comments show "Original content deleted" in Docs.
     *
     * The Docs API PHP client in use predates this request, so it is sent as
     * raw JSON through the authorized HTTP client.
     *
     * @param  array{start: int, end: int, quote: string}  $range
     * @return array{comment_id: string, anchor: string, quote: string, link: string}
     */
    public function commentOnRange(string $documentId, array $range, string $content): array
    {
        $response = $this->http()->request(
            'POST',
            'https://docs.googleapis.com/v1/documents/'.rawurlencode($documentId).':batchUpdate',
            ['json' => self::insertCommentPayload($range, $content), 'http_errors' => false],
        );

        $body = json_decode((string) $response->getBody(), true) ?? [];
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Google Docs API: '.($body['error']['message'] ?? 'HTTP '.$response->getStatusCode()));
        }

        $comment = self::parseInsertCommentResponse($body);

        return $comment + ['link' => "https://docs.google.com/document/d/{$documentId}/edit?disco={$comment['comment_id']}"];
    }

    /**
     * @param  array{start: int, end: int}  $range
     */
    public static function insertCommentPayload(array $range, string $content): array
    {
        return ['requests' => [['insertComment' => [
            'content' => $content,
            'range' => ['startIndex' => $range['start'], 'endIndex' => $range['end']],
        ]]]];
    }

    /**
     * @return array{comment_id: string, anchor: string, quote: string}
     */
    public static function parseInsertCommentResponse(array $body): array
    {
        $state = $body['commentUpdateState'] ?? null;
        $thread = $body['replies'][0]['insertComment']['commentThread'] ?? null;

        // The batch can commit while the comment thread itself fails to save.
        if ($state !== 'ALL_SAVED' || ! isset($thread['commentId'])) {
            throw new RuntimeException('Komentar tidak tersimpan di Google Docs ('.($state ?? 'tanpa status').'). Coba lagi.');
        }

        return [
            'comment_id' => $thread['commentId'],
            'anchor' => $thread['anchorId'] ?? '',
            'quote' => $thread['plainTextQuote'] ?? '',
        ];
    }

    public static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * @param  list<DocsRequest>  $requests
     */
    private function batchUpdate(string $documentId, array $requests): void
    {
        (new Docs($this->client))->documents->batchUpdate(
            $documentId,
            new BatchUpdateDocumentRequest(['requests' => $requests]),
        );
    }

    private function makeClient(): Client
    {
        /** @var SocialiteUser|null $account */
        $account = $this->user->socialiteUser;
        if (! $account || ! $account->google_access_token) {
            throw new RuntimeException('Login dengan Google terlebih dahulu.');
        }

        $client = new Client;
        $client->setApplicationName(config('app.name'));
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));

        $expiresAt = $account->google_expires_at ? \Illuminate\Support\Carbon::parse($account->google_expires_at) : now();
        $client->setAccessToken([
            'access_token' => $account->google_access_token,
            'refresh_token' => $account->google_refresh_token,
            'created' => time(),
            'expires_in' => max(0, now()->diffInSeconds($expiresAt, false)),
        ]);

        if ($client->isAccessTokenExpired()) {
            if (! $account->google_refresh_token) {
                throw new RuntimeException('Sesi Google kedaluwarsa. Silakan login ulang dengan Google.');
            }

            $token = $client->fetchAccessTokenWithRefreshToken($account->google_refresh_token);
            if (isset($token['error'])) {
                throw new RuntimeException('Gagal memperbarui token Google ('.$token['error'].'). Silakan login ulang.');
            }

            $account->update([
                'google_access_token' => $token['access_token'],
                'google_expires_at' => now()->addSeconds($token['expires_in']),
            ]);
        }

        return $client;
    }
}

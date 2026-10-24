<?php

namespace App\Services\Preview;

use App\Models\User;
use App\Services\GoogleDocsCommenter;
use App\Services\UnsupportedDocument;
use App\Support\GoogleDocsFile;
use RuntimeException;

/**
 * Raw documents.get for the preview. The installed PHP client has no model
 * for comments or comment anchors, so the JSON is fetched through the
 * authorized Guzzle client and kept as arrays.
 */
class DocumentFetcher
{
    private const TEXT_STYLE = 'textStyle(bold,italic,underline,strikethrough,smallCaps,backgroundColor,foregroundColor,fontSize,weightedFontFamily,baselineOffset,link)';

    private const PARAGRAPH_STYLE = 'paragraphStyle(namedStyleType,alignment,lineSpacing,direction,spaceAbove,spaceBelow,indentFirstLine,indentStart,indentEnd,shading,borderTop,borderBottom,borderLeft,borderRight)';

    private const PARAGRAPH = 'paragraph(elements(startIndex,endIndex,textRun(content,suggestedInsertionIds,suggestedDeletionIds,'.self::TEXT_STYLE.'),'
        .'inlineObjectElement(inlineObjectId),person(personProperties(name)),richLink(richLinkProperties(title)),'
        .'pageBreak,horizontalRule,footnoteReference(footnoteNumber),equation),'
        .self::PARAGRAPH_STYLE.',bullet(listId,nestingLevel,'.self::TEXT_STYLE.'),positionedObjectIds)';

    private const CELL = 'tableCells(tableCellStyle,content(';

    private const TABLE_TAIL = '))),tableStyle(tableColumnProperties))';

    private const CONTENT_L3 = 'content(startIndex,endIndex,'.self::PARAGRAPH.')';

    private const CONTENT_L2 = 'content(startIndex,endIndex,'.self::PARAGRAPH.',table(tableRows('.self::CELL.'startIndex,endIndex,'.self::PARAGRAPH.self::TABLE_TAIL.')';

    private const CONTENT_L1 = 'content(startIndex,endIndex,'.self::PARAGRAPH
        .',table(tableRows('.self::CELL.'startIndex,endIndex,'.self::PARAGRAPH.',table(tableRows('.self::CELL.'startIndex,endIndex,'.self::PARAGRAPH.self::TABLE_TAIL.self::TABLE_TAIL
        .',tableOfContents('.self::CONTENT_L3.'))';

    private const DOCUMENT_TAB = 'body('.self::CONTENT_L1.'),documentStyle(pageSize,marginTop,marginBottom,marginLeft,marginRight,background),'
        .'namedStyles(styles(namedStyleType,'.self::TEXT_STYLE.','.self::PARAGRAPH_STYLE.')),lists,inlineObjects,positionedObjects';

    private const COMMENTS = 'comments(commentId,anchorId,status,plainTextQuote,'
        .'headPost(content,deleted,createTime,author(displayName,anonymous)),'
        .'replies(content,deleted,createTime,author(displayName,anonymous)))';

    public function fetch(User $user, string $documentId): FetchedDocument
    {
        $commenter = app(GoogleDocsCommenter::class, ['user' => $user]);
        $info = $commenter->fileInfo($documentId);
        GoogleDocsFile::assertSupported($info['mimeType']);
        $capabilities = ['canEdit' => $info['canEdit'], 'canComment' => $info['canComment']];

        $body = $this->get($commenter, $documentId, true);
        if ($body !== null) {
            return FetchedDocument::fromApi($body, true, capabilities: $capabilities);
        }

        $body = $this->get($commenter, $documentId, false)
            ?? throw UnsupportedDocument::notFound();

        return FetchedDocument::fromApi($body, false, 'Komentar tidak bisa dimuat: tidak ada akses.', $capabilities);
    }

    public static function query(bool $withComments): array
    {
        $q = ['includeTabsContent' => 'true', 'fields' => self::fields($withComments)];
        if ($withComments) {
            $q['suggestionsViewMode'] = 'SUGGESTIONS_INLINE';
            $q['commentsViewMode'] = 'COMMENTS_VIEW_MODE_INCLUDED';
        }

        return $q;
    }

    public static function fields(bool $withComments): string
    {
        $anchors = $withComments ? ',commentAnchors' : '';
        $tabs = 'tabs(tabProperties(tabId,title),documentTab('.self::DOCUMENT_TAB.$anchors.'),'
            .'childTabs(tabProperties(tabId)'.($withComments ? ',documentTab(commentAnchors)' : '').'))';

        return 'title,revisionId,'.$tabs.($withComments ? ','.self::COMMENTS : '');
    }

    /** Decoded body, or null when Google refuses with 403 (no comment access). */
    private function get(GoogleDocsCommenter $commenter, string $documentId, bool $withComments): ?array
    {
        $response = $commenter->http()->request(
            'GET',
            'https://docs.googleapis.com/v1/documents/'.rawurlencode($documentId),
            ['query' => self::query($withComments), 'http_errors' => false],
        );
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true) ?? [];

        return match (true) {
            $status === 403 => null,
            $status === 404 => throw UnsupportedDocument::notFound(),
            $status >= 400 => throw new RuntimeException('Google Docs API: '.($body['error']['message'] ?? 'HTTP '.$status)),
            default => $body,
        };
    }
}

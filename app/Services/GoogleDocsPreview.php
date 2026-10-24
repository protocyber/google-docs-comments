<?php

namespace App\Services;

use App\Services\Preview\CommentIndex;
use App\Services\Preview\FetchedDocument;
use App\Services\Preview\HtmlRenderer;
use App\Services\Preview\PreviewResult;
use App\Services\Preview\TextIndex;

/**
 * Facade: build the preview of a fetched Docs tab.
 */
class GoogleDocsPreview
{
    public static function build(FetchedDocument $doc): PreviewResult
    {
        $comments = new CommentIndex(
            $doc->comments,
            $doc->tab['commentAnchors'] ?? [],
            $doc->tabId,
            $doc->otherTabAnchorIds,
            TextIndex::endIndex($doc->content()),
        );
        $renderer = new HtmlRenderer($doc->tab, $comments);
        $html = $renderer->render();

        return new PreviewResult(
            html: $html,
            fonts: $renderer->fonts(),
            threads: $comments->threads(),
            title: $doc->title,
            revisionId: $doc->revisionId,
            tabCount: $doc->tabCount,
            commentsError: $doc->commentsError,
            canEdit: $doc->canEdit,
            canComment: $doc->canComment,
        );
    }
}

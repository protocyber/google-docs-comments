<?php

namespace App\Services\Preview;

/**
 * documents.get response reduced to what the preview needs: the first tab,
 * the document-level comments, and anchor ids that live in other tabs.
 */
final class FetchedDocument
{
    /**
     * @param  array  $tab  documentTab of the first tab
     * @param  array|null  $comments  null when comments were not requested
     * @param  list<string>  $otherTabAnchorIds
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $revisionId,
        public readonly string $tabId,
        public readonly array $tab,
        public readonly int $tabCount,
        public readonly ?array $comments,
        public readonly array $otherTabAnchorIds,
        public readonly ?string $commentsError = null,
    ) {}

    public static function fromApi(array $body, bool $withComments, ?string $commentsError = null): self
    {
        $tabs = $body['tabs'] ?? [];
        $first = $tabs[0] ?? [];
        $other = [];
        foreach ($first['childTabs'] ?? [] as $child) {
            $other = [...$other, ...self::anchorIds($child)];
        }
        foreach (array_slice($tabs, 1) as $tab) {
            $other = [...$other, ...self::anchorIds($tab)];
        }

        return new self(
            title: (string) ($body['title'] ?? ''),
            revisionId: $body['revisionId'] ?? null,
            tabId: (string) ($first['tabProperties']['tabId'] ?? ''),
            tab: $first['documentTab'] ?? [],
            tabCount: array_sum(array_map(self::countTabs(...), $tabs)),
            comments: $withComments ? ($body['comments'] ?? []) : null,
            otherTabAnchorIds: array_values(array_unique($other)),
            commentsError: $commentsError,
        );
    }

    public function content(): array
    {
        return $this->tab['body']['content'] ?? [];
    }

    private static function countTabs(array $tab): int
    {
        return 1 + array_sum(array_map(self::countTabs(...), $tab['childTabs'] ?? []));
    }

    /** @return list<string> */
    private static function anchorIds(array $tab): array
    {
        $ids = array_map('strval', array_keys($tab['documentTab']['commentAnchors'] ?? []));
        foreach ($tab['childTabs'] ?? [] as $child) {
            $ids = [...$ids, ...self::anchorIds($child)];
        }

        return $ids;
    }
}

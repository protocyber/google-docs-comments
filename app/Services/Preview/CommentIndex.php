<?php

namespace App\Services\Preview;

/**
 * Comment threads of the document joined with their anchors in the rendered
 * tab. A thread is navigable ("here") only when every one of its anchor
 * ranges is a valid body range of this tab; one bad comment never breaks
 * rendering, it is just listed without a location.
 */
final class CommentIndex
{
    /** @var list<array> */
    private array $threads = [];

    /**
     * @param  array|null  $comments  Document.comments (null = not fetched)
     * @param  array  $anchors  documentTab.commentAnchors keyed by anchor id
     * @param  list<string>  $otherTabAnchorIds
     */
    public function __construct(?array $comments, array $anchors, string $tabId, array $otherTabAnchorIds, int $bodyEnd)
    {
        foreach ($comments ?? [] as $c) {
            $head = $c['headPost'] ?? [];
            if (! empty($head['deleted']) || ! isset($c['commentId'])) {
                continue;
            }
            [$location, $ranges] = self::locate($c['anchorId'] ?? null, $anchors, $tabId, $otherTabAnchorIds, $bodyEnd);
            $this->threads[] = [
                'id' => (string) $c['commentId'],
                'status' => ($c['status'] ?? 'OPEN') === 'RESOLVED' ? 'resolved' : 'open',
                'location' => $location,
                'start' => $ranges ? $ranges[0][0] : null,
                'end' => $ranges ? max(array_column($ranges, 1)) : null,
                'ranges' => $ranges,
                'quote' => (string) ($c['plainTextQuote'] ?? ''),
            ] + self::post($head) + [
                'replies' => array_values(array_map(self::post(...), array_filter(
                    $c['replies'] ?? [],
                    fn ($r) => empty($r['deleted']) && trim((string) ($r['content'] ?? '')) !== '',
                ))),
            ];
        }

        usort($this->threads, function (array $a, array $b): int {
            $ah = $a['location'] === 'here';
            $bh = $b['location'] === 'here';
            if ($ah !== $bh) {
                return $ah ? -1 : 1;
            }
            if ($ah) {
                return [$a['start'], $a['end'] - $a['start']] <=> [$b['start'], $b['end'] - $b['start']];
            }

            return (string) $a['created'] <=> (string) $b['created'];
        });
    }

    /** @return list<array> */
    public function threads(): array
    {
        return $this->threads;
    }

    /** Sorted unique cut points of every located range (open and resolved). */
    public function cuts(): array
    {
        $cuts = [];
        foreach ($this->located() as $t) {
            foreach ($t['ranges'] as [$s, $e]) {
                $cuts[$s] = true;
                $cuts[$e] = true;
            }
        }
        $cuts = array_keys($cuts);
        sort($cuts);

        return $cuts;
    }

    /** @return list<string> */
    public function openIdsAt(int $index): array
    {
        return $this->idsAt($index, 'open');
    }

    /** @return list<string> */
    public function resolvedIdsAt(int $index): array
    {
        return $this->idsAt($index, 'resolved');
    }

    private function idsAt(int $index, string $status): array
    {
        $ids = [];
        foreach ($this->located() as $t) {
            if ($t['status'] !== $status) {
                continue;
            }
            foreach ($t['ranges'] as [$s, $e]) {
                if ($index >= $s && $index < $e) {
                    $ids[] = $t['id'];
                    break;
                }
            }
        }

        return $ids;
    }

    private function located(): array
    {
        return array_filter($this->threads, fn ($t) => $t['location'] === 'here');
    }

    /** @return array{string, list<array{int,int}>} */
    private static function locate(?string $anchorId, array $anchors, string $tabId, array $otherTabAnchorIds, int $bodyEnd): array
    {
        if ($anchorId === null || $anchorId === '') {
            return ['deleted', []];
        }
        if (! isset($anchors[$anchorId])) {
            return [in_array($anchorId, $otherTabAnchorIds, true) ? 'other_tab' : 'unknown', []];
        }
        $raw = $anchors[$anchorId]['ranges'] ?? [];
        if ($raw === []) {
            return ['deleted', []];
        }
        $ranges = [];
        foreach ($raw as $r) {
            $s = (int) ($r['startIndex'] ?? 0); // proto3 omits 0
            $e = $r['endIndex'] ?? null;
            $valid = ($r['segmentId'] ?? '') === ''
                && (! isset($r['tabId']) || $r['tabId'] === $tabId)
                && is_int($e) && $s >= 0 && $e > $s && $e <= $bodyEnd;
            if (! $valid) {
                return ['unknown', []];
            }
            $ranges[] = [$s, $e];
        }
        usort($ranges, fn ($a, $b) => $a <=> $b);

        return ['here', $ranges];
    }

    /** @return array{author: string, created: ?string, content: string} */
    private static function post(array $post): array
    {
        $author = $post['author'] ?? [];

        return [
            'author' => ! empty($author['anonymous']) ? 'Anonim' : ((string) ($author['displayName'] ?? '') ?: 'Tanpa nama'),
            'created' => $post['createTime'] ?? null,
            'content' => (string) ($post['content'] ?? ''),
        ];
    }
}

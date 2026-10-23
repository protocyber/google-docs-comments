<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\CommentIndex;
use App\Services\Preview\TextIndex;
use PHPUnit\Framework\TestCase;
use Tests\Support\DocFixture;

class CommentIndexTest extends TestCase
{
    private function index(DocFixture $f): CommentIndex
    {
        $body = $f->api(true);
        $tab = $body['tabs'][0]['documentTab'] ?? [];
        $otherTabAnchorIds = [];
        foreach (array_slice($body['tabs'] ?? [], 1) as $t) {
            foreach (array_keys($t['documentTab']['commentAnchors'] ?? []) as $aId) {
                $otherTabAnchorIds[] = (string) $aId;
            }
        }

        return new CommentIndex(
            $body['comments'] ?? [],
            $tab['commentAnchors'] ?? [],
            $body['tabs'][0]['tabProperties']['tabId'] ?? 't.0',
            $otherTabAnchorIds,
            TextIndex::endIndex($f->content()),
        );
    }

    public function test_order_and_locations(): void
    {
        $threads = $this->index(DocFixture::rich())->threads();
        $this->assertSame(['c2', 'c3', 'c4', 'c1', 'c5', 'c6', 'c7'], array_column($threads, 'id'));
        $this->assertSame(['here', 'here', 'here', 'here', 'other_tab', 'deleted', 'unknown'], array_column($threads, 'location'));
        $this->assertSame('resolved', $threads[2]['status']);
        $this->assertNull($threads[4]['start']);
    }

    public function test_thread_fields_replies_and_no_email(): void
    {
        $f = DocFixture::rich();
        $c1 = collect($this->index($f)->threads())->firstWhere('id', 'c1');
        $this->assertSame($f->pos('Kalimat akhir untuk komentar jauh.'), [$c1['start'], $c1['end']]);
        $this->assertSame([[$c1['start'], $c1['end']]], $c1['ranges']);
        $this->assertSame('Komentar jauh', $c1['content']);
        $this->assertSame('Penguji', $c1['author']);
        $this->assertSame('2026-10-01T09:00:00Z', $c1['created']);
        $this->assertSame(['Balasan 1', 'Balasan 2', 'Balasan 3', 'Balasan 4'], array_column($c1['replies'], 'content'));
        $this->assertStringNotContainsString('@', json_encode($c1));
    }

    public function test_ids_at_and_cuts(): void
    {
        $f = DocFixture::rich();
        $idx = $this->index($f);
        [$tS] = $f->pos('Temuan');
        [$pS, $pE] = $f->pos('penting');
        [$kS] = $f->pos('Kas');
        [$c3S, $c3E] = $f->pos('penting ditemukan');

        $this->assertSame(['c2'], $idx->openIdsAt($tS));
        $this->assertSame(['c2', 'c3'], $idx->openIdsAt($pS));
        $this->assertSame(['c3'], $idx->openIdsAt($pE));
        $this->assertSame([], $idx->openIdsAt($c3E));
        $this->assertSame([], $idx->openIdsAt($kS));
        $this->assertSame(['c4'], $idx->resolvedIdsAt($kS));

        $cuts = $idx->cuts();
        $this->assertSame($cuts, array_values(array_unique($cuts)));
        $sorted = $cuts;
        sort($sorted);
        $this->assertSame($sorted, $cuts);
        foreach ([$tS, $pS, $pE, $c3S, $c3E] as $p) {
            $this->assertContains($p, $cuts);
        }
    }

    public function test_malformed_ranges_are_unknown_and_empty_ranges_deleted(): void
    {
        $anchors = [
            'kix.seg' => ['anchorId' => 'kix.seg', 'ranges' => [['segmentId' => 'kix.header', 'startIndex' => 1, 'endIndex' => 3]]],
            'kix.bad' => ['anchorId' => 'kix.bad', 'ranges' => [['startIndex' => 9, 'endIndex' => 4], ['startIndex' => 1, 'endIndex' => 9999]]],
            'kix.tab' => ['anchorId' => 'kix.tab', 'ranges' => [['startIndex' => 1, 'endIndex' => 3, 'tabId' => 't.9']]],
            'kix.gone' => ['anchorId' => 'kix.gone', 'ranges' => []],
            'kix.zero' => ['anchorId' => 'kix.zero', 'ranges' => [['endIndex' => 3]]],
        ];
        $post = ['content' => 'x', 'author' => ['displayName' => 'A']];
        $comments = array_map(fn ($id) => ['commentId' => $id, 'anchorId' => "kix.$id", 'status' => 'OPEN', 'headPost' => $post],
            ['seg', 'bad', 'tab', 'gone', 'zero']);
        $threads = collect((new CommentIndex($comments, $anchors, 't.0', [], 100))->threads())->keyBy('id');

        $this->assertSame('unknown', $threads['seg']['location']);
        $this->assertSame('unknown', $threads['bad']['location']);
        $this->assertSame('unknown', $threads['tab']['location']);
        $this->assertSame('deleted', $threads['gone']['location']);
        $this->assertSame('here', $threads['zero']['location']);
        $this->assertSame(0, $threads['zero']['start']);
    }

    public function test_authors_deleted_heads_and_null_comments(): void
    {
        $comments = [
            ['commentId' => 'a', 'status' => 'OPEN', 'headPost' => ['content' => 'x', 'author' => ['anonymous' => true]]],
            ['commentId' => 'b', 'status' => 'OPEN', 'headPost' => ['content' => 'x']],
            ['commentId' => 'c', 'status' => 'OPEN', 'headPost' => ['deleted' => true]],
        ];
        $threads = (new CommentIndex($comments, [], 't.0', [], 10))->threads();
        $this->assertSame(['a', 'b'], array_column($threads, 'id'));
        $this->assertSame(['Anonim', 'Tanpa nama'], array_column($threads, 'author'));
        $this->assertSame([], (new CommentIndex(null, [], 't.0', [], 10))->threads());
        $this->assertSame([], (new CommentIndex(null, [], 't.0', [], 10))->cuts());
    }
}

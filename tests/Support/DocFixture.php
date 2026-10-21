<?php

namespace Tests\Support;

use App\Services\Preview\FetchedDocument;
use App\Services\Preview\TextIndex;

/**
 * Builds Docs API JSON (arrays) with indexes computed the way Google does:
 * UTF-16 units; atoms, table start/end and each cell start take one index.
 */
final class DocFixture
{
    private int $i = 1;

    private array $content = [['startIndex' => 0, 'endIndex' => 1, 'sectionBreak' => ['sectionStyle' => []]]];

    /** @var list<array{int, string}> paragraph start => flat text */
    private array $flat = [];

    private array $comments = [];

    private array $anchors = [];

    private array $extraTabs = [];

    private array $documentTab = [];

    /**
     * $parts: string = text run; ['text' => s, 'style' => [...], 'ins' => bool, 'del' => bool] = styled run;
     * any other array = non-text element (one index), e.g. ['inlineObjectElement' => ['inlineObjectId' => 'img1']].
     */
    public function para(array $parts, array $paragraph = []): self
    {
        $this->content[] = $this->buildPara($parts, $paragraph);

        return $this;
    }

    private function buildPara(array $parts, array $paragraph): array
    {
        $start = $this->i;
        $elements = [];
        $flat = '';
        foreach ($parts as $part) {
            $s = $this->i;
            if (is_string($part) || isset($part['text'])) {
                $text = is_string($part) ? $part : $part['text'];
                $this->i += TextIndex::utf16Length($text);
                $run = ['content' => $text];
                if (! is_string($part) && isset($part['style'])) {
                    $run['textStyle'] = $part['style'];
                }
                if (! is_string($part) && ! empty($part['ins'])) {
                    $run['suggestedInsertionIds'] = ['suggest.ins'];
                }
                if (! is_string($part) && ! empty($part['del'])) {
                    $run['suggestedDeletionIds'] = ['suggest.del'];
                }
                $elements[] = ['startIndex' => $s, 'endIndex' => $this->i, 'textRun' => $run];
                $flat .= $text;
            } else {
                $this->i += 1;
                $elements[] = ['startIndex' => $s, 'endIndex' => $this->i] + $part;
                $flat .= "\u{FFFC}";
            }
        }
        $this->flat[] = [$start, $flat];

        return ['startIndex' => $start, 'endIndex' => $this->i, 'paragraph' => ['elements' => $elements] + $paragraph];
    }

    /**
     * $rows: list of rows, each a list of cell texts (newline added).
     * $cellStyles: "r,c" => tableCellStyle.
     */
    public function table(array $rows, array $tableExtra = [], array $cellStyles = []): self
    {
        $tableStart = $this->i++;
        $tableRows = [];
        foreach ($rows as $r => $cells) {
            $rowStart = $this->i;
            $row = [];
            foreach ($cells as $c => $text) {
                $cellStart = $this->i++;
                $p = $this->buildPara([$text."\n"], []);
                $cell = ['startIndex' => $cellStart, 'endIndex' => $this->i, 'content' => [$p]];
                if (isset($cellStyles["$r,$c"])) {
                    $cell['tableCellStyle'] = $cellStyles["$r,$c"];
                }
                $row[] = $cell;
            }
            $tableRows[] = ['startIndex' => $rowStart, 'endIndex' => $this->i, 'tableCells' => $row];
        }
        $this->i++;
        $this->content[] = ['startIndex' => $tableStart, 'endIndex' => $this->i, 'table' => [
            'rows' => count($rows), 'columns' => count($rows[0] ?? []), 'tableRows' => $tableRows,
        ] + $tableExtra];

        return $this;
    }

    /** Table of contents: one index before and after, like a table. Verify against probe JSON. */
    public function toc(array $lines): self
    {
        $start = $this->i++;
        $paras = array_map(fn ($l) => $this->buildPara([$l."\n"], []), $lines);
        $this->i++;
        $this->content[] = ['startIndex' => $start, 'endIndex' => $this->i, 'tableOfContents' => ['content' => $paras]];

        return $this;
    }

    /** [start, end) of the $nth occurrence of $needle inside one paragraph. */
    public function pos(string $needle, int $nth = 1): array
    {
        foreach ($this->flat as [$start, $text]) {
            $offset = 0;
            while (($at = mb_strpos($text, $needle, $offset)) !== false) {
                if (--$nth === 0) {
                    $s = $start + TextIndex::utf16Length(mb_substr($text, 0, $at));

                    return [$s, $s + TextIndex::utf16Length($needle)];
                }
                $offset = $at + 1;
            }
        }
        throw new \LogicException("'$needle' not in fixture");
    }

    /**
     * @param  array{int,int}|null  $range  null = comment without anchor (old Drive API comment)
     * @param  string|null  $anchorId  override (e.g. unknown anchor id)
     */
    public function comment(string $id, ?array $range, string $content, string $status = 'OPEN',
        array $replies = [], ?string $anchorId = null, string $author = 'Penguji', string $created = '2026-10-01T08:00:00Z'): self
    {
        $anchor = $anchorId ?? ($range ? "kix.$id" : null);
        if ($range && $anchorId === null) {
            $this->anchors[$anchor] = ['anchorId' => $anchor, 'ranges' => [['startIndex' => $range[0], 'endIndex' => $range[1], 'tabId' => 't.0']]];
        }
        $post = fn ($c, $a = 'Penguji') => ['content' => $c, 'author' => ['displayName' => $a], 'createTime' => $created];
        $this->comments[] = array_filter([
            'commentId' => $id,
            'anchorId' => $anchor,
            'status' => $status,
            'plainTextQuote' => $range ? $this->textAt($range) : 'teks lama',
            'headPost' => $post($content, $author),
            'replies' => array_map(fn ($r) => is_array($r) ? $r : $post($r), $replies),
        ], fn ($v) => $v !== null);

        return $this;
    }

    public function otherTab(string $commentId, string $content): self
    {
        $anchor = "kix.$commentId";
        $this->extraTabs[] = ['tabProperties' => ['tabId' => 't.1', 'title' => 'Lampiran'], 'documentTab' => [
            'body' => ['content' => [['startIndex' => 1, 'endIndex' => 6, 'paragraph' => ['elements' => [
                ['startIndex' => 1, 'endIndex' => 6, 'textRun' => ['content' => "Lain\n"]],
            ]]]]],
            'commentAnchors' => [$anchor => ['anchorId' => $anchor, 'ranges' => [['startIndex' => 1, 'endIndex' => 5, 'tabId' => 't.1']]]],
        ]];
        $this->comments[] = ['commentId' => $commentId, 'anchorId' => $anchor, 'status' => 'OPEN', 'plainTextQuote' => 'Lain',
            'headPost' => ['content' => $content, 'author' => ['displayName' => 'Penguji'], 'createTime' => '2026-10-01T08:00:00Z']];

        return $this;
    }

    public function with(array $documentTab): self
    {
        $this->documentTab = array_replace_recursive($this->documentTab, $documentTab);

        return $this;
    }

    public function content(): array
    {
        return $this->content;
    }

    /** Raw documents.get response. */
    public function api(bool $withComments = true): array
    {
        $tab = ['body' => ['content' => $this->content]] + $this->documentTab;
        if ($withComments) {
            $tab['commentAnchors'] = $this->anchors;
        }
        $tabs = [['tabProperties' => ['tabId' => 't.0', 'title' => 'Tab 1'], 'documentTab' => $tab]];
        foreach ($this->extraTabs as $t) {
            if (! $withComments) {
                unset($t['documentTab']['commentAnchors']);
            }
            $tabs[] = $t;
        }
        $body = ['title' => 'Fixture', 'revisionId' => 'r1', 'tabs' => $tabs];
        if ($withComments) {
            $body['comments'] = $this->comments;
        }

        return $body;
    }

    public function fetched(bool $withComments = true, ?string $commentsError = null): FetchedDocument
    {
        return FetchedDocument::fromApi($this->api($withComments), $withComments, $commentsError);
    }

    private function textAt(array $range): string
    {
        return TextIndex::textBetween($this->content, $range[0], $range[1]);
    }

    /** Every feature the renderer and the sidebar handle. $prefix shifts all indexes (concurrent-edit tests). */
    public static function rich(string $prefix = ''): self
    {
        $f = new self;
        $f->para(["Laporan Hasil Pemeriksaan\n"], ['paragraphStyle' => ['namedStyleType' => 'HEADING_1']]);
        if ($prefix !== '') {
            $f->para([$prefix."\n"]);
        }
        $f->para(['Temuan ', ['text' => 'penting', 'style' => ['bold' => true]], " ditemukan 😀 di sini.\n"],
            ['paragraphStyle' => ['namedStyleType' => 'NORMAL_TEXT', 'alignment' => 'JUSTIFIED', 'indentFirstLine' => ['magnitude' => 36, 'unit' => 'PT']]]);
        $f->para(['Gambar ', ['inlineObjectElement' => ['inlineObjectId' => 'img1']], " lalu \u{000B}baris lunak\n"],
            ['positionedObjectIds' => ['pos1']]);
        $f->para(["Butir satu\n"], ['bullet' => ['listId' => 'l1', 'nestingLevel' => 0]]);
        $f->para(["Butir anak\n"], ['bullet' => ['listId' => 'l1', 'nestingLevel' => 1]]);
        $f->para(["Butir dua\n"], ['bullet' => ['listId' => 'l1', 'nestingLevel' => 0]]);
        $f->para(['Usulan ', ['text' => 'baru', 'ins' => true], ' ', ['text' => 'lama', 'del' => true], "\n"]);
        $f->para(['Lihat ', ['text' => 'tautan', 'style' => ['link' => ['url' => 'https://example.test']]], ' <b>&amp;</b>', "\n"]);
        $f->para(["\n"]);
        $f->para(['Pisah', ['pageBreak' => []], "\n"]);
        $f->toc(['Daftar isi baris']);
        $f->table([['Kode', 'Uraian'], ['T-01', 'Kas tidak sesuai']], [
            'tableStyle' => ['tableColumnProperties' => [
                ['widthType' => 'FIXED_WIDTH', 'width' => ['magnitude' => 100, 'unit' => 'PT']],
                ['widthType' => 'FIXED_WIDTH', 'width' => ['magnitude' => 368, 'unit' => 'PT']],
            ]],
        ], [
            '0,0' => ['columnSpan' => 1, 'rowSpan' => 1, 'backgroundColor' => ['color' => ['rgbColor' => ['red' => 0.9, 'green' => 0.9, 'blue' => 0.9]]]],
        ]);
        $f->table([['Gabung', ''], ['A', 'B']], [], ['0,0' => ['columnSpan' => 2, 'rowSpan' => 1]]);
        for ($n = 1; $n <= 40; $n++) {
            $f->para(["Paragraf pengisi nomor $n untuk menguji scroll.\n"]);
        }
        $f->para(['Kalimat akhir untuk komentar jauh. Sapa ', ['person' => ['personProperties' => ['name' => 'Budi']]], ".\n"]);

        $f->comment('c1', $f->pos('Kalimat akhir untuk komentar jauh.'), 'Komentar jauh', 'OPEN',
            ['Balasan 1', '', 'Balasan 2', ['content' => '', 'deleted' => true], 'Balasan 3', 'Balasan 4'], created: '2026-10-01T09:00:00Z');
        $f->comment('c2', $f->pos('Temuan penting'), 'Komentar <script>alert(1)</script>');
        $f->comment('c3', $f->pos('penting ditemukan'), 'Komentar tumpang tindih');
        $f->comment('c4', $f->pos('Kas tidak'), 'Sudah beres', 'RESOLVED');
        $f->otherTab('c5', 'Di tab lain');
        $f->comment('c6', null, 'Komentar lama Drive API');
        $f->comment('c7', null, 'Anchor tak dikenal', anchorId: 'kix.missing');

        $pt = fn ($v) => ['magnitude' => $v, 'unit' => 'PT'];
        $f->with([
            'documentStyle' => [
                'pageSize' => ['width' => $pt(612), 'height' => $pt(792)],
                'marginTop' => $pt(72), 'marginBottom' => $pt(72), 'marginLeft' => $pt(72), 'marginRight' => $pt(72),
            ],
            'namedStyles' => ['styles' => [
                ['namedStyleType' => 'NORMAL_TEXT',
                    'textStyle' => ['fontSize' => $pt(11), 'weightedFontFamily' => ['fontFamily' => 'Arial', 'weight' => 400]],
                    'paragraphStyle' => ['lineSpacing' => 115, 'spaceAbove' => $pt(0), 'spaceBelow' => $pt(0)]],
                ['namedStyleType' => 'HEADING_1',
                    'textStyle' => ['fontSize' => $pt(20), 'weightedFontFamily' => ['fontFamily' => 'Roboto', 'weight' => 700]],
                    'paragraphStyle' => ['spaceAbove' => $pt(20), 'spaceBelow' => $pt(6)]],
            ]],
            'lists' => ['l1' => ['listProperties' => ['nestingLevels' => [
                ['glyphType' => 'DECIMAL', 'glyphFormat' => '%0.', 'indentFirstLine' => $pt(18), 'indentStart' => $pt(36)],
                ['glyphType' => 'ALPHA', 'glyphFormat' => '%1)', 'indentFirstLine' => $pt(54), 'indentStart' => $pt(72)],
            ]]]],
            'inlineObjects' => ['img1' => ['inlineObjectProperties' => ['embeddedObject' => [
                'imageProperties' => ['contentUri' => 'https://example.test/img.png'],
                'size' => ['width' => $pt(120), 'height' => $pt(60)],
            ]]]],
            'positionedObjects' => ['pos1' => ['positionedObjectProperties' => ['embeddedObject' => [
                'imageProperties' => ['contentUri' => 'https://example.test/float.png'],
                'size' => ['width' => $pt(80), 'height' => $pt(80)],
            ]]]],
        ]);

        return $f;
    }
}

<?php

namespace Tests\Unit;

use App\Services\GoogleDocsPreview;
use Google\Service\Docs\Document;
use PHPUnit\Framework\TestCase;

class GoogleDocsPreviewTest extends TestCase
{
    /**
     * Build a Docs API body from a compact spec, computing indexes the way
     * Google does (UTF-16 units; atoms and table/cell starts are 1 index).
     */
    public static function fixture(): Document
    {
        $i = 1;
        $para = function (array $parts, array $extra = []) use (&$i): array {
            $start = $i;
            $elements = [];
            foreach ($parts as $part) {
                $s = $i;
                if (is_string($part)) {
                    $i += intdiv(strlen(mb_convert_encoding($part, 'UTF-16LE', 'UTF-8')), 2);
                    $elements[] = ['startIndex' => $s, 'endIndex' => $i, 'textRun' => ['content' => $part]];
                } else {
                    $i += 1;
                    $elements[] = ['startIndex' => $s, 'endIndex' => $i] + $part;
                }
            }

            return ['startIndex' => $start, 'endIndex' => $i, 'paragraph' => ['elements' => $elements] + $extra];
        };

        $content = [['startIndex' => 0, 'endIndex' => 1, 'sectionBreak' => []]];
        $content[] = $para(["Laporan Hasil Pemeriksaan\n"], ['paragraphStyle' => ['namedStyleType' => 'HEADING_1']]);
        $content[] = $para(['Temuan ', 'penting', " ditemukan 😀 di sini.\n"]);
        $content[0 + count($content) - 1]['paragraph']['elements'][1]['textRun']['textStyle'] = ['bold' => true];
        $content[] = $para(['Gambar ', ['inlineObjectElement' => ['inlineObjectId' => 'img1']], " lalu \u{000B}baris lunak\n"]);
        $content[] = $para(["Butir pertama\n"], ['bullet' => ['listId' => 'l1', 'nestingLevel' => 0]]);
        $content[] = $para(["Butir kedua\n"], ['bullet' => ['listId' => 'l1', 'nestingLevel' => 1]]);

        // Table: 1 index for the table start, 1 per cell start, then cell paragraphs.
        $tableStart = $i++;
        $rows = [];
        foreach ([['Kode', 'Uraian'], ['T-01', 'Kas tidak sesuai']] as $cells) {
            $rowStart = $i;
            $row = [];
            foreach ($cells as $text) {
                $cellStart = $i++;
                $p = $para([$text."\n"]);
                $row[] = ['startIndex' => $cellStart, 'endIndex' => $i, 'content' => [$p]];
            }
            $rows[] = ['startIndex' => $rowStart, 'endIndex' => $i, 'tableCells' => $row];
        }
        $i++; // table end
        $content[] = ['startIndex' => $tableStart, 'endIndex' => $i, 'table' => ['rows' => 2, 'columns' => 2, 'tableRows' => $rows]];
        $content[] = $para(['Sapa ', ['person' => ['personProperties' => ['name' => 'Budi']]], " akhir dokumen.\n"]);

        return new Document([
            'title' => 'Fixture',
            'revisionId' => 'r1',
            'body' => ['content' => $content],
            'inlineObjects' => ['img1' => ['inlineObjectProperties' => ['embeddedObject' => [
                'imageProperties' => ['contentUri' => 'https://example.test/img.png'],
            ]]]],
        ]);
    }

    public function test_renders_runs_with_their_indexes_and_structure(): void
    {
        $html = GoogleDocsPreview::render(self::fixture());

        $this->assertStringContainsString('<h1><span data-s="1" data-e="26">Laporan Hasil Pemeriksaan</span></h1>', $html);
        $this->assertStringContainsString('class="b">penting</span>', $html);
        $this->assertStringContainsString('data-atom="1" class="gd-atom"><img src="https://example.test/img.png"', $html);
        $this->assertStringContainsString('<table class="gd-table">', $html);
        $this->assertStringContainsString('class="gd-li"', $html);
        $this->assertStringContainsString('@Budi', $html);
    }

    public function test_text_between_counts_utf16_atoms_and_tables(): void
    {
        $doc = self::fixture();

        $this->assertSame('Laporan', GoogleDocsPreview::textBetween($doc, 1, 8));
        // "Temuan penting ditemukan 😀": emoji spans two indexes.
        $html = GoogleDocsPreview::render($doc);
        preg_match('/data-s="(\d+)" data-e="\d+"> ditemukan/', $html, $m);
        $s = (int) $m[1];
        $this->assertSame(' ditemukan 😀', GoogleDocsPreview::textBetween($doc, $s, $s + 13));

        preg_match('/data-s="(\d+)" data-e="\d+">Kas tidak sesuai/', $html, $m);
        $this->assertSame('Kas', GoogleDocsPreview::textBetween($doc, (int) $m[1], (int) $m[1] + 3));
    }
}

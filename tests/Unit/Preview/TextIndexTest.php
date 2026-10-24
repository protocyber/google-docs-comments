<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\TextIndex;
use PHPUnit\Framework\TestCase;
use Tests\Support\DocFixture;

class TextIndexTest extends TestCase
{
    public function test_text_between_counts_utf16_atoms_tables_and_toc(): void
    {
        $f = DocFixture::rich();
        $c = $f->content();

        $this->assertSame('Laporan', TextIndex::textBetween($c, 1, 8));
        [$s] = $f->pos(' ditemukan 😀');
        $this->assertSame(' ditemukan 😀', TextIndex::textBetween($c, $s, $s + 13)); // emoji = 2 units
        [$s, $e] = $f->pos('Kas tidak');
        $this->assertSame('Kas tidak', TextIndex::textBetween($c, $s, $e));
        [$s, $e] = $f->pos('Daftar isi');
        $this->assertSame('Daftar isi', TextIndex::textBetween($c, $s, $e));
        [$s] = $f->pos("Gambar \u{FFFC}");
        $this->assertSame("Gambar \u{FFFC}", TextIndex::textBetween($c, $s, $s + 8));
    }

    public function test_end_index_and_utf16_helpers(): void
    {
        $c = DocFixture::rich()->content();
        $this->assertSame(end($c)['endIndex'], TextIndex::endIndex($c));
        $this->assertSame(0, TextIndex::endIndex([]));
        $this->assertSame(2, TextIndex::utf16Length('😀'));
        $this->assertSame('😀b', TextIndex::utf16Substr('a😀b', 1, 3));
    }

    public function test_runs_cover_every_index_once(): void
    {
        $c = DocFixture::rich()->content();
        $covered = 0;
        foreach (TextIndex::runs($c) as [$s, $e, $text]) {
            $this->assertSame($e - $s, TextIndex::utf16Length($text));
            $covered += $e - $s;
        }
        // Body minus section break, table/toc start+end and cell starts.
        $this->assertGreaterThan(1000, $covered);
    }

    public function test_indexed_aligns_with_docs_indexes(): void
    {
        $f = DocFixture::rich();
        $c = $f->content();
        $indexed = TextIndex::indexed($c);

        $this->assertSame(TextIndex::endIndex($c) - 1, intdiv(strlen($indexed), 2));

        foreach ([$f->pos('Kas tidak'), $f->pos('😀')] as [$s, $e]) {
            $slice = substr($indexed, ($s - 1) * 2, ($e - $s) * 2);
            $this->assertSame(TextIndex::textBetween($c, $s, $e), mb_convert_encoding($slice, 'UTF-8', 'UTF-16LE'));
        }
    }

    public function test_splice_matches_a_refetch(): void
    {
        $a = new DocFixture;
        $a->para(["Kas tidak sesuai dengan catatan.\n"]);
        $a->para(["Paragraf kedua tidak berubah.\n"]);

        $b = new DocFixture;
        $b->para(["Kas sudah benar-benar sesuai dengan catatan.\n"]);
        $b->para(["Paragraf kedua tidak berubah.\n"]);

        [$s, $e] = $a->pos('tidak sesuai');

        $spliced = TextIndex::splice(TextIndex::indexed($a->content()), $s, $e, 'sudah benar-benar sesuai');

        $this->assertSame(TextIndex::indexed($b->content()), $spliced);
    }
}

<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\CommentIndex;
use App\Services\Preview\FetchedDocument;
use App\Services\Preview\HtmlRenderer;
use App\Services\Preview\TextIndex;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\DocFixture;

class HtmlRendererTest extends TestCase
{
    private function render(FetchedDocument $doc): string
    {
        $comments = new CommentIndex($doc->comments, $doc->tab['commentAnchors'] ?? [], $doc->tabId,
            $doc->otherTabAnchorIds, TextIndex::endIndex($doc->content()));

        return (new HtmlRenderer($doc->tab, $comments))->render();
    }

    public function test_runs_split_at_comment_boundaries(): void
    {
        $f = DocFixture::rich();
        $html = $this->render($f->fetched());
        [$t] = $f->pos('Temuan ');
        [$p, $pe] = $f->pos('penting');

        $this->assertStringContainsString(sprintf('<span data-s="%d" data-e="%d" data-c="c2">Temuan </span>', $t, $t + 7), $html);
        $this->assertStringContainsString(sprintf('<span data-s="%d" data-e="%d" data-c="c2 c3" style="font-weight:700;">penting</span>', $p, $pe), $html);
        $this->assertStringContainsString(sprintf('<span data-s="%d" data-e="%d" data-c="c3"> ditemukan</span>', $pe, $pe + 10), $html);
        [$k, $ke] = $f->pos('Kas tidak');
        $this->assertStringContainsString(sprintf('<span data-s="%d" data-e="%d" data-r="c4">Kas tidak</span>', $k, $ke), $html);
    }

    public function test_split_inside_styled_run_keeps_style(): void
    {
        $f = (new DocFixture)->para(['Ab', ['text' => 'cdef', 'style' => ['bold' => true]], "\n"]);
        $f->comment('x', $f->pos('de'), 'k');
        $html = $this->render($f->fetched());
        [$s, $e] = $f->pos('de');

        $this->assertStringContainsString(sprintf('<span data-s="%d" data-e="%d" style="font-weight:700;">c</span>', $s - 1, $s), $html);
        $this->assertStringContainsString(sprintf('<span data-s="%d" data-e="%d" data-c="x" style="font-weight:700;">de</span>', $s, $e), $html);
        $this->assertStringContainsString(sprintf('<span data-s="%d" data-e="%d" style="font-weight:700;">f</span>', $e, $e + 1), $html);
    }

    public function test_page_named_styles_and_fonts(): void
    {
        $doc = DocFixture::rich()->fetched();
        $comments = new CommentIndex($doc->comments, $doc->tab['commentAnchors'], $doc->tabId, $doc->otherTabAnchorIds, TextIndex::endIndex($doc->content()));
        $r = new HtmlRenderer($doc->tab, $comments);
        $html = $r->render();

        $this->assertStringStartsWith('<div class="gd-page" style="width:612pt;min-height:792pt;padding:72pt 72pt 72pt 72pt;"><style>', $html);
        $this->assertStringContainsString(".gd-page{font-family:'Arial',Arial,sans-serif;font-size:11pt;}", $html);
        $this->assertStringContainsString(".gd-page .gd-n-HEADING_1{line-height:1.32;margin-top:20pt;margin-bottom:6pt;font-family:'Roboto',Arial,sans-serif;font-weight:700;font-size:20pt;}", $html);
        $this->assertStringContainsString('<h1 class="gd-n-HEADING_1" data-ps="1" data-pe="26" data-ds="1" data-de="27"><span data-s="1" data-e="26">Laporan Hasil Pemeriksaan</span></h1>', $html);
        $this->assertStringContainsString('<p class="gd-n-NORMAL_TEXT" data-ps="27" data-pe="63" data-ds="27" data-de="64" data-merge style="text-align:justify;margin-inline-start:0pt;text-indent:36pt;">', $html);
        $this->assertSame(['Roboto'], $r->fonts());
    }

    public function test_lists_markers_and_indents(): void
    {
        $html = $this->render(DocFixture::rich()->fetched());
        $this->assertStringContainsString('<p class="gd-n-NORMAL_TEXT" data-ps="91" data-pe="101" data-ds="91" data-de="102" data-merge style="margin-inline-start:36pt;text-indent:-18pt;"><span data-deco class="gd-marker" aria-hidden="true" style="min-width:18pt;">1.</span>', $html);
        $this->assertStringContainsString('<span data-deco class="gd-marker" aria-hidden="true" style="min-width:18pt;">a)</span>', $html);
        $this->assertStringContainsString('>2.</span>', $html);
    }

    public function test_objects_suggestions_tables_toc_and_breaks(): void
    {
        $html = $this->render(DocFixture::rich()->fetched());
        $this->assertMatchesRegularExpression('/data-atom="1" class="gd-atom"><img src="https:\/\/example.test\/img.png" alt="" draggable="false" style="width:120pt"><\/span>/', $html);
        $this->assertStringContainsString('<span data-deco class="gd-floating" aria-hidden="true"><img src="https://example.test/float.png" alt="" draggable="false" style="width:80pt"></span>', $html);
        $this->assertMatchesRegularExpression('/class="gd-ins">baru<\/span>/', $html);
        $this->assertMatchesRegularExpression('/class="gd-del">lama<\/span>/', $html);
        $this->assertMatchesRegularExpression('/class="gd-link">tautan<\/span>/', $html);
        $this->assertMatchesRegularExpression('/data-atom="1" class="gd-atom gd-pagebreak"><\/span>/', $html);
        $this->assertMatchesRegularExpression('/data-atom="1" class="gd-atom">@Budi<\/span>/', $html);
        $this->assertStringContainsString('<div class="gd-toc"><p class="gd-n-NORMAL_TEXT" data-ps="175" data-pe="191" data-ds="175" data-de="191" data-ro="toc">', $html);
        $this->assertStringContainsString('<colgroup><col style="width:100pt"><col style="width:368pt"></colgroup>', $html);
        $this->assertStringContainsString('<td style="background-color:#e6e6e6;">', $html);
        $this->assertMatchesRegularExpression('/<tr><td colspan="2">(?:(?!<\/tr>).)*Gabung(?:(?!<\/tr>).)*<\/td><\/tr>/s', $html);
        $this->assertStringContainsString('<p class="gd-n-NORMAL_TEXT" data-ps="166" data-pe="166" data-ds="166" data-de="167" data-merge><span data-deco>&#8203;</span></p>', $html);
        $this->assertStringContainsString('baris lunak', $html);
        $this->assertStringContainsString(" lalu \nbaris lunak", $html);
    }

    public function test_escapes_text_and_rejects_non_https_images(): void
    {
        $html = $this->render(DocFixture::rich()->fetched());
        $this->assertStringContainsString('&lt;b&gt;&amp;amp;&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);

        $f = (new DocFixture)->para(['x', ['inlineObjectElement' => ['inlineObjectId' => 'i']], "\n"])
            ->with(['inlineObjects' => ['i' => ['inlineObjectProperties' => ['embeddedObject' => ['imageProperties' => ['contentUri' => 'javascript:alert(1)']]]]]]);
        $html = $this->render($f->fetched());
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('[gambar]', $html);
    }

    public function test_paragraph_edit_ranges(): void
    {
        $f = DocFixture::rich();
        $content = $f->content();
        $html = $this->render($f->fetched());
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        $isPara = fn ($el) => $el !== null && isset($el['paragraph']);

        // Heading: first body paragraph, preceded by the document's initial sectionBreak.
        $heading = $content[1];
        $next = $content[2];
        $node = $xp->query("//h1[contains(., 'Laporan Hasil Pemeriksaan')]")->item(0);
        $this->assertNotNull($node, 'heading not found');
        $this->assertSame((string) $heading['startIndex'], $node->getAttribute('data-ps'));
        $this->assertSame((string) ($heading['endIndex'] - 1), $node->getAttribute('data-pe'));
        $this->assertSame((string) $heading['startIndex'], $node->getAttribute('data-ds'));
        $this->assertTrue($isPara($next));
        $this->assertSame((string) $heading['endIndex'], $node->getAttribute('data-de'));
        $this->assertFalse($node->hasAttribute('data-merge'));
        $this->assertFalse($node->hasAttribute('data-ro'));

        // "Temuan" paragraph: previous and next are both paragraphs.
        $temuan = $content[2];
        $node = $xp->query("//p[contains(., 'Temuan')]")->item(0);
        $this->assertNotNull($node, 'Temuan paragraph not found');
        $this->assertSame((string) $temuan['startIndex'], $node->getAttribute('data-ps'));
        $this->assertSame((string) ($temuan['endIndex'] - 1), $node->getAttribute('data-pe'));
        $this->assertSame((string) $temuan['startIndex'], $node->getAttribute('data-ds'));
        $this->assertSame((string) $temuan['endIndex'], $node->getAttribute('data-de'));
        $this->assertTrue($node->hasAttribute('data-merge'));

        // "Pisah" paragraph: next element is the TOC, not a paragraph.
        $pisah = $content[10];
        $this->assertFalse($isPara($content[11]));
        $node = $xp->query("//p[contains(., 'Pisah')]")->item(0);
        $this->assertNotNull($node, 'Pisah paragraph not found');
        $this->assertSame((string) $pisah['startIndex'], $node->getAttribute('data-ds'));
        $this->assertSame((string) ($pisah['endIndex'] - 1), $node->getAttribute('data-de'));

        // TOC line: read-only as "toc".
        $node = $xp->query("//div[@class='gd-toc']//p[contains(., 'Daftar isi baris')]")->item(0);
        $this->assertNotNull($node, 'TOC line not found');
        $this->assertSame('toc', $node->getAttribute('data-ro'));

        // "Usulan" paragraph: carries suggested insertion/deletion runs.
        $node = $xp->query("//p[contains(., 'Usulan')]")->item(0);
        $this->assertNotNull($node, 'Usulan paragraph not found');
        $this->assertSame('suggestion', $node->getAttribute('data-ro'));

        // Cell paragraph "Kas tidak sesuai": alone in its cell's content list.
        $node = $xp->query("//td//p[contains(., 'Kas tidak sesuai')]")->item(0);
        $this->assertNotNull($node, 'cell paragraph not found');
        [$ks] = $f->pos('Kas tidak sesuai');
        $ke = $ks + TextIndex::utf16Length('Kas tidak sesuai') + 1; // + trailing "\n"
        $this->assertSame((string) $ks, $node->getAttribute('data-ds'));
        $this->assertSame((string) ($ke - 1), $node->getAttribute('data-de'));
        $this->assertFalse($node->hasAttribute('data-merge'));

        // Last body paragraph: no next element, previous is a paragraph.
        $last = end($content);
        $node = $xp->query("//p[contains(., 'Kalimat akhir untuk komentar jauh')]")->item(0);
        $this->assertNotNull($node, 'last paragraph not found');
        $this->assertSame((string) $last['startIndex'], $node->getAttribute('data-ds'));
        $this->assertSame((string) ($last['endIndex'] - 1), $node->getAttribute('data-de'));
        $this->assertTrue($node->hasAttribute('data-merge'));
    }

    public static function documents(): iterable
    {
        yield 'rich' => [DocFixture::rich()->fetched()];
        yield 'rich without comments' => [DocFixture::rich()->fetched(false)];
        foreach (glob(__DIR__.'/../../Fixtures/docs/*.json') ?: [] as $file) {
            yield basename($file) => [FetchedDocument::fromApi(json_decode(file_get_contents($file), true), true)];
        }
    }

    /** Index invariant from the spec: spans hold exactly the document text, once. */
    #[DataProvider('documents')]
    public function test_spans_cover_document_text_exactly(FetchedDocument $doc): void
    {
        $content = $doc->content();
        $html = $this->render($doc);
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        $covered = 0;
        foreach ($xp->query('//*[@data-s]') as $span) {
            $s = (int) $span->getAttribute('data-s');
            $e = (int) $span->getAttribute('data-e');
            $this->assertGreaterThan($s, $e);
            $this->assertSame(0, $xp->query('ancestor::*[@data-s]', $span)->length, 'nested index span');
            $expected = TextIndex::textBetween($content, $s, $e);
            if ($span->getAttribute('data-atom') === '1') {
                $this->assertSame(str_repeat("\u{FFFC}", $e - $s), $expected, "atom $s");
            } else {
                $this->assertSame(str_replace("\u{000B}", "\n", $expected), $span->textContent, "span $s-$e");
            }
            $covered += $e - $s;
        }

        $expectedCover = 0;
        foreach (TextIndex::runs($content) as [$s, $e, $text]) {
            $expectedCover += $e - $s - (str_ends_with($text, "\n") ? 1 : 0);
        }
        $this->assertSame($expectedCover, $covered);

        $stray = $xp->query('//text()[not(ancestor::*[@data-s]) and not(ancestor::*[@data-deco]) and not(ancestor::style)]');
        $this->assertSame(0, $stray->length, 'text outside index spans');
    }
}

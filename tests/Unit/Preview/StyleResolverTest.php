<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\StyleResolver;
use PHPUnit\Framework\TestCase;

class StyleResolverTest extends TestCase
{
    private function pt(float $v): array
    {
        return ['magnitude' => $v, 'unit' => 'PT'];
    }

    private function resolver(): StyleResolver
    {
        return new StyleResolver(['styles' => [
            ['namedStyleType' => 'NORMAL_TEXT',
                'textStyle' => ['fontSize' => $this->pt(11), 'weightedFontFamily' => ['fontFamily' => 'Arial', 'weight' => 400]],
                'paragraphStyle' => ['lineSpacing' => 115, 'spaceAbove' => $this->pt(0), 'spaceBelow' => $this->pt(0)]],
            ['namedStyleType' => 'HEADING_1',
                'textStyle' => ['fontSize' => $this->pt(20), 'weightedFontFamily' => ['fontFamily' => 'Roboto', 'weight' => 700]],
                'paragraphStyle' => ['spaceAbove' => $this->pt(20), 'spaceBelow' => $this->pt(6)]],
        ]]);
    }

    public function test_named_styles_inherit_from_normal_text(): void
    {
        $r = $this->resolver();
        $p = $r->namedParagraph('HEADING_1');
        $this->assertSame(115, $p['lineSpacing']);
        $this->assertSame(20.0, StyleResolver::pt($p['spaceAbove']));
        $t = $r->namedText('HEADING_1');
        $this->assertSame('Roboto', $t['weightedFontFamily']['fontFamily']);
        $this->assertSame(20.0, StyleResolver::pt($t['fontSize']));
        $this->assertSame([], $r->namedText('UNKNOWN') === $r->namedText('NORMAL_TEXT') ? [] : ['differs']);
    }

    public function test_paragraph_css(): void
    {
        $css = StyleResolver::paragraphCss([
            'alignment' => 'JUSTIFIED', 'lineSpacing' => 115, 'direction' => 'RIGHT_TO_LEFT',
            'spaceAbove' => $this->pt(20), 'spaceBelow' => $this->pt(0),
            'indentStart' => $this->pt(36), 'indentEnd' => $this->pt(10), 'indentFirstLine' => $this->pt(54),
            'shading' => ['backgroundColor' => ['color' => ['rgbColor' => ['red' => 1, 'green' => 1, 'blue' => 0]]]],
            'borderTop' => ['width' => $this->pt(1), 'dashStyle' => 'DASH', 'color' => ['color' => ['rgbColor' => []]], 'padding' => $this->pt(2)],
        ]);
        $this->assertSame('text-align:justify;direction:rtl;line-height:1.32;margin-top:20pt;margin-bottom:0pt;'
            .'margin-inline-start:36pt;margin-inline-end:10pt;text-indent:18pt;background-color:#ffff00;'
            .'border-top:1pt dashed #000000;padding-top:2pt;', $css);
        $this->assertSame('', StyleResolver::paragraphCss([]));
        $this->assertSame('text-align:center;', StyleResolver::paragraphCss(['alignment' => 'CENTER']));
    }

    public function test_text_css_and_fonts(): void
    {
        $r = $this->resolver();
        $css = $r->textCss([
            'weightedFontFamily' => ['fontFamily' => 'Lato', 'weight' => 300], 'italic' => true,
            'underline' => true, 'strikethrough' => true, 'fontSize' => $this->pt(14),
            'foregroundColor' => ['color' => ['rgbColor' => ['red' => 0.2, 'green' => 0.4, 'blue' => 0.6]]],
            'backgroundColor' => ['color' => ['rgbColor' => ['red' => 1, 'green' => 1, 'blue' => 1]]],
            'smallCaps' => true, 'baselineOffset' => 'SUPERSCRIPT',
        ]);
        $this->assertSame("font-family:'Lato',Arial,sans-serif;font-weight:300;font-style:italic;"
            .'text-decoration:underline line-through;font-size:14pt;color:#336699;background-color:#ffffff;'
            .'font-variant:small-caps;vertical-align:super;font-size:.75em;', $css);
        $this->assertSame('font-weight:700;', $r->textCss(['bold' => true]));
        $this->assertSame('font-weight:400;', $r->textCss(['bold' => false]));
        $this->assertSame("font-family:'Arial',Arial,sans-serif;", $r->textCss(['weightedFontFamily' => ['fontFamily' => 'Arial']]));
        $this->assertSame(['Lato'], $r->fonts()); // Arial is web-safe, not collected
    }

    public function test_font_family_is_sanitized(): void
    {
        $r = $this->resolver();
        $css = $r->textCss(['weightedFontFamily' => ['fontFamily' => "Evil'); } body { x: url(y"]]);
        $this->assertStringNotContainsString("'", substr($css, strlen("font-family:'"), -strlen("',Arial,sans-serif;")));
        $this->assertStringNotContainsString(';}', $css);
        $this->assertStringNotContainsString('(', $css);
        $this->assertSame('', $r->textCss(['weightedFontFamily' => ['fontFamily' => '();']]));
    }

    public function test_helpers(): void
    {
        $this->assertNull(StyleResolver::color(null));
        $this->assertNull(StyleResolver::color(['color' => null]));
        $this->assertSame('#000000', StyleResolver::color(['color' => ['rgbColor' => []]]));
        $this->assertSame(0.0, StyleResolver::pt(['unit' => 'PT']));
        $this->assertNull(StyleResolver::pt(null));
        $this->assertSame('none', StyleResolver::border(null));
        $this->assertSame('none', StyleResolver::border(['width' => ['magnitude' => 0, 'unit' => 'PT']]));
        $this->assertSame('1.5pt dotted #ff0000', StyleResolver::border(['width' => ['magnitude' => 1.5], 'dashStyle' => 'DOT', 'color' => ['color' => ['rgbColor' => ['red' => 1]]]]));
        $this->assertSame('1.32', StyleResolver::num(1.3225));
        $this->assertSame('0', StyleResolver::num(0.0));
        $this->assertSame('12', StyleResolver::num(12.0));
    }
}

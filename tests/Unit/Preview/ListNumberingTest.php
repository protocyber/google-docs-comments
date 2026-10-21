<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\ListNumbering;
use PHPUnit\Framework\TestCase;

class ListNumberingTest extends TestCase
{
    private function lists(): array
    {
        return [
            'num' => ['listProperties' => ['nestingLevels' => [
                ['glyphType' => 'DECIMAL', 'glyphFormat' => '%0.'],
                ['glyphType' => 'ALPHA', 'glyphFormat' => '%1)'],
                ['glyphType' => 'ROMAN', 'glyphFormat' => '%0.%1.%2'],
            ]]],
            'upper' => ['listProperties' => ['nestingLevels' => [
                ['glyphType' => 'UPPER_ALPHA', 'glyphFormat' => '%0.'],
                ['glyphType' => 'UPPER_ROMAN', 'glyphFormat' => '(%1)'],
            ]]],
            'bul' => ['listProperties' => ['nestingLevels' => [
                ['glyphSymbol' => '●'], ['glyphSymbol' => '○'], ['glyphType' => 'GLYPH_TYPE_UNSPECIFIED'],
            ]]],
            'zero' => ['listProperties' => ['nestingLevels' => [['glyphType' => 'ZERO_DECIMAL', 'glyphFormat' => '%0.']]]],
        ];
    }

    public function test_decimal_alpha_roman_nesting_and_restart(): void
    {
        $n = new ListNumbering($this->lists());
        $seq = [
            $n->marker('num', 0), $n->marker('num', 1), $n->marker('num', 1),
            $n->marker('num', 2), $n->marker('num', 0), $n->marker('num', 1),
        ];
        $this->assertSame(['1.', 'a)', 'b)', '1.b.i', '2.', 'a)'], $seq);
    }

    public function test_numbering_continues_across_other_lists(): void
    {
        $n = new ListNumbering($this->lists());
        $n->marker('num', 0);
        $n->marker('upper', 0);
        $this->assertSame('2.', $n->marker('num', 0));
        $this->assertSame('B.', $n->marker('upper', 0));
        $this->assertSame('(I)', $n->marker('upper', 1));
    }

    public function test_bullets_and_fallbacks(): void
    {
        $n = new ListNumbering($this->lists());
        $this->assertSame('●', $n->marker('bul', 0));
        $this->assertSame('○', $n->marker('bul', 1));
        $this->assertSame('●', $n->marker('bul', 2));
        $this->assertSame('●', $n->marker('missing', 0));
        $this->assertSame('01.', $n->marker('zero', 0));
    }

    public function test_alpha_and_roman_conversion(): void
    {
        $n = new ListNumbering(['a' => ['listProperties' => ['nestingLevels' => [['glyphType' => 'ALPHA', 'glyphFormat' => '%0']]]]]);
        $out = [];
        for ($i = 1; $i <= 28; $i++) {
            $out[] = $n->marker('a', 0);
        }
        $this->assertSame(['a', 'z', 'aa', 'ab'], [$out[0], $out[25], $out[26], $out[27]]);
        $this->assertSame('xiv', ListNumbering::roman(14));
        $this->assertSame('mcmxcix', ListNumbering::roman(1999));
    }

    public function test_level_returns_nesting_level(): void
    {
        $n = new ListNumbering($this->lists());
        $this->assertSame('ALPHA', $n->level('num', 1)['glyphType']);
        $this->assertSame([], $n->level('num', 7));
        $this->assertSame([], $n->level('missing', 0));
    }
}

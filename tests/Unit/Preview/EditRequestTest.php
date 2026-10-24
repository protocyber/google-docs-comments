<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\EditRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EditRequestTest extends TestCase
{
    public function test_replace_is_delete_then_insert(): void
    {
        $this->assertSame([
            ['deleteContentRange' => ['range' => ['startIndex' => 10, 'endIndex' => 15]]],
            ['insertText' => ['location' => ['index' => 10], 'text' => 'abc']],
        ], EditRequest::build(10, 15, 'abc'));
    }

    public function test_pure_insert_and_pure_delete(): void
    {
        $this->assertSame([
            ['insertText' => ['location' => ['index' => 7], 'text' => "x\n"]],
        ], EditRequest::build(7, 7, "x\n"));

        $this->assertSame([
            ['deleteContentRange' => ['range' => ['startIndex' => 7, 'endIndex' => 9]]],
        ], EditRequest::build(7, 9, ''));
    }

    public function test_refuses_object_replacement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EditRequest::build(5, 6, "\u{FFFC}");
    }

    public function test_refuses_bad_ranges(): void
    {
        foreach ([
            [0, 5, 'x'],
            [5, 4, 'x'],
            [5, 5, ''],
        ] as [$start, $end, $text]) {
            try {
                EditRequest::build($start, $end, $text);
                $this->fail("expected InvalidArgumentException for [$start, $end, '$text']");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_keeps_soft_break(): void
    {
        $this->assertSame([
            ['insertText' => ['location' => ['index' => 5], 'text' => "a\u{000B}b"]],
        ], EditRequest::build(5, 5, "a\u{000B}b"));
    }
}

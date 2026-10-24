<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\EditBase;
use Tests\TestCase;

class EditBaseTest extends TestCase
{
    public function test_put_and_get_round_trip(): void
    {
        EditBase::put(1, 'DOC', 'r1', 'indexed-bytes');

        $this->assertSame('indexed-bytes', EditBase::get(1, 'DOC', 'r1'));
    }

    public function test_different_user_doc_or_revision_misses(): void
    {
        EditBase::put(1, 'DOC', 'r1', 'indexed-bytes');

        $this->assertNull(EditBase::get(2, 'DOC', 'r1'));
        $this->assertNull(EditBase::get(1, 'OTHER', 'r1'));
        $this->assertNull(EditBase::get(1, 'DOC', 'r2'));
    }
}

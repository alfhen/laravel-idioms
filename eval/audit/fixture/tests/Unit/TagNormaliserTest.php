<?php

namespace Tests\Unit;

use App\Support\TagNormaliser;
use PHPUnit\Framework\TestCase;

class TagNormaliserTest extends TestCase
{
    public function test_keeps_first_occurrence_of_each_distinct_tag(): void
    {
        $tags = (new TagNormaliser)->unique(['0', '00', ' 1e3 ', '1000', 'wool', 'wool', '']);

        $this->assertSame(['0', '00', '1e3', '1000', 'wool'], $tags);
    }
}

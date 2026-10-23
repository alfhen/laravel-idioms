<?php

namespace Tests\Unit;

use App\Support\ProductCodes;
use PHPUnit\Framework\TestCase;

class ProductCodesTest extends TestCase
{
    public function test_codes_with_leading_zeros_stay_distinct(): void
    {
        $this->assertSame(['007', '7', '07'], (new ProductCodes)->normalise([' 007', '7', '07 ', '7']));
    }
}

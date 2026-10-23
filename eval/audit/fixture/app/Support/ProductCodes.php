<?php

namespace App\Support;

class ProductCodes
{
    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function normalise(array $codes): array
    {
        return array_values(array_unique(array_map(trim(...), $codes)));
    }
}

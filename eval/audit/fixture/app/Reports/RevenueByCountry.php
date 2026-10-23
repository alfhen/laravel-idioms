<?php

namespace App\Reports;

use App\Models\Order;

class RevenueByCountry
{
    /** @return array<string, int> */
    public function __invoke(): array
    {
        $totals = [];

        foreach (Order::query()->get() as $order) {
            $totals[$order->country] = ($totals[$order->country] ?? 0) + $order->total_cents;
        }

        ksort($totals);

        return $totals;
    }
}

<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Str;

class PlaceOrder
{
    public function __invoke(Customer $customer, int $totalCents): Order
    {
        return Order::create([
            'reference' => 'ORD-'.Str::upper(Str::random(10)),
            'customer_email' => $customer->email,
            'status' => 'pending',
            'total_cents' => $totalCents,
        ]);
    }
}

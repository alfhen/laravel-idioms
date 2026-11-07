<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerOrderController extends Controller
{
    public function __invoke(Request $request, Customer $customer): JsonResponse
    {
        $orders = Order::query()
            ->where('customer_email', $customer->email)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(50);

        return response()->json($orders);
    }
}

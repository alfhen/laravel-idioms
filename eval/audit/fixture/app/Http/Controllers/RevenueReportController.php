<?php

namespace App\Http\Controllers;

use App\Reports\RevenueByCountry;
use Illuminate\Http\JsonResponse;

class RevenueReportController extends Controller
{
    public function __invoke(RevenueByCountry $report): JsonResponse
    {
        return response()->json($report());
    }
}

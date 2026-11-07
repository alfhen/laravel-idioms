<?php

use App\Http\Controllers\CustomerOrderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/customers/{customer}/orders', CustomerOrderController::class)->middleware('auth:sanctum');

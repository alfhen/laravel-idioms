<?php

use App\Http\Controllers\LanguageController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\RevenueReportController;
use Illuminate\Support\Facades\Route;

Route::get('/languages', LanguageController::class);
Route::get('/offers', [OfferController::class, 'index']);
Route::get('/offers/titles', [OfferController::class, 'titles']);
Route::post('/offers', [OfferController::class, 'store']);
Route::get('/reports/revenue-by-country', RevenueReportController::class);

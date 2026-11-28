<?php

use App\Http\Controllers\TokenCheckController;
use Illuminate\Support\Facades\Route;

Route::post('/token-checks', TokenCheckController::class);

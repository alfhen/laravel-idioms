<?php

namespace App\Http\Controllers;

use App\Support\LanguageSettings;
use Illuminate\Http\JsonResponse;

class LanguageController extends Controller
{
    public function __invoke(LanguageSettings $settings): JsonResponse
    {
        return response()->json(['enabled' => $settings->enabledCodes()]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Auth\TokenVerifier;
use App\Http\Requests\CheckTokenRequest;
use Illuminate\Http\JsonResponse;

class TokenCheckController extends Controller
{
    public function __invoke(CheckTokenRequest $request, TokenVerifier $verifier): JsonResponse
    {
        return response()->json(['valid' => $verifier->check($request->validated('token'))]);
    }
}

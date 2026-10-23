<?php

namespace App\Http\Controllers;

use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Support\TagNormaliser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfferController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $offers = Offer::query()
            ->when($request->boolean('accepted'), fn ($query) => $query->accepted())
            ->orderBy('id')
            ->get();

        return OfferResource::collection($offers);
    }

    public function titles(): AnonymousResourceCollection
    {
        return OfferResource::collection(Offer::query()->select(['id', 'title'])->orderBy('title')->get());
    }

    public function store(Request $request, TagNormaliser $normaliser): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:120',
            'price_cents' => 'required|integer|min:0',
            'tags' => 'array',
            'tags.*' => 'string',
        ]);

        $offer = Offer::create([...$request->all(), 'tags' => $normaliser->unique($request->input('tags', []))]);

        return (new OfferResource($offer))->response()->setStatusCode(201);
    }
}

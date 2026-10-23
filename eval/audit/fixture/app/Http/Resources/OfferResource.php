<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Offer */
class OfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'price_cents' => $this->whenHas('price_cents'),
            'tags' => $this->whenHas('tags'),
            'accepted' => (bool) ($this->accepted ?? false),
        ];
    }
}

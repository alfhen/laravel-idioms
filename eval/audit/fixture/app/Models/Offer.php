<?php

namespace App\Models;

use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    protected $fillable = ['title', 'price_cents', 'accepted', 'tags'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    /** @param Builder<Offer> $query */
    public function scopeAccepted(Builder $query): void
    {
        $query->where('accepted', true);
    }
}

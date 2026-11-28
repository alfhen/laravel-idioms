<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'balance_cents'])]
class Merchant extends Model
{
    use HasFactory;

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}

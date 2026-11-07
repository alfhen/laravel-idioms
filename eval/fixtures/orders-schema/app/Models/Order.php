<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['reference', 'customer_email', 'status', 'total_cents'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;
}

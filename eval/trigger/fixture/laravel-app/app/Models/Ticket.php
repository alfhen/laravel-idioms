<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'subject', 'body', 'status', 'resolved_at'];

    protected $casts = [
        'status' => TicketStatus::class,
        'resolved_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    public function lastReplyAt()
    {
        $latest = null;

        foreach ($this->replies as $reply) {
            if ($latest === null || $reply->created_at > $latest) {
                $latest = $reply->created_at;
            }
        }

        return $latest;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use HasFactory;

    protected $fillable = ['email', 'name', 'email_normalized', 'crm_sync_queued_at', 'crm_synced_at', 'crm_last_error'];

    protected function casts(): array
    {
        return [
            'email_normalized' => 'boolean',
            'crm_sync_queued_at' => 'datetime',
            'crm_synced_at' => 'datetime',
        ];
    }
}

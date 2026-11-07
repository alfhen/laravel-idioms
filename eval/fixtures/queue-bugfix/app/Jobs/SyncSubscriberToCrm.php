<?php

namespace App\Jobs;

use App\Models\Subscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSubscriberToCrm implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public Subscriber $subscriber) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->subscriber->id)];
    }

    public function handle(): void
    {
        try {
            Http::timeout(10)
                ->post(config('services.crm.url', 'https://crm.example.test/api').'/contacts', [
                    'email' => $this->subscriber->email,
                    'name' => $this->subscriber->name,
                ])
                ->throw();

            $this->subscriber->update(['crm_synced_at' => now(), 'crm_last_error' => null]);
        } catch (Throwable $e) {
            Log::error('CRM sync failed', ['subscriber' => $this->subscriber->id, 'error' => $e->getMessage()]);
            $this->subscriber->update(['crm_last_error' => $e->getMessage()]);
        }
    }
}

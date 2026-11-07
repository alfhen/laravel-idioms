<?php

use App\Jobs\SyncSubscriberToCrm;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('marks a subscriber as synced when the CRM accepts it', function () {
    Http::fake(['crm.example.test/*' => Http::response(['id' => 'c-1'], 201)]);
    $subscriber = Subscriber::factory()->create();

    SyncSubscriberToCrm::dispatch($subscriber);

    expect($subscriber->fresh()->crm_synced_at)->not->toBeNull();
    Http::assertSent(fn ($request) => $request['email'] === $subscriber->email);
});

it('queues a sync for subscribers that are not in the CRM yet', function () {
    Queue::fake();
    Subscriber::factory()->count(3)->create();
    Subscriber::factory()->create(['crm_synced_at' => now()]);

    $this->artisan('crm:dispatch-sync')->assertSuccessful();

    Queue::assertPushed(SyncSubscriberToCrm::class, 3);
});

it('normalizes subscriber emails', function () {
    $subscriber = Subscriber::factory()->create(['email' => '  Jane@Example.COM ']);

    $this->artisan('subscribers:normalize-emails')->assertSuccessful();

    expect($subscriber->fresh())
        ->email->toBe('jane@example.com')
        ->email_normalized->toBeTrue();
});

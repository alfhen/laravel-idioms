<?php

use App\Models\Merchant;
use App\Models\Payout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('pays every merchant with a balance and zeroes it', function () {
    $paid = Merchant::factory()->create(['balance_cents' => 12_500]);
    $empty = Merchant::factory()->create(['balance_cents' => 0]);

    $this->artisan('payouts:run')->assertSuccessful();

    expect(Payout::count())->toBe(1)
        ->and(Payout::first()->merchant_id)->toBe($paid->id)
        ->and(Payout::first()->amount_cents)->toBe(12_500)
        ->and(Payout::first()->paid_at)->not->toBeNull()
        ->and($paid->fresh()->balance_cents)->toBe(0)
        ->and($empty->fresh()->balance_cents)->toBe(0);
});

it('does nothing and succeeds while another run holds the lock', function () {
    Merchant::factory()->create(['balance_cents' => 5_000]);
    $lock = Cache::lock('payouts:run', 600);
    $lock->get();

    $this->artisan('payouts:run')->assertSuccessful();

    expect(Payout::count())->toBe(0)
        ->and(Merchant::first()->balance_cents)->toBe(5_000);
});

it('releases the lock when it is done', function () {
    Merchant::factory()->create();

    $this->artisan('payouts:run')->assertSuccessful();

    $lock = Cache::lock('payouts:run', 600);
    expect($lock->get())->toBeTrue();
});

<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\Payout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RunPayouts extends Command
{
    protected $signature = 'payouts:run';

    protected $description = 'Pay out every merchant balance';

    public function handle(): int
    {
        $lock = Cache::lock('payouts:run', 600);

        if (! $lock->get()) {
            $this->warn('A payouts run is already in progress.');

            return self::SUCCESS;
        }

        try {
            $count = 0;

            Merchant::query()->where('balance_cents', '>', 0)->each(function (Merchant $merchant) use (&$count) {
                DB::transaction(function () use ($merchant) {
                    Payout::create([
                        'merchant_id' => $merchant->id,
                        'amount_cents' => $merchant->balance_cents,
                        'paid_at' => now(),
                    ]);
                    $merchant->update(['balance_cents' => 0]);
                });
                $count++;
            });

            $this->info("Paid {$count} merchants.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}

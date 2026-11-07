<?php

namespace App\Console\Commands;

use App\Jobs\SyncSubscriberToCrm;
use App\Models\Subscriber;
use Illuminate\Console\Command;

class DispatchCrmSync extends Command
{
    protected $signature = 'crm:dispatch-sync';

    protected $description = 'Queue CRM syncs for subscribers that are not in the CRM yet';

    public function handle(): int
    {
        $count = 0;

        Subscriber::query()->whereNull('crm_synced_at')->each(function (Subscriber $subscriber) use (&$count) {
            SyncSubscriberToCrm::dispatch($subscriber);
            $subscriber->update(['crm_sync_queued_at' => now()]);
            $count++;
        });

        $this->info("Queued {$count} subscribers.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use Illuminate\Console\Command;

class NormalizeSubscriberEmails extends Command
{
    protected $signature = 'subscribers:normalize-emails';

    protected $description = 'Lower-case and trim subscriber emails';

    public function handle(): int
    {
        Subscriber::query()->where('email_normalized', false)->chunk(100, function ($subscribers) {
            foreach ($subscribers as $subscriber) {
                $subscriber->update([
                    'email' => strtolower(trim($subscriber->email)),
                    'email_normalized' => true,
                ]);
            }
        });

        return self::SUCCESS;
    }
}

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crm:dispatch-sync')->everyMinute()->withoutOverlapping();
Schedule::command('subscribers:normalize-emails')->dailyAt('01:00');

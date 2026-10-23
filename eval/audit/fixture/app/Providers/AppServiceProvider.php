<?php

namespace App\Providers;

use App\Support\AllowedHosts;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AllowedHosts::class, fn () => new AllowedHosts(config('hosts.allowed', [])));
    }

    public function boot(): void
    {
        //
    }
}

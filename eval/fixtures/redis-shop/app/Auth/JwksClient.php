<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class JwksClient
{
    /**
     * The identity provider's signing keys, each with a `kid` and a `secret`.
     *
     * @return list<array{kid: string, secret: string}>
     */
    public function keys(): array
    {
        return Cache::remember('idp:jwks', now()->addHour(), function (): array {
            return Http::timeout(3)
                ->get(config('services.idp.jwks_url'))
                ->throw()
                ->json('keys');
        });
    }
}

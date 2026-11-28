<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;

class TokenVerifier
{
    public function __construct(private JwksClient $jwks) {}

    public function check(string $token): bool
    {
        return Cache::remember('token-check:'.hash('sha256', $token), 60, fn (): bool => $this->verify($token));
    }

    private function verify(string $token): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        [$kid, $payload, $signature] = $parts;
        $key = collect($this->jwks->keys())->firstWhere('kid', $kid);

        if ($key === null) {
            return false;
        }

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$kid.$payload", $key['secret'], true)), '+/', '-_'), '=');

        return hash_equals($expected, $signature);
    }
}

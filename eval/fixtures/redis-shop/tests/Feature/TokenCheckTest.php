<?php

use App\Auth\JwksClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function signedToken(string $kid, string $secret, string $payload = 'eyJzdWIiOiIxIn0'): string
{
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "$kid.$payload", $secret, true)), '+/', '-_'), '=');

    return "$kid.$payload.$signature";
}

beforeEach(function () {
    Cache::flush();
    Http::fake(['idp.example.test/*' => Http::response(['keys' => [['kid' => 'k1', 'secret' => 'top-secret']]])]);
});

it('accepts a token signed with a published key', function () {
    $this->postJson('/api/token-checks', ['token' => signedToken('k1', 'top-secret')])
        ->assertOk()
        ->assertExactJson(['valid' => true]);
});

it('rejects a token with a wrong signature, an unknown key or a bad shape', function (string $token) {
    $this->postJson('/api/token-checks', ['token' => $token])
        ->assertOk()
        ->assertExactJson(['valid' => false]);
})->with([
    'wrong secret' => fn () => signedToken('k1', 'other-secret'),
    'unknown kid' => fn () => signedToken('k9', 'top-secret'),
    'not three parts' => 'abc.def',
]);

it('requires a token', function () {
    $this->postJson('/api/token-checks', [])->assertUnprocessable();
});

it('fetches the keys once and caches them', function () {
    $this->postJson('/api/token-checks', ['token' => signedToken('k1', 'top-secret', 'one')]);
    $this->postJson('/api/token-checks', ['token' => signedToken('k1', 'top-secret', 'two')]);

    Http::assertSentCount(1);
});

it('caches the result for a token', function () {
    $token = signedToken('k1', 'top-secret');

    $this->postJson('/api/token-checks', ['token' => $token]);
    $this->postJson('/api/token-checks', ['token' => $token]);

    expect(Cache::has('token-check:'.hash('sha256', $token)))->toBeTrue();
});

it('returns the keys array from the client', function () {
    expect(app(JwksClient::class)->keys())->toBe([['kid' => 'k1', 'secret' => 'top-secret']]);
});

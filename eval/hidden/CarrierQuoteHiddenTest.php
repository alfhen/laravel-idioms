<?php

namespace Tests\Hidden;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CarrierQuoteHiddenTest extends TestCase
{
    private array $timeouts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.carrier.base_url' => 'https://carrier.example.test',
            'services.carrier.key' => 'secret-key',
            'services.carrier.fallback_cents' => 995,
        ]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    /** Fakes the carrier with a sequence of responses (callables or Http responses), recording each attempt's timeout. */
    private function carrier(array $responses): void
    {
        $i = 0;
        Http::fake(function (Request $request, array $options) use (&$i, $responses) {
            if (! str_starts_with($request->url(), 'https://carrier.example.test/')) {
                return null;
            }
            $this->timeouts[] = $options['timeout'] ?? null;
            $response = $responses[min($i, count($responses) - 1)];
            $i++;

            return is_callable($response) ? $response() : $response;
        });
    }

    private function rates(): array
    {
        return ['rates' => [['service' => 'express', 'price_cents' => 1490], ['service' => 'standard', 'price_cents' => 690]]];
    }

    private function fallback(): array
    {
        return ['estimated' => true, 'rates' => [['service' => 'standard', 'price_cents' => 995]]];
    }

    private function attempts(): int
    {
        return count($this->timeouts);
    }

    public function test_it_returns_the_carriers_rates(): void
    {
        $this->carrier([Http::response($this->rates())]);

        $response = $this->getJson('/api/shipping/quotes?postcode=8000');

        $response->assertOk()->assertExactJson(['estimated' => false, 'rates' => $this->rates()['rates']]);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secret-key')
            && str_starts_with($r->url(), 'https://carrier.example.test/v2/rates')
            && ($r->data()['postcode'] ?? null) === '8000');
    }

    public function test_an_unknown_postcode_is_a_422_after_one_call(): void
    {
        $this->carrier([Http::response(['message' => 'unknown postcode'], 404)]);

        $response = $this->getJson('/api/shipping/quotes?postcode=9999');

        $response->assertStatus(422);
        $this->assertIsString($response->json('message'));
        $this->assertSame(1, $this->attempts());
    }

    public function test_transient_errors_are_retried(): void
    {
        $this->carrier([Http::response('', 500), Http::response('', 502), Http::response($this->rates())]);

        $response = $this->getJson('/api/shipping/quotes?postcode=8000');

        $response->assertOk()->assertJsonPath('estimated', false);
        $this->assertSame(3, $this->attempts());
    }

    public function test_a_carrier_that_keeps_failing_gets_the_fallback_after_at_most_3_calls(): void
    {
        $this->carrier([Http::response('<html>Bad gateway</html>', 503)]);

        $response = $this->getJson('/api/shipping/quotes?postcode=8000');

        $response->assertOk()->assertExactJson($this->fallback());
        $this->assertGreaterThanOrEqual(2, $this->attempts(), 'a transient failure was not retried');
        $this->assertLessThanOrEqual(3, $this->attempts());
    }

    public function test_a_carrier_that_does_not_answer_gets_the_fallback(): void
    {
        $this->carrier([fn () => Http::failedConnection()]);

        $response = $this->getJson('/api/shipping/quotes?postcode=8000');

        $response->assertOk()->assertExactJson($this->fallback());
        $this->assertLessThanOrEqual(3, $this->attempts());
    }

    public function test_every_attempt_has_a_timeout_that_fits_the_5_second_budget(): void
    {
        $this->carrier([fn () => Http::failedConnection()]);

        $this->getJson('/api/shipping/quotes?postcode=8000')->assertOk();

        $this->assertNotEmpty($this->timeouts);
        foreach ($this->timeouts as $timeout) {
            $this->assertNotNull($timeout);
            $this->assertGreaterThan(0, $timeout, 'timeout 0 waits forever');
        }
        $this->assertLessThanOrEqual(5, array_sum($this->timeouts), 'attempts x timeout exceeds 5 seconds');
    }

    public function test_a_rejected_key_is_not_retried_and_gets_the_fallback(): void
    {
        $this->carrier([Http::response(['message' => 'Unauthenticated'], 401)]);

        $response = $this->getJson('/api/shipping/quotes?postcode=8000');

        $response->assertOk()->assertExactJson($this->fallback());
        $this->assertSame(1, $this->attempts());
    }

    public function test_an_invalid_postcode_never_reaches_the_carrier(): void
    {
        $this->carrier([Http::response($this->rates())]);

        $this->getJson('/api/shipping/quotes?postcode=80A0')->assertStatus(422);
        $this->getJson('/api/shipping/quotes')->assertStatus(422);
        $this->assertSame(0, $this->attempts());
    }
}

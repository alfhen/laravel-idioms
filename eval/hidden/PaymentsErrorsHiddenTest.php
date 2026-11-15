<?php

namespace Tests\Hidden;

use App\Contracts\PaymentGateway;
use App\Exceptions\CardDeclined;
use App\Exceptions\GatewayUnavailable;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use JsonSerializable;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use TypeError;

class PaymentsErrorsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const CARD_TOKEN = 'tok_CARDq8Vn42';

    private const ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    /** @var list<MessageLogged> */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Sanctum::actingAs(User::factory()->create(), ['*']);
        Event::listen(MessageLogged::class, function (MessageLogged $record) {
            $this->records[] = $record;
        });
    }

    private function gatewayDoes(Closure $charge): object
    {
        $gateway = new class($charge) implements PaymentGateway
        {
            public array $calls = [];

            public function __construct(private Closure $behaviour) {}

            public function charge(int $amount, string $currency, string $cardToken): string
            {
                $this->calls[] = [$amount, $currency, $cardToken];

                return ($this->behaviour)();
            }
        };
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    private function pay()
    {
        $this->records = [];

        return $this->postJson('/api/payments', ['amount' => 1999, 'currency' => 'EUR', 'card_token' => self::CARD_TOKEN]);
    }

    private static function chainContains(mixed $value, string $class): bool
    {
        for ($e = $value; $e instanceof Throwable; $e = $e->getPrevious()) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** Log records with the exception object (or a wrapper whose previous chain holds it) in their context. */
    private function recordsCarrying(string $class): array
    {
        return array_values(array_filter(
            $this->records,
            fn (MessageLogged $r) => collect($r->context)->contains(fn ($v) => self::chainContains($v, $class)),
        ));
    }

    private function errorRecords(): array
    {
        return array_values(array_filter($this->records, fn (MessageLogged $r) => in_array($r->level, self::ERROR_LEVELS, true)));
    }

    private static function flatten(mixed $value, int $depth = 0): string
    {
        if ($depth > 6) {
            return '';
        }
        if ($value instanceof Throwable) {
            $text = '';
            for ($e = $value; $e; $e = $e->getPrevious()) {
                $text .= get_class($e).': '.$e->getMessage().' ';
                if (method_exists($e, 'context')) {
                    $text .= self::flatten($e->context(), $depth + 1);
                }
            }

            return $text;
        }
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (is_array($value)) {
            return implode(' ', array_keys($value)).' '.implode(' ', array_map(fn ($v) => self::flatten($v, $depth + 1), $value));
        }
        if (is_object($value)) {
            return get_class($value).' '.json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    public function test_a_successful_charge_answers_201_with_the_reference(): void
    {
        $gateway = $this->gatewayDoes(fn () => 'ref_OK123');

        $response = $this->pay();

        $response->assertCreated()->assertJson(['reference' => 'ref_OK123']);
        $this->assertSame([[1999, 'EUR', self::CARD_TOKEN]], $gateway->calls);
    }

    public function test_a_declined_card_answers_402_with_card_declined(): void
    {
        $this->gatewayDoes(fn () => throw new CardDeclined('Card declined by issuer'));

        $this->pay()->assertStatus(402)->assertJson(['error' => 'card_declined']);
    }

    public function test_a_declined_card_logs_nothing_at_error_level_or_above(): void
    {
        $this->gatewayDoes(fn () => throw new CardDeclined('Card declined by issuer'));

        $this->pay()->assertStatus(402);

        $this->assertSame([], array_map(fn ($r) => "{$r->level}: {$r->message}", $this->errorRecords()));
    }

    public function test_a_gateway_outage_answers_503_with_gateway_unavailable(): void
    {
        $this->gatewayDoes(fn () => throw new GatewayUnavailable('Gateway timed out after 10s'));

        $this->pay()->assertStatus(503)->assertJson(['error' => 'gateway_unavailable']);
    }

    public function test_a_gateway_outage_is_logged_exactly_once_with_the_exception(): void
    {
        $this->gatewayDoes(fn () => throw new GatewayUnavailable('Gateway timed out after 10s'));

        $this->pay()->assertStatus(503);

        $this->assertCount(1, $this->recordsCarrying(GatewayUnavailable::class), 'log records: '.json_encode(array_map(fn ($r) => "{$r->level}: {$r->message}", $this->records)));
    }

    public function test_a_gateway_outage_is_logged_at_warning(): void
    {
        $this->gatewayDoes(fn () => throw new GatewayUnavailable('Gateway timed out after 10s'));

        $this->pay()->assertStatus(503);

        $carrying = $this->recordsCarrying(GatewayUnavailable::class);
        $this->assertNotEmpty($carrying, 'no log record carries the GatewayUnavailable exception');
        $this->assertSame(['warning'], array_values(array_unique(array_map(fn ($r) => $r->level, $carrying))));
        $this->assertSame([], $this->errorRecords());
    }

    public function test_an_unexpected_type_error_is_a_server_error_without_its_message(): void
    {
        $this->gatewayDoes(fn () => throw new TypeError('LEAKt7Hq internal detail'));

        $response = $this->pay();

        $this->assertContains($response->status(), [500, 503]);
        $this->assertStringNotContainsString('LEAKt7Hq', $response->getContent());
    }

    public function test_an_unexpected_type_error_is_logged_once_as_an_error_with_the_exception(): void
    {
        $this->gatewayDoes(fn () => throw new TypeError('LEAKt7Hq internal detail'));

        $this->pay();

        $carrying = $this->recordsCarrying(TypeError::class);
        $this->assertCount(1, $carrying, 'log records: '.json_encode(array_map(fn ($r) => "{$r->level}: {$r->message}", $this->records)));
        $this->assertContains($carrying[0]->level, self::ERROR_LEVELS);
    }

    public function test_an_unexpected_exception_is_a_server_error_without_its_message(): void
    {
        $this->gatewayDoes(fn () => throw new RuntimeException('LEAKr2Mw internal detail'));

        $response = $this->pay();

        $this->assertContains($response->status(), [500, 503]);
        $this->assertStringNotContainsString('LEAKr2Mw', $response->getContent());
    }

    public function test_an_unexpected_exception_is_logged_once_as_an_error_with_the_exception(): void
    {
        $this->gatewayDoes(fn () => throw new RuntimeException('LEAKr2Mw internal detail'));

        $this->pay();

        $carrying = $this->recordsCarrying(RuntimeException::class);
        $this->assertCount(1, $carrying, 'log records: '.json_encode(array_map(fn ($r) => "{$r->level}: {$r->message}", $this->records)));
        $this->assertContains($carrying[0]->level, self::ERROR_LEVELS);
    }

    public function test_the_card_token_never_reaches_the_logs(): void
    {
        $seen = [];
        foreach ([
            fn () => 'ref_OK123',
            fn () => throw new CardDeclined('Card declined by issuer'),
            fn () => throw new GatewayUnavailable('Gateway timed out after 10s'),
            fn () => throw new TypeError('Unexpected gateway payload'),
        ] as $behaviour) {
            $this->gatewayDoes($behaviour);
            $this->pay();
            array_push($seen, ...$this->records);
        }

        foreach ($seen as $record) {
            $this->assertStringNotContainsString(self::CARD_TOKEN, $record->message.' '.self::flatten($record->context));
        }
    }
}

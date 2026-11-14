<?php

namespace Tests\Hidden;

use App\Contracts\PartnerApi;
use App\Exceptions\PartnerDown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Throwable;
use TypeError;

class PartnerSyncHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['sqlite'];

    /** @var list<MessageLogged> */
    private array $records = [];

    private object $partner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->travelTo(now()->startOfMinute()->addSeconds(5));
        Event::listen(MessageLogged::class, function (MessageLogged $record): void {
            $this->records[] = $record;
        });
        $this->partner = new class implements PartnerApi
        {
            public array $failures = [];

            public array $attempted = [];

            public array $pushed = [];

            public function push(string $id): void
            {
                $this->attempted[] = $id;
                if (isset($this->failures[$id])) {
                    throw ($this->failures[$id])();
                }
                $this->pushed[] = $id;
            }
        };
        $this->app->instance(PartnerApi::class, $this->partner);
    }

    /** @param  array<string, callable(): Throwable>  $failures */
    private function partner(array $failures = []): object
    {
        $this->partner->failures = $failures;

        return $this->partner;
    }

    private function sync(array $ids): int
    {
        try {
            return Artisan::call('partner:sync', ['ids' => $ids]);
        } catch (Throwable) {
            return -1;
        }
    }

    private function carrying(string $class): int
    {
        return collect($this->records)->filter(function (MessageLogged $record) use ($class) {
            for ($e = $record->context['exception'] ?? null; $e instanceof Throwable; $e = $e->getPrevious()) {
                if ($e instanceof $class) {
                    return true;
                }
            }

            return false;
        })->count();
    }

    private function outage(int $count, string $prefix = 'p'): array
    {
        $ids = array_map(fn ($i) => "{$prefix}{$i}", range(1, $count));
        $this->partner(array_fill_keys($ids, fn () => new PartnerDown('partner unavailable')));

        return $ids;
    }

    public function test_every_id_is_pushed_and_the_run_succeeds_when_the_partner_is_up(): void
    {
        $partner = $this->partner();

        $exitCode = $this->sync(['p1', 'p2', 'p3']);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['p1', 'p2', 'p3'], $partner->pushed);
    }

    public function test_an_outage_attempts_every_id_and_fails_the_run(): void
    {
        $ids = $this->outage(20);

        $exitCode = $this->sync($ids);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame($ids, $this->partner->attempted);
    }

    public function test_an_outage_is_reported_with_the_exception_but_at_most_three_times_a_minute(): void
    {
        $this->sync($this->outage(20));

        $reported = $this->carrying(PartnerDown::class);
        $this->assertGreaterThanOrEqual(1, $reported, 'the outage never reached the logs with its exception');
        $this->assertLessThanOrEqual(3, $reported, "the outage was reported {$reported} times in one minute");
    }

    public function test_the_outage_is_reported_again_after_a_minute(): void
    {
        $this->sync($this->outage(20));
        $before = $this->carrying(PartnerDown::class);

        $this->travel(61)->seconds();
        $this->sync($this->outage(5, 'q'));

        $this->assertSame(array_merge(array_map(fn ($i) => "p{$i}", range(1, 20)), ['q1', 'q2', 'q3', 'q4', 'q5']), $this->partner->attempted);
        $this->assertGreaterThan($before, $this->carrying(PartnerDown::class));
    }

    public function test_an_unexpected_error_for_one_id_is_reported_and_the_rest_still_sync(): void
    {
        $partner = $this->partner(['p2' => fn () => new TypeError('unexpected payload shape')]);

        $exitCode = $this->sync(['p1', 'p2', 'p3', 'p4']);

        $this->assertSame(['p1', 'p3', 'p4'], $partner->pushed);
        $this->assertNotSame(0, $exitCode);
        $this->assertGreaterThanOrEqual(1, $this->carrying(TypeError::class));
    }

    public function test_up_succeeds_while_the_database_is_reachable(): void
    {
        $this->get('/up')->assertSuccessful();
    }

    public function test_up_fails_when_the_database_is_unreachable(): void
    {
        $this->breakDatabase();

        $this->get('/up')->assertServerError();
    }

    public function test_live_does_not_depend_on_the_database(): void
    {
        $this->breakDatabase();

        $this->get('/live')->assertSuccessful();
    }

    private function breakDatabase(): void
    {
        config([
            'database.connections.broken' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent-'.uniqid().'/missing.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'broken',
        ]);
    }
}

<?php

namespace Tests\Hidden;

use App\Models\Export;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportPurgeCommandHiddenTest extends TestCase
{
    use RefreshDatabase;

    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = Storage::fake('exports');
    }

    /** Inserts $count exports created $daysOld days ago, each with a file; returns their ids. */
    private function exports(int $count, int $daysOld, string $prefix = 'export'): array
    {
        $user = User::factory()->create();
        $at = now()->subDays($daysOld)->subHour();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $path = "{$prefix}-{$daysOld}-{$i}.csv";
            $this->disk->put($path, 'id,name');
            $rows[] = ['user_id' => $user->id, 'path' => $path, 'created_at' => $at, 'updated_at' => $at];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('exports')->insert($chunk);
        }

        return Export::query()->where('path', 'like', "{$prefix}-{$daysOld}-%")->pluck('id')->all();
    }

    private function inProduction(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_it_removes_every_expired_export_and_its_file_and_keeps_recent_ones(): void
    {
        $this->exports(2500, 40);
        $recent = $this->exports(10, 5);

        $this->artisan('exports:purge', ['--force' => true])->assertExitCode(0);

        $this->assertEqualsCanonicalizing($recent, Export::query()->pluck('id')->all());
        $this->assertCount(10, $this->disk->allFiles());
    }

    public function test_the_retention_period_can_be_changed(): void
    {
        $ten = $this->exports(3, 10);

        $this->artisan('exports:purge', ['--force' => true])->assertExitCode(0);
        $this->assertSame(3, Export::query()->count());

        $this->artisan('exports:purge', ['--days' => 7, '--force' => true])->assertExitCode(0);
        $this->assertSame(0, Export::query()->whereKey($ten)->count());
    }

    public function test_a_dry_run_deletes_nothing_and_reports_the_count(): void
    {
        $this->exports(37, 40);

        $exitCode = Artisan::call('exports:purge', ['--dry-run' => true, '--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('37', Artisan::output());
        $this->assertSame(37, Export::query()->count());
        $this->assertCount(37, $this->disk->allFiles());
    }

    public function test_in_production_it_refuses_to_delete_without_confirmation_when_unattended(): void
    {
        $this->exports(5, 40);
        $this->inProduction();

        $exitCode = Artisan::call('exports:purge', ['--no-interaction' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(5, Export::query()->count());
        $this->assertCount(5, $this->disk->allFiles());
    }

    public function test_in_production_force_deletes_unattended(): void
    {
        $this->exports(5, 40);
        $this->inProduction();

        $exitCode = Artisan::call('exports:purge', ['--force' => true, '--no-interaction' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, Export::query()->count());
    }

    public function test_a_file_that_cannot_be_deleted_keeps_its_row_does_not_stop_the_rest_and_fails_the_run(): void
    {
        $this->exports(4, 40);
        $broken = $this->exports(1, 40, 'locked');
        $fake = $this->disk;
        Storage::set('exports', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths)
            {
                $paths = is_array($paths) ? $paths : func_get_args();
                foreach ($paths as $path) {
                    if (str_starts_with($path, 'locked')) {
                        return false;
                    }
                }

                return parent::delete($paths);
            }
        });

        $exitCode = Artisan::call('exports:purge', ['--force' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertEqualsCanonicalizing($broken, Export::query()->pluck('id')->all());
        $this->assertSame(['locked-40-0.csv'], $fake->allFiles());
    }

    public function test_it_is_scheduled_nightly_at_three_on_one_server_and_the_scheduled_invocation_works_unattended_in_production(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'exports:purge'));
        $this->assertNotNull($event, 'exports:purge is not scheduled');
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertTrue($event->onOneServer, 'it would run on both servers');

        $arguments = trim(substr((string) $event->command, strpos((string) $event->command, 'exports:purge') + strlen('exports:purge')));
        $this->exports(3, 40);
        $this->inProduction();

        $exitCode = Artisan::call(trim('exports:purge '.$arguments.' --no-interaction'));

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, Export::query()->count());
    }
}

<?php

namespace Tests\Hidden;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePatchHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var list<string> */
    private array $postQueries = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        $this->user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'bio' => 'Original bio',
            'phone' => '+45 12 34 56 78',
            'is_admin' => false,
            'team_id' => 7,
        ]);
    }

    private function actingAsUser(): void
    {
        Sanctum::actingAs($this->user, ['*']);
    }

    /** The brief pins an unwrapped object: the profile fields sit at the top level, with no `data` key. */
    private function profile(TestResponse $response): array
    {
        $json = $response->json();
        $this->assertIsArray($json, 'response is not a JSON object');
        $this->assertArrayNotHasKey('data', $json, 'the profile must not be wrapped in a data key');
        $this->assertArrayHasKey('email', $json, 'the profile fields must be at the top level');

        return $json;
    }

    private function freshUser(): User
    {
        return User::query()->findOrFail($this->user->id);
    }

    private function listenForPostQueries(): void
    {
        $this->postQueries = [];
        DB::listen(function ($query): void {
            if (preg_match('/\bposts\b/i', $query->sql)) {
                $this->postQueries[] = $query->sql;
            }
        });
    }

    private function assertUnchanged(User $user): void
    {
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('Original bio', $user->bio);
        $this->assertSame('+45 12 34 56 78', $user->phone);
    }

    private function importFile(mixed $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hidden-profiles').'.json';
        file_put_contents($path, is_string($rows) ? $rows : json_encode($rows));

        return $path;
    }

    /** An uncaught exception is what `php artisan` turns into exit code 1, so it counts as a non-zero exit. */
    private function import(string $path): int
    {
        try {
            return Artisan::call('profiles:import', ['path' => $path]);
        } catch (\Throwable) {
            return 1;
        }
    }

    public function test_guests_cannot_read_or_change_a_profile(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->patchJson('/api/profile', ['name' => 'Guest'])->assertUnauthorized();
        $this->assertUnchanged($this->freshUser());
    }

    public function test_show_returns_the_pinned_profile_shape(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $profile = $this->profile($response);
        $this->assertSame($this->user->id, (int) $profile['id']);
        $this->assertSame('Ada Lovelace', $profile['name']);
        $this->assertSame('ada@example.com', $profile['email']);
        $this->assertSame('Original bio', $profile['bio']);
        $this->assertSame('+45 12 34 56 78', $profile['phone']);
        foreach (['is_admin', 'team_id', 'password', 'remember_token'] as $key) {
            $this->assertArrayNotHasKey($key, $profile);
        }
    }

    public function test_posts_are_neither_returned_nor_queried_unless_included(): void
    {
        Post::factory()->create(['user_id' => $this->user->id, 'title' => 'First post']);
        $this->actingAsUser();
        $this->listenForPostQueries();

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $this->assertArrayNotHasKey('posts', $this->profile($response));
        $this->assertStringNotContainsString('First post', $response->getContent());
        $this->assertSame([], $this->postQueries, 'posts were queried without ?include=posts');
    }

    public function test_include_posts_returns_only_the_users_own_posts(): void
    {
        $other = User::factory()->create();
        Post::factory()->create(['user_id' => $this->user->id, 'title' => 'Mine one']);
        Post::factory()->create(['user_id' => $this->user->id, 'title' => 'Mine two']);
        Post::factory()->create(['user_id' => $other->id, 'title' => 'Not mine']);
        $this->actingAsUser();

        $response = $this->getJson('/api/profile?include=posts');

        $response->assertOk();
        $posts = $this->profile($response)['posts'] ?? null;
        $this->assertIsArray($posts);
        $titles = collect($posts)->pluck('title')->sort()->values()->all();
        $this->assertSame(['Mine one', 'Mine two'], $titles);
        foreach ($posts as $post) {
            $this->assertArrayHasKey('id', $post);
        }
    }

    public function test_patching_only_the_name_leaves_bio_and_phone_unchanged(): void
    {
        $this->actingAsUser();

        $response = $this->patchJson('/api/profile', ['name' => 'Ada King']);

        $response->assertOk();
        $user = $this->freshUser();
        $this->assertSame('Ada King', $user->name);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('Original bio', $user->bio);
        $this->assertSame('+45 12 34 56 78', $user->phone);
        $profile = $this->profile($response);
        $this->assertSame('Ada King', $profile['name']);
        $this->assertSame('Original bio', $profile['bio']);
        $this->assertSame('+45 12 34 56 78', $profile['phone']);
    }

    public function test_an_explicit_null_clears_the_bio_and_leaves_the_rest(): void
    {
        $this->actingAsUser();

        $response = $this->patchJson('/api/profile', ['bio' => null]);

        $response->assertOk();
        $user = $this->freshUser();
        $this->assertNull($user->bio);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('+45 12 34 56 78', $user->phone);
    }

    public function test_patch_ignores_is_admin_team_id_and_id(): void
    {
        $this->actingAsUser();

        $response = $this->patchJson('/api/profile', [
            'name' => 'Ada King',
            'is_admin' => true,
            'team_id' => 99,
            'id' => 12345,
        ]);

        $this->assertContains($response->status(), [200, 422], "answered {$response->status()}");
        $this->assertSame(1, User::query()->count());
        $user = $this->freshUser();
        $this->assertFalse((bool) $user->is_admin);
        $this->assertSame(7, (int) $user->team_id);
        if ($response->status() === 200) {
            $this->assertSame('Ada King', $user->name);
            $profile = $this->profile($response);
            $this->assertArrayNotHasKey('is_admin', $profile);
            $this->assertArrayNotHasKey('team_id', $profile);
        }
    }

    public function test_an_invalid_email_is_rejected_and_changes_nothing(): void
    {
        $this->actingAsUser();

        $response = $this->patchJson('/api/profile', ['name' => 'Ada King', 'email' => 'not-an-email']);

        $response->assertUnprocessable();
        $this->assertUnchanged($this->freshUser());
    }

    public function test_an_over_long_name_is_rejected_and_changes_nothing(): void
    {
        $this->actingAsUser();

        $response = $this->patchJson('/api/profile', ['name' => str_repeat('a', 101), 'bio' => 'New bio']);

        $response->assertUnprocessable();
        $this->assertUnchanged($this->freshUser());
    }

    public function test_a_null_or_non_string_name_is_rejected_not_a_server_error(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/profile', ['name' => null])->assertUnprocessable();
        $this->patchJson('/api/profile', ['name' => ['Ada']])->assertUnprocessable();
        $this->assertUnchanged($this->freshUser());
    }

    public function test_an_email_taken_by_another_user_is_rejected_but_keeping_your_own_is_fine(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $this->actingAsUser();

        $this->patchJson('/api/profile', ['email' => 'taken@example.com'])->assertUnprocessable();
        $this->assertUnchanged($this->freshUser());

        $this->patchJson('/api/profile', ['email' => 'ada@example.com', 'name' => 'Ada King'])->assertOk();
        $this->assertSame('Ada King', $this->freshUser()->name);
    }

    public function test_the_patch_response_does_not_load_posts(): void
    {
        Post::factory()->create(['user_id' => $this->user->id, 'title' => 'First post']);
        $this->actingAsUser();
        $this->listenForPostQueries();

        $response = $this->patchJson('/api/profile', ['name' => 'Ada King']);

        $response->assertOk();
        $this->assertArrayNotHasKey('posts', $this->profile($response));
        $this->assertSame([], $this->postQueries, 'posts were queried by a PATCH');
    }

    public function test_import_creates_valid_rows_and_exits_zero(): void
    {
        $path = $this->importFile([
            ['email' => 'grace@example.com', 'name' => 'Grace Hopper', 'bio' => 'Compilers', 'phone' => '555-0100'],
            ['email' => 'alan@example.com', 'name' => 'Alan Turing'],
        ]);

        $this->assertSame(0, $this->import($path));

        $grace = User::query()->where('email', 'grace@example.com')->sole();
        $this->assertSame('Grace Hopper', $grace->name);
        $this->assertSame('Compilers', $grace->bio);
        $this->assertSame('555-0100', $grace->phone);
        $this->assertSame('Alan Turing', User::query()->where('email', 'alan@example.com')->sole()->name);
    }

    public function test_import_rejects_a_bad_email_row_still_imports_the_rest_and_exits_non_zero(): void
    {
        $path = $this->importFile([
            ['email' => 'grace@example.com', 'name' => 'Grace Hopper'],
            ['email' => 'not-an-email', 'name' => 'Bad Row'],
            ['email' => 'alan@example.com', 'name' => 'Alan Turing'],
        ]);

        $this->assertNotSame(0, $this->import($path));

        $this->assertSame(0, User::query()->where('name', 'Bad Row')->count());
        $this->assertSame(0, User::query()->where('email', 'not-an-email')->count());
        $this->assertSame(1, User::query()->where('email', 'grace@example.com')->count());
        $this->assertSame(1, User::query()->where('email', 'alan@example.com')->count());
        $this->assertSame(3, User::query()->count());
    }

    public function test_import_rejects_wrongly_typed_rows_without_crashing(): void
    {
        $path = $this->importFile([
            ['email' => 'grace@example.com', 'name' => ['Grace']],
            ['email' => 'bob@example.com', 'name' => str_repeat('b', 101)],
            ['email' => 'carol@example.com'],
            ['email' => 'alan@example.com', 'name' => 'Alan Turing'],
        ]);

        $this->assertNotSame(0, $this->import($path));

        $this->assertSame(0, User::query()->whereIn('email', ['grace@example.com', 'bob@example.com', 'carol@example.com'])->count());
        $this->assertSame(1, User::query()->where('email', 'alan@example.com')->count());
    }

    public function test_import_updates_an_existing_user_by_email_and_ignores_privileged_fields(): void
    {
        $path = $this->importFile([
            ['email' => 'ada@example.com', 'name' => 'Ada King', 'is_admin' => true, 'team_id' => 99],
            ['email' => 'grace@example.com', 'name' => 'Grace Hopper', 'is_admin' => true],
        ]);

        $this->assertSame(0, $this->import($path), 'extra columns are not a reason to reject a row');

        $this->assertSame(2, User::query()->count());
        $ada = $this->freshUser();
        $this->assertSame('Ada King', $ada->name);
        $this->assertFalse((bool) $ada->is_admin);
        $this->assertSame(7, (int) $ada->team_id);
        $this->assertFalse((bool) User::query()->where('email', 'grace@example.com')->sole()->is_admin);
    }

    public function test_import_of_a_missing_file_exits_non_zero_and_creates_nothing(): void
    {
        $this->assertNotSame(0, $this->import(sys_get_temp_dir().'/hidden-profiles-does-not-exist.json'));
        $this->assertSame(1, User::query()->count());
    }
}

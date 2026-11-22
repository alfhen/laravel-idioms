<?php

namespace Tests\Hidden;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TeamRolesPageHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('member', 'web');
    }

    private function person(string $name, array $roles): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);
        if ($roles !== []) {
            $user->assignRole(...$roles);
        }

        return $user;
    }

    /** Each call acts like a fresh request: a freshly loaded user, nothing left over from the last one. */
    private function visit(?User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();
        if ($user !== null) {
            $this->actingAs($user->fresh());
        }

        return $this->get('/admin/roles');
    }

    private function text(TestResponse $response): string
    {
        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($response->getContent())));
    }

    private function between(string $text, string $from, string $to): string
    {
        $start = mb_strpos($text, $from);
        $this->assertNotFalse($start, "{$from} is not on the page");
        $start += mb_strlen($from);
        $end = mb_strpos($text, $to, $start);
        $this->assertNotFalse($end, "{$to} does not come after {$from}");

        return mb_substr($text, $start, $end - $start);
    }

    private function queriesFor(User $viewer): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->visit($viewer)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->person('Ada Lovelace', ['admin']);

        $response = $this->visit(null);

        $response->assertRedirect();
        $this->assertStringEndsWith('/login', (string) parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $response->assertDontSee('Ada Lovelace');
    }

    public function test_members_without_the_admin_role_are_forbidden(): void
    {
        $this->person('Ada Lovelace', ['admin']);
        $member = $this->person('Brian Kernighan', ['member']);

        $response = $this->visit($member);

        $this->assertContains($response->status(), [403, 404], "expected 403 or 404, got {$response->status()}");
        $response->assertDontSee('Ada Lovelace');
    }

    public function test_users_without_any_role_are_forbidden(): void
    {
        $this->person('Ada Lovelace', ['admin']);
        $nobody = $this->person('Dennis Ritchie', []);

        $response = $this->visit($nobody);

        $this->assertContains($response->status(), [403, 404], "expected 403 or 404, got {$response->status()}");
        $response->assertDontSee('Ada Lovelace');
    }

    public function test_admins_see_every_user_with_their_role_names(): void
    {
        $this->person('Ada Lovelace', ['admin']);
        $this->person('Brian Kernighan', ['member']);
        $this->person('Carol Shaw', ['admin', 'member']);
        $this->person('Dennis Ritchie', []);
        $viewer = $this->person('Zoe Viewer', ['admin']);

        $response = $this->visit($viewer);

        $response->assertOk();
        $response->assertSeeTextInOrder(['Ada Lovelace', 'Brian Kernighan', 'Carol Shaw', 'Dennis Ritchie', 'Zoe Viewer']);
        $text = $this->text($response);

        $ada = $this->between($text, 'Ada Lovelace', 'Brian Kernighan');
        $this->assertMatchesRegularExpression('/\badmin\b/i', $ada);
        $this->assertDoesNotMatchRegularExpression('/\bmember\b/i', $ada);

        $brian = $this->between($text, 'Brian Kernighan', 'Carol Shaw');
        $this->assertMatchesRegularExpression('/\bmember\b/i', $brian);
        $this->assertDoesNotMatchRegularExpression('/\badmin\b/i', $brian);

        $carol = $this->between($text, 'Carol Shaw', 'Dennis Ritchie');
        $this->assertMatchesRegularExpression('/\badmin\b/i', $carol);
        $this->assertMatchesRegularExpression('/\bmember\b/i', $carol);

        $dennis = $this->between($text, 'Dennis Ritchie', 'Zoe Viewer');
        $this->assertDoesNotMatchRegularExpression('/\b(admin|member)\b/i', $dennis);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_users(): void
    {
        $viewer = $this->person('Zoe Viewer', ['admin']);
        foreach (range(1, 4) as $i) {
            $this->person("Small Team {$i}", [$i % 2 ? 'member' : 'admin']);
        }
        $this->visit($viewer)->assertOk();
        $small = $this->queriesFor($viewer);

        foreach (range(1, 20) as $i) {
            $this->person("Large Team {$i}", $i % 3 ? ['member'] : ['admin', 'member']);
        }
        $large = $this->queriesFor($viewer);

        $this->assertLessThanOrEqual($small + 2, $large, "{$small} queries for 5 users, {$large} for 25");
    }
}

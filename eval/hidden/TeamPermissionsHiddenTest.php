<?php

namespace Tests\Hidden;

use App\Models\Post;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamPermissionsHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function member(Team $team, ?string $role): User
    {
        $user = User::factory()->create();
        $team->addMember($user, $role);

        return $user;
    }

    private function makePost(Team $team): Post
    {
        $author = User::factory()->create();
        $team->addMember($author, null);

        return Post::factory()->create([
            'team_id' => $team->id,
            'user_id' => $author->id,
            'title' => 'Original title',
            'body' => 'Original body',
        ]);
    }

    /** Each call acts like a fresh request from a fresh process: a freshly loaded user, no team context left over. */
    private function send(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(null);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), ['*']);

        return $this->json($method, $uri, $data);
    }

    private function updatePost(User $user, Post $post): TestResponse
    {
        return $this->send($user, 'PATCH', "/api/posts/{$post->id}", ['title' => 'Changed title', 'body' => 'Changed body']);
    }

    private function deletePost(User $user, Post $post): TestResponse
    {
        return $this->send($user, 'DELETE', "/api/posts/{$post->id}");
    }

    private function assertRejected(TestResponse $response): void
    {
        $this->assertContains($response->status(), [403, 404], "expected 403 or 404, got {$response->status()}");
    }

    private function assertUnchanged(Post $post): void
    {
        $row = DB::table('posts')->where('id', $post->id)->first();
        $this->assertNotNull($row, 'the post was deleted');
        $this->assertSame('Original title', $row->title);
        $this->assertSame('Original body', $row->body);
    }

    private function assertGone(Post $post): void
    {
        $this->assertFalse(DB::table('posts')->where('id', $post->id)->exists(), 'the post still exists');
    }

    /** @return array<string, int> */
    private function permissionRowCounts(): array
    {
        $tables = config('permission.table_names');

        return [
            'roles' => DB::table($tables['roles'])->count(),
            'permissions' => DB::table($tables['permissions'])->count(),
            'role_has_permissions' => DB::table($tables['role_has_permissions'])->count(),
        ];
    }

    public function test_an_editor_creates_and_updates_posts_in_their_team(): void
    {
        $team = Team::factory()->create();
        $editor = $this->member($team, 'editor');
        $post = $this->makePost($team);

        $this->send($editor, 'POST', "/api/teams/{$team->id}/posts", ['title' => 'New post', 'body' => 'Hello'])->assertCreated();
        $this->assertTrue(DB::table('posts')->where('team_id', $team->id)->where('user_id', $editor->id)->where('title', 'New post')->exists());

        $this->assertTrue($this->updatePost($editor, $post)->isSuccessful());
        $this->assertSame('Changed title', DB::table('posts')->where('id', $post->id)->value('title'));
    }

    public function test_an_editor_cannot_delete_posts(): void
    {
        $team = Team::factory()->create();
        $editor = $this->member($team, 'editor');
        $post = $this->makePost($team);

        $this->assertRejected($this->deletePost($editor, $post));
        $this->assertUnchanged($post);
    }

    public function test_an_editor_cannot_update_or_delete_another_teams_post(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $editor = $this->member($teamA, 'editor');
        $post = $this->makePost($teamB);

        $this->assertRejected($this->updatePost($editor, $post));
        $this->assertRejected($this->deletePost($editor, $post));
        $this->assertUnchanged($post);
    }

    public function test_an_editor_in_one_team_who_is_a_plain_member_of_another_cannot_write_there(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $user = $this->member($teamA, 'editor');
        $teamB->addMember($user, null);
        $post = $this->makePost($teamB);

        $this->assertSame(200, $this->send($user, 'GET', "/api/teams/{$teamB->id}/posts")->status());
        $this->assertRejected($this->updatePost($user, $post));
        $this->assertRejected($this->send($user, 'POST', "/api/teams/{$teamB->id}/posts", ['title' => 'Sneaky', 'body' => 'x']));
        $this->assertUnchanged($post);
        $this->assertFalse(DB::table('posts')->where('title', 'Sneaky')->exists());
    }

    public function test_a_member_without_a_role_can_read_but_not_write(): void
    {
        $team = Team::factory()->create();
        $member = $this->member($team, null);
        $post = $this->makePost($team);

        $this->assertSame(200, $this->send($member, 'GET', "/api/teams/{$team->id}/posts")->status());
        $this->assertRejected($this->send($member, 'POST', "/api/teams/{$team->id}/posts", ['title' => 'Nope', 'body' => 'x']));
        $this->assertRejected($this->updatePost($member, $post));
        $this->assertRejected($this->deletePost($member, $post));
        $this->assertUnchanged($post);
        $this->assertFalse(DB::table('posts')->where('title', 'Nope')->exists());
    }

    public function test_a_user_outside_the_team_cannot_read_or_write(): void
    {
        $team = Team::factory()->create();
        $outsider = User::factory()->create();
        $post = $this->makePost($team);

        $this->assertRejected($this->send($outsider, 'GET', "/api/teams/{$team->id}/posts"));
        $this->assertRejected($this->updatePost($outsider, $post));
        $this->assertRejected($this->deletePost($outsider, $post));
        $this->assertUnchanged($post);
    }

    public function test_an_admin_updates_and_deletes_in_their_own_team(): void
    {
        $team = Team::factory()->create();
        $admin = $this->member($team, 'admin');
        $toUpdate = $this->makePost($team);
        $toDelete = $this->makePost($team);

        $this->assertTrue($this->updatePost($admin, $toUpdate)->isSuccessful());
        $this->assertSame('Changed title', DB::table('posts')->where('id', $toUpdate->id)->value('title'));

        $this->assertTrue($this->deletePost($admin, $toDelete)->isSuccessful());
        $this->assertGone($toDelete);
    }

    public function test_an_admin_cannot_update_or_delete_another_teams_post(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $admin = $this->member($teamA, 'admin');
        $post = $this->makePost($teamB);

        $this->assertRejected($this->updatePost($admin, $post));
        $this->assertRejected($this->deletePost($admin, $post));
        $this->assertUnchanged($post);
    }

    public function test_running_the_seeder_again_neither_fails_nor_duplicates(): void
    {
        $before = $this->permissionRowCounts();
        $this->assertGreaterThan(0, $before['roles']);
        $this->assertGreaterThan(0, $before['permissions']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($before, $this->permissionRowCounts());

        $team = Team::factory()->create();
        $admin = $this->member($team, 'admin');
        $post = $this->makePost($team);
        $this->assertTrue($this->deletePost($admin, $post)->isSuccessful());
        $this->assertGone($post);
    }

    public function test_a_permission_granted_to_a_role_applies_on_the_next_request(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $editor = $this->member($team, 'editor');
        $this->member($team, 'admin');
        $post = $this->makePost($team);
        $otherPost = $this->makePost($other);

        $this->assertRejected($this->deletePost($editor, $post));
        $this->assertUnchanged($post);

        // Ops grants the editor role everything the admin role may do, using the package's own methods.
        $roleClass = config('permission.models.role');
        $adminPermissions = $roleClass::query()->where('name', 'admin')->get()
            ->flatMap(fn ($role) => $role->permissions)->unique('id')->values();
        $this->assertNotEmpty($adminPermissions, 'the admin role has no permissions');
        foreach ($roleClass::query()->where('name', 'editor')->get() as $role) {
            $role->givePermissionTo($adminPermissions);
        }

        $this->assertTrue($this->deletePost($editor, $post)->isSuccessful());
        $this->assertGone($post);

        $this->assertRejected($this->deletePost($editor, $otherPost));
        $this->assertUnchanged($otherPost);
    }
}

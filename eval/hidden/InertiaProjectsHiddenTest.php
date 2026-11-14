<?php

namespace Tests\Hidden;

use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InertiaProjectsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_SECRETS = ['INTERNALNOTE5512', '98765431'];

    private const ACCOUNT_SECRETS = ['TFASECRET7731', 'RECOVERYCODES4419', 'CUSSENTINEL9902'];

    private const INERTIA_FORM = [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];

    private int $auditQueries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'inertia.testing.ensure_pages_exist' => false]);
    }

    private function owner(): User
    {
        return User::factory()->create([
            'name' => 'Olive Owner',
            'two_factor_secret' => 'TFASECRET7731',
            'two_factor_recovery_codes' => 'RECOVERYCODES4419',
            'stripe_customer_id' => 'CUSSENTINEL9902',
        ]);
    }

    private function projectFor(User $user, array $attributes = []): Project
    {
        return Project::factory()->create([
            'user_id' => $user->id,
            'title' => 'Bridge Repaint',
            'description' => 'Repaint the north bridge',
            'status' => 'active',
            'internal_notes' => 'INTERNALNOTE5512',
            'internal_cost_cents' => 98765431,
            ...$attributes,
        ]);
    }

    private function seedActivity(Project $project, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            AuditEntry::factory()->create([
                'project_id' => $project->id,
                'message' => "entry-{$i}",
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }
    }

    private function countAuditQueries(): void
    {
        $this->auditQueries = 0;
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'audit_entries')) {
                $this->auditQueries++;
            }
        });
    }

    private function assertNoSecrets(string $content, array $secrets = self::PROJECT_SECRETS): void
    {
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $content, "Secret {$secret} reached the browser");
        }
    }

    private function reload(User $user, Project $project, string $only): array
    {
        $response = $this->actingAs($user)->get(route('projects.show', $project))->assertOk();
        $props = null;
        $response->assertInertia(function (AssertableInertia $page) use ($only, &$props) {
            $page->reloadOnly($only, function (AssertableInertia $reload) use (&$props) {
                $props = $reload->toArray()['props'];
            });
        });

        return $props;
    }

    public function test_index_lists_only_the_users_own_projects(): void
    {
        $owner = $this->owner();
        $mine = Project::factory()->count(3)->create(['user_id' => $owner->id]);
        Project::factory()->count(2)->create();

        $response = $this->actingAs($owner)->get(route('projects.index'))->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Index', false));
        $ids = collect($response->inertiaProps('projects.data'))->pluck('id')->sort()->values()->all();
        $this->assertSame($mine->pluck('id')->sort()->values()->all(), $ids);
    }

    public function test_index_items_carry_id_title_and_status(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);

        $item = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->inertiaProps('projects.data.0');

        $this->assertSame($project->id, $item['id']);
        $this->assertSame('Bridge Repaint', $item['title']);
        $this->assertSame('active', $item['status']);
    }

    public function test_index_page_never_carries_internal_project_fields(): void
    {
        $owner = $this->owner();
        $this->projectFor($owner);

        $response = $this->actingAs($owner)->get(route('projects.index'))->assertOk();

        $this->assertNoSecrets($response->getContent());
        $this->assertNoSecrets(json_encode($response->viewData('page')));
    }

    public function test_show_page_never_carries_internal_project_fields(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);
        $this->seedActivity($project, 3);

        $response = $this->actingAs($owner)->get(route('projects.show', $project))->assertOk();

        $this->assertNoSecrets($response->getContent());
        $this->assertNoSecrets(json_encode($response->viewData('page')));
    }

    public function test_inertia_visit_json_never_carries_internal_project_fields(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);
        $version = $this->actingAs($owner)->get(route('projects.show', $project))->inertiaPage()['version'];

        $response = $this->actingAs($owner)->get(route('projects.show', $project), [
            ...self::INERTIA_FORM,
            'X-Inertia-Version' => (string) $version,
        ]);

        $response->assertOk();
        $this->assertSame('Projects/Show', $response->json('component'));
        $this->assertNoSecrets($response->getContent());
    }

    public function test_partial_reload_of_project_never_carries_internal_project_fields(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);

        $props = $this->reload($owner, $project, 'project');

        $this->assertNoSecrets(json_encode($props));
    }

    public function test_no_page_carries_the_users_account_secrets(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);

        foreach ([route('projects.index'), route('projects.show', $project)] as $url) {
            $response = $this->actingAs($owner)->get($url)->assertOk();
            $this->assertNoSecrets($response->getContent(), self::ACCOUNT_SECRETS);
            $this->assertNoSecrets(json_encode($response->viewData('page')), self::ACCOUNT_SECRETS);
        }
    }

    public function test_shared_auth_user_has_only_id_name_and_email(): void
    {
        $owner = $this->owner();
        $this->projectFor($owner);

        $user = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->inertiaProps('auth.user');

        $this->assertIsArray($user);
        $this->assertSame($owner->id, $user['id'] ?? null);
        $this->assertSame([], array_diff(array_keys($user), ['id', 'name', 'email']));
    }

    public function test_guest_pages_share_a_null_auth_user(): void
    {
        $props = $this->get(route('login'))->assertOk()->inertiaPage()['props'];

        $this->assertArrayHasKey('auth', $props);
        $this->assertArrayHasKey('user', $props['auth']);
        $this->assertNull($props['auth']['user']);
    }

    public function test_show_page_reads_project_fields_at_the_top_level(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);

        $response = $this->actingAs($owner)->get(route('projects.show', $project))->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Show', false));
        $this->assertSame($project->id, $response->inertiaProps('project.id'));
        $this->assertSame('Bridge Repaint', $response->inertiaProps('project.title'));
        $this->assertSame('Repaint the north bridge', $response->inertiaProps('project.description'));
        $this->assertSame('active', $response->inertiaProps('project.status'));
    }

    public function test_activity_is_not_queried_when_the_page_opens(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);
        $this->seedActivity($project, 3);

        $this->countAuditQueries();
        $this->actingAs($owner)->get(route('projects.show', $project))->assertOk();

        $this->assertSame(0, $this->auditQueries);
    }

    public function test_activity_is_not_queried_on_a_partial_reload_of_project(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);
        $this->seedActivity($project, 3);

        $response = $this->actingAs($owner)->get(route('projects.show', $project))->assertOk();
        $this->countAuditQueries();
        $props = null;
        $response->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->reloadOnly('project', function (AssertableInertia $reload) use (&$props) {
                $props = $reload->toArray()['props'];
            });
        });

        $this->assertArrayHasKey('project', $props);
        $this->assertSame(0, $this->auditQueries);
    }

    public function test_activity_partial_reload_returns_the_20_newest_entries(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);
        $this->seedActivity($project, 25);
        $this->seedActivity($this->projectFor($owner), 2);

        $activity = $this->reload($owner, $project, 'activity')['activity'];

        $this->assertIsArray($activity);
        $this->assertCount(20, $activity);
        $this->assertSame('entry-0', $activity[0]['message'] ?? null);
        $this->assertSame('entry-19', $activity[19]['message'] ?? null);
        $this->assertArrayHasKey('id', $activity[0]);
        $this->assertArrayHasKey('created_at', $activity[0]);
    }

    public function test_store_creates_the_project_for_the_user_and_redirects_to_it(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner)->post(route('projects.store'), [
            'title' => 'New Roof',
            'description' => 'Replace the roof',
            'status' => 'draft',
            'user_id' => User::factory()->create()->id,
        ], self::INERTIA_FORM);

        $project = Project::query()->where('title', 'New Roof')->sole();
        $this->assertSame($owner->id, $project->user_id);
        $response->assertRedirect(route('projects.show', $project));
    }

    public function test_invalid_store_from_an_inertia_form_redirects_back_with_errors(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner)
            ->from(route('projects.index'))
            ->post(route('projects.store'), ['title' => '', 'status' => 'draft'], self::INERTIA_FORM);

        $this->assertContains($response->status(), [302, 303]);
        $response->assertSessionHasErrors('title');
        $this->assertSame(0, Project::count());
    }

    public function test_invalid_update_from_an_inertia_form_redirects_back_with_errors(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);

        $response = $this->actingAs($owner)
            ->from(route('projects.show', $project))
            ->put(route('projects.update', $project), ['title' => str_repeat('a', 121), 'status' => 'retired'], self::INERTIA_FORM);

        $this->assertContains($response->status(), [302, 303]);
        $response->assertSessionHasErrors(['title', 'status']);
        $this->assertSame('Bridge Repaint', $project->fresh()->title);
    }

    public function test_owner_can_update_and_delete_a_project(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);

        $this->actingAs($owner)
            ->put(route('projects.update', $project), ['title' => 'Bridge Repaint II', 'status' => 'archived'], self::INERTIA_FORM)
            ->assertRedirect(route('projects.show', $project));
        $this->assertSame('Bridge Repaint II', $project->fresh()->title);
        $this->assertSame('archived', $project->fresh()->status);

        $this->actingAs($owner)
            ->delete(route('projects.destroy', $project), [], self::INERTIA_FORM)
            ->assertRedirect(route('projects.index'));
        $this->assertNull(Project::find($project->id));
    }

    public function test_another_users_project_cannot_be_viewed_updated_or_deleted(): void
    {
        $owner = $this->owner();
        $project = $this->projectFor($owner);
        $stranger = User::factory()->create();

        $show = $this->actingAs($stranger)->get(route('projects.show', $project));
        $update = $this->actingAs($stranger)->put(route('projects.update', $project), ['title' => 'Hijacked', 'status' => 'draft'], self::INERTIA_FORM);
        $delete = $this->actingAs($stranger)->delete(route('projects.destroy', $project), [], self::INERTIA_FORM);

        $this->assertContains($show->status(), [403, 404]);
        $this->assertNoSecrets($show->getContent());
        $this->assertContains($update->status(), [403, 404]);
        $this->assertContains($delete->status(), [403, 404]);
        $this->assertSame('Bridge Repaint', $project->fresh()?->title);
    }
}

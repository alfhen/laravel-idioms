<?php

namespace Tests\Hidden;

use App\Models\Note;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotesSearchApiHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE_SENTINELS = ['NOTESECRETq7Zk', 'NOTETOKENw3Xp'];

    private const RELATED_SENTINELS = ['PROJSECRETm9Lb', 'REMEMBERr5Tc'];

    private Team $team;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        $this->team = Team::factory()->create(['name' => 'Alpha']);
        $this->user = User::factory()->create(['team_id' => $this->team->id, 'remember_token' => 'REMEMBERr5Tc']);
        $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Alpha project', 'secret' => 'PROJSECRETm9Lb']);
        Sanctum::actingAs($this->user, ['*']);
    }

    private function note(array $attributes, ?User $author = null, ?Project $project = null): Note
    {
        $author ??= $this->user;
        $project ??= $this->project;

        return Note::factory()->create([
            'team_id' => $project->team_id,
            'project_id' => $project->id,
            'user_id' => $author->id,
            'body' => 'Body text',
            'secret' => null,
            'api_token' => null,
            ...$attributes,
        ]);
    }

    /** Default (newest first) is C, B, A; secret order and api_token order differ from both created_at orders. */
    private function seedOrdering(): void
    {
        $this->note(['title' => 'Note A', 'secret' => 's2', 'api_token' => 'k3', 'created_at' => Carbon::parse('2026-01-01 10:00:00')]);
        $this->note(['title' => 'Note B', 'secret' => 's3', 'api_token' => 'k1', 'created_at' => Carbon::parse('2026-01-02 10:00:00')]);
        $this->note(['title' => 'Note C', 'secret' => 's1', 'api_token' => 'k2', 'created_at' => Carbon::parse('2026-01-03 10:00:00')]);
    }

    private function titles($response): array
    {
        return collect($response->json('data'))->pluck('title')->all();
    }

    private function assertRejectedOrCreatedAtOrder(string $query): void
    {
        $response = $this->get('/api/notes?'.$query);

        $this->assertContains($response->status(), [200, 422], "?{$query} answered {$response->status()}");
        if ($response->status() === 200) {
            $this->assertContains($this->titles($response), [['Note C', 'Note B', 'Note A'], ['Note A', 'Note B', 'Note C']], "?{$query} did not fall back to the created_at order");
        }
    }

    private function assertNoSentinels($response, array $sentinels = self::NOTE_SENTINELS): void
    {
        $body = $response->getContent();
        foreach ($sentinels as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $body);
        }
    }

    public function test_list_is_newest_first_by_default_and_sorts_by_title(): void
    {
        $this->seedOrdering();

        $default = $this->get('/api/notes');
        $default->assertOk();
        $this->assertSame(['Note C', 'Note B', 'Note A'], $this->titles($default));

        $byTitle = $this->get('/api/notes?sort=title&dir=asc');
        $byTitle->assertOk();
        $this->assertSame(['Note A', 'Note B', 'Note C'], $this->titles($byTitle));

        $oldest = $this->get('/api/notes?sort=created_at&dir=asc');
        $oldest->assertOk();
        $this->assertSame(['Note A', 'Note B', 'Note C'], $this->titles($oldest));
    }

    public function test_sorting_by_the_secret_column_is_not_possible(): void
    {
        $this->seedOrdering();

        $this->assertRejectedOrCreatedAtOrder('sort=secret&dir=asc');
        $this->assertRejectedOrCreatedAtOrder('sort=secret&dir=desc');
        $this->assertRejectedOrCreatedAtOrder('sort=notes.secret&dir=asc');
    }

    public function test_sorting_by_the_api_token_column_is_not_possible(): void
    {
        $this->seedOrdering();

        $this->assertRejectedOrCreatedAtOrder('sort=api_token&dir=asc');
        $this->assertRejectedOrCreatedAtOrder('sort=api_token&dir=desc');
    }

    public function test_an_unknown_or_injected_sort_is_never_a_server_error(): void
    {
        $this->seedOrdering();

        $this->assertRejectedOrCreatedAtOrder('sort=notes.nonexistent');
        $this->assertRejectedOrCreatedAtOrder('sort='.urlencode('title; drop table notes--'));
        $this->assertRejectedOrCreatedAtOrder('sort='.urlencode('(select 1)'));
        $this->assertRejectedOrCreatedAtOrder('sort=nonexistent_column');
        $this->assertSame(3, Note::count());
    }

    public function test_an_array_sort_is_never_a_server_error(): void
    {
        $this->seedOrdering();

        $this->assertRejectedOrCreatedAtOrder('sort[]=title');
    }

    public function test_a_bad_direction_is_never_a_server_error(): void
    {
        $this->seedOrdering();

        $this->assertRejectedOrCreatedAtOrder('dir=sideways');
        $this->assertRejectedOrCreatedAtOrder('dir[]=asc');
        $this->assertRejectedOrCreatedAtOrder('sort=created_at&dir='.urlencode('asc, secret'));
    }

    public function test_search_matches_part_of_a_title(): void
    {
        $this->note(['title' => 'Quarterly plan']);
        $this->note(['title' => 'Holiday list']);

        $response = $this->get('/api/notes?q=plan');

        $response->assertOk();
        $this->assertSame(['Quarterly plan'], $this->titles($response));
    }

    public function test_a_percent_sign_in_the_search_is_not_a_wildcard(): void
    {
        $this->note(['title' => '100% sure']);
        $this->note(['title' => '1000 things']);

        $response = $this->get('/api/notes?q='.urlencode('100%'));

        $response->assertOk();
        $this->assertNotContains('1000 things', $this->titles($response));
    }

    public function test_an_underscore_in_the_search_is_not_a_wildcard(): void
    {
        $this->note(['title' => 'a_b']);
        $this->note(['title' => 'axb']);

        $response = $this->get('/api/notes?q=a_b');

        $response->assertOk();
        $this->assertNotContains('axb', $this->titles($response));
    }

    public function test_a_search_for_a_percent_sign_finds_the_literal_title(): void
    {
        $this->note(['title' => '100% sure']);
        $this->note(['title' => '1000 things']);

        $response = $this->get('/api/notes?q='.urlencode('100%'));

        $response->assertOk();
        $this->assertSame(['100% sure'], $this->titles($response));
    }

    public function test_a_search_for_an_underscore_finds_the_literal_title(): void
    {
        $this->note(['title' => 'a_b']);
        $this->note(['title' => 'axb']);

        $response = $this->get('/api/notes?q=a_b');

        $response->assertOk();
        $this->assertSame(['a_b'], $this->titles($response));
    }

    public function test_the_list_shows_only_the_users_team(): void
    {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $outsider = User::factory()->create(['team_id' => $otherTeam->id]);
        $this->note(['title' => 'Ours']);
        $this->note(['title' => 'Theirs'], $outsider, $otherProject);

        $response = $this->get('/api/notes');

        $response->assertOk();
        $this->assertSame(['Ours'], $this->titles($response));
    }

    public function test_creating_a_note_in_another_teams_project_is_refused(): void
    {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this->post('/api/notes', ['project_id' => $otherProject->id, 'title' => 'Sneaky', 'body' => 'x']);

        $this->assertFalse($response->isSuccessful(), "answered {$response->status()}");
        $this->assertLessThan(500, $response->status());
        $this->assertSame(0, Note::count());
    }

    public function test_team_and_author_come_from_the_user_not_the_body(): void
    {
        $otherTeam = Team::factory()->create();
        $outsider = User::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this->post('/api/notes', [
            'project_id' => $this->project->id,
            'title' => 'Mine',
            'body' => 'Hello',
            'team_id' => $otherTeam->id,
            'user_id' => $outsider->id,
        ]);

        $response->assertCreated();
        $note = Note::query()->where('title', 'Mine')->sole();
        $this->assertSame($this->team->id, (int) $note->team_id);
        $this->assertSame($this->user->id, (int) $note->user_id);
        $this->assertSame($this->project->id, (int) $note->project_id);
    }

    public function test_another_teams_note_cannot_be_read(): void
    {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $outsider = User::factory()->create(['team_id' => $otherTeam->id]);
        $theirs = $this->note(['title' => 'Theirs'], $outsider, $otherProject);

        $response = $this->get("/api/notes/{$theirs->id}");

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('Theirs', $response->getContent());
    }

    public function test_the_list_never_returns_internal_columns(): void
    {
        $this->note(['title' => 'Listed', 'secret' => 'NOTESECRETq7Zk', 'api_token' => 'NOTETOKENw3Xp']);

        $response = $this->get('/api/notes');

        $response->assertOk();
        $this->assertSame(['Listed'], $this->titles($response));
        $this->assertNoSentinels($response);
    }

    public function test_showing_a_note_never_returns_internal_columns(): void
    {
        $note = $this->note(['title' => 'Shown', 'secret' => 'NOTESECRETq7Zk', 'api_token' => 'NOTETOKENw3Xp']);

        $response = $this->get("/api/notes/{$note->id}");

        $response->assertOk();
        $this->assertStringContainsString('Shown', $response->getContent());
        $this->assertNoSentinels($response);
    }

    public function test_creating_a_note_never_returns_the_projects_or_authors_internal_columns(): void
    {
        $response = $this->post('/api/notes', ['project_id' => $this->project->id, 'title' => 'Fresh', 'body' => 'Hello']);

        $response->assertCreated();
        $this->assertStringContainsString('Fresh', $response->getContent());
        $this->assertNoSentinels($response, self::RELATED_SENTINELS);
    }

    public function test_responses_never_include_the_projects_or_authors_internal_columns(): void
    {
        $note = $this->note(['title' => 'Related']);

        $list = $this->get('/api/notes');
        $show = $this->get("/api/notes/{$note->id}");

        $list->assertOk();
        $show->assertOk();
        $this->assertStringContainsString('Alpha project', $list->getContent().$show->getContent());
        $this->assertNoSentinels($list, self::RELATED_SENTINELS);
        $this->assertNoSentinels($show, self::RELATED_SENTINELS);
    }
}

<?php

namespace Tests\Hidden;

use App\Models\Comment;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Form;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionObject;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;

class LivewireNotesHiddenTest extends TestCase
{
    use RefreshDatabase;

    private int $minute = 0;

    private function note(User $owner, string $title, string $body = 'Some body text'): Note
    {
        return Note::forceCreate([
            'user_id' => $owner->id,
            'title' => $title,
            'body' => $body,
            'created_at' => now()->subDays(1)->addMinutes(++$this->minute),
            'updated_at' => now()->subDays(1)->addMinutes($this->minute),
        ]);
    }

    private function comment(Note $note, User $author, int $minutesAfterNote): Comment
    {
        return Comment::forceCreate([
            'note_id' => $note->id,
            'user_id' => $author->id,
            'body' => 'A comment',
            'created_at' => $note->created_at->copy()->addMinutes($minutesAfterNote),
            'updated_at' => $note->created_at->copy()->addMinutes($minutesAfterNote),
        ]);
    }

    private function noteWithTwoComments(User $owner, string $title): Note
    {
        $note = $this->note($owner, $title);
        $this->comment($note, User::factory()->create(['name' => 'Olga Older']), 1);
        $this->comment($note, User::factory()->create(['name' => 'Nina Newest']), 2);

        return $note;
    }

    /** `title` / `body` on the component, or `form.title` / `form.body` when the build uses a form object. */
    private function field($component, string $name): string
    {
        $instance = $component->instance();

        return property_exists($instance, 'form') && is_object($instance->form) && property_exists($instance->form, $name)
            ? "form.{$name}"
            : $name;
    }

    private function fieldValue($component, string $name): mixed
    {
        return data_get($component->instance(), $this->field($component, $name));
    }

    private function fill($component, string $title, ?string $body = null)
    {
        $component->set($this->field($component, 'title'), $title);
        if ($body !== null) {
            $component->set($this->field($component, 'body'), $body);
        }

        return $component;
    }

    private function tryTo(callable $action): void
    {
        try {
            $action();
        } catch (Throwable) {
        }
    }

    private function assertDenied($component): void
    {
        try {
            $component->assertForbidden();
        } catch (AssertionFailedError) {
            $component->assertNotFound();
        }
    }

    /** Public properties that hold an id or a model, as dot paths, including those of a form object. */
    private function tamperTargets(object $instance, string $prefix = ''): array
    {
        $paths = [];
        foreach ((new ReflectionObject($instance))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $declaredBy = $property->getDeclaringClass();
            if ($property->isStatic() || (! $declaredBy->isAnonymous() && str_starts_with($declaredBy->getName(), 'Livewire\\'))) {
                continue;
            }
            if (! $property->isInitialized($instance)) {
                continue;
            }
            $value = $property->getValue($instance);
            $path = $prefix.$property->getName();
            if ($value instanceof Form) {
                $paths = [...$paths, ...$this->tamperTargets($value, $path.'.')];
            } elseif ($value instanceof Model || is_int($value) || (is_string($value) && ctype_digit($value))) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** [queries on first render, queries on a later request] for an owner with $notes notes of two comments each. */
    private function indexQueries(int $notes): array
    {
        $owner = User::factory()->create();
        for ($i = 1; $i <= $notes; $i++) {
            $this->noteWithTwoComments($owner, "Note {$i}");
        }
        $this->actingAs($owner);

        $component = null;
        $first = $this->countQueries(function () use (&$component) {
            $component = Livewire::test('notes.index');
        });
        $later = $this->countQueries(fn () => $component->call('$refresh'));

        return [$first, $later];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/notes')->assertRedirect(route('login'));
    }

    public function test_index_page_shows_title_comment_count_and_latest_commenter(): void
    {
        $owner = User::factory()->create();
        $this->noteWithTwoComments($owner, 'Groceries for Sunday');

        $this->actingAs($owner)->get(route('notes.index'))
            ->assertOk()
            ->assertSee('Groceries for Sunday')
            ->assertSee('2 comments')
            ->assertSee('Nina Newest');
    }

    public function test_create_saves_a_note_for_the_user_and_redirects(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->get(route('notes.create'))->assertOk();

        $component = Livewire::test('notes.create');
        $this->fill($component, 'A brand new note', 'With a body')->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('notes.index'));

        $note = Note::sole();
        $this->assertSame($owner->id, (int) $note->user_id);
        $this->assertSame('A brand new note', $note->title);
        $this->assertSame('With a body', $note->body);
    }

    public function test_edit_is_prefilled_and_saves_changes(): void
    {
        $owner = User::factory()->create();
        $note = $this->note($owner, 'Original title', 'Original body');
        $this->actingAs($owner);
        $this->get(route('notes.edit', $note))->assertOk();

        $component = Livewire::test('notes.edit', ['note' => $note]);
        $this->assertSame('Original title', $this->fieldValue($component, 'title'));
        $this->assertSame('Original body', $this->fieldValue($component, 'body'));

        $this->fill($component, 'Updated title', 'Updated body')->call('save')->assertHasNoErrors();

        $this->assertSame('Updated title', $note->fresh()->title);
        $this->assertSame('Updated body', $note->fresh()->body);
    }

    public function test_edit_delete_removes_the_note_and_redirects(): void
    {
        $owner = User::factory()->create();
        $note = $this->note($owner, 'Delete me');
        $this->actingAs($owner);

        Livewire::test('notes.edit', ['note' => $note])->call('delete')->assertRedirect(route('notes.index'));

        $this->assertNull(Note::find($note->id));
    }

    public function test_index_delete_removes_an_own_note(): void
    {
        $owner = User::factory()->create();
        $note = $this->note($owner, 'Delete me from the list');
        $this->actingAs($owner);

        Livewire::test('notes.index')->call('delete', $note->id);

        $this->assertNull(Note::find($note->id));
    }

    public function test_edit_is_denied_to_a_non_owner(): void
    {
        $note = $this->note(User::factory()->create(), 'Private plans', 'Secret body');
        $this->actingAs(User::factory()->create());

        $this->assertContains($this->get(route('notes.edit', $note))->status(), [403, 404]);
        $this->assertDenied(Livewire::test('notes.edit', ['note' => $note]));
    }

    public function test_edit_save_checks_the_current_user_again(): void
    {
        $owner = User::factory()->create();
        $note = $this->note($owner, 'Owner title', 'Owner body');
        $this->actingAs($owner);
        $component = $this->fill(Livewire::test('notes.edit', ['note' => $note]), 'Hijacked title', 'Hijacked body');

        $this->actingAs(User::factory()->create());
        $this->tryTo(fn () => $component->call('save'));

        $this->assertSame('Owner title', $note->fresh()->title);
        $this->assertSame('Owner body', $note->fresh()->body);
    }

    public function test_edit_delete_checks_the_current_user_again(): void
    {
        $owner = User::factory()->create();
        $note = $this->note($owner, 'Owner title');
        $this->actingAs($owner);
        $component = Livewire::test('notes.edit', ['note' => $note]);

        $this->actingAs(User::factory()->create());
        $this->tryTo(fn () => $component->call('delete'));

        $this->assertNotNull(Note::find($note->id));
    }

    public function test_tampered_edit_state_cannot_change_another_users_note(): void
    {
        $owner = User::factory()->create();
        $mine = $this->note($owner, 'My note', 'My body');
        $theirs = $this->note(User::factory()->create(), 'Their note', 'Their body');
        $this->actingAs($owner);
        $component = Livewire::test('notes.edit', ['note' => $mine]);

        foreach ($this->tamperTargets($component->instance()) as $path) {
            $this->tryTo(fn () => $component->set($path, $theirs->id));
        }
        $this->tryTo(fn () => $this->fill($component, 'Hacked title', 'Hacked body')->call('save'));
        $this->tryTo(fn () => $component->call('delete'));

        $this->assertSame('Their note', Note::find($theirs->id)?->title);
        $this->assertSame('Their body', Note::find($theirs->id)?->body);
    }

    public function test_index_delete_cannot_remove_another_users_note(): void
    {
        $theirs = $this->note(User::factory()->create(), 'Their note');
        $this->actingAs(User::factory()->create());

        $this->tryTo(fn () => Livewire::test('notes.index')->call('delete', $theirs->id));

        $this->assertNotNull(Note::find($theirs->id));
    }

    public function test_create_rejects_invalid_titles_and_bodies(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([['', 'Fine body'], ['ab', 'Fine body'], [str_repeat('x', 101), 'Fine body'], ['Fine title', str_repeat('y', 5001)]] as [$title, $body]) {
            $component = Livewire::test('notes.create');
            $this->fill($component, $title, $body)->call('save')->assertHasErrors();
        }

        $this->assertSame(0, Note::count());
    }

    public function test_create_rejects_fields_that_were_never_filled(): void
    {
        $this->actingAs(User::factory()->create());

        $onlyTitle = Livewire::test('notes.create');
        $this->tryTo(fn () => $onlyTitle->set($this->field($onlyTitle, 'title'), 'Only a title')->call('save')->assertHasErrors());

        $onlyBody = Livewire::test('notes.create');
        $this->tryTo(fn () => $onlyBody->set($this->field($onlyBody, 'body'), 'Only a body')->call('save')->assertHasErrors());

        $nothing = Livewire::test('notes.create');
        $this->tryTo(fn () => $nothing->call('save')->assertHasErrors());

        $this->assertSame(0, Note::count());
        $onlyTitle->assertHasErrors();
        $onlyBody->assertHasErrors();
        $nothing->assertHasErrors();
    }

    public function test_edit_rejects_invalid_input_and_keeps_the_note(): void
    {
        $owner = User::factory()->create();
        $note = $this->note($owner, 'Valid title', 'Valid body');
        $this->actingAs($owner);

        foreach ([['ab', 'Valid body'], [str_repeat('x', 101), 'Valid body'], ['Valid title', '']] as [$title, $body]) {
            $component = Livewire::test('notes.edit', ['note' => $note]);
            $this->fill($component, $title, $body)->call('save')->assertHasErrors();
        }

        $this->assertSame('Valid title', $note->fresh()->title);
        $this->assertSame('Valid body', $note->fresh()->body);
    }

    public function test_index_query_count_does_not_grow_with_rows_on_first_render(): void
    {
        [$five] = $this->indexQueries(5);
        [$many] = $this->indexQueries(25);

        $this->assertLessThanOrEqual($five + 2, $many, "first render: {$five} queries for 5 notes, {$many} for 25");
    }

    public function test_index_query_count_does_not_grow_with_rows_on_a_later_request(): void
    {
        [, $five] = $this->indexQueries(5);
        [, $many] = $this->indexQueries(25);

        $this->assertLessThanOrEqual($five + 2, $many, "later request: {$five} queries for 5 notes, {$many} for 25");
    }

    public function test_index_rows_stay_complete_on_a_later_request(): void
    {
        $owner = User::factory()->create();
        $this->noteWithTwoComments($owner, 'Groceries for Sunday');
        $this->actingAs($owner);

        Livewire::test('notes.index')
            ->call('$refresh')
            ->assertSee('Groceries for Sunday')
            ->assertSee('2 comments')
            ->assertSee('Nina Newest');
    }

    public function test_index_shows_only_own_notes_ten_per_page_newest_first(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        for ($i = 1; $i <= 12; $i++) {
            $this->note($owner, sprintf('Mine %02d', $i));
        }
        foreach (['Theirs A', 'Theirs B', 'Theirs C'] as $title) {
            $this->note($stranger, $title);
        }
        $this->actingAs($owner);

        Livewire::test('notes.index')
            ->assertSeeInOrder(['Mine 12', 'Mine 11', 'Mine 04', 'Mine 03'])
            ->assertDontSee('Mine 02')
            ->assertDontSee('Mine 01')
            ->assertDontSee('Theirs');

        $this->get('/notes?page=2')
            ->assertOk()
            ->assertSeeInOrder(['Mine 02', 'Mine 01'])
            ->assertDontSee('Mine 03')
            ->assertDontSee('Theirs');
    }

    public function test_index_search_filters_by_title_case_insensitively(): void
    {
        $owner = User::factory()->create();
        $this->note($owner, 'Shopping list');
        $this->note($owner, 'Weekly SHOPPING run');
        $this->note($owner, 'Holiday ideas');
        $this->note(User::factory()->create(), 'Shopping for someone else');
        $this->actingAs($owner);

        Livewire::test('notes.index')
            ->set('search', 'shop')
            ->assertSee('Shopping list')
            ->assertSee('Weekly SHOPPING run')
            ->assertDontSee('Holiday ideas')
            ->assertDontSee('someone else');
    }

    public function test_changing_the_search_returns_to_the_first_page(): void
    {
        $owner = User::factory()->create();
        for ($i = 1; $i <= 12; $i++) {
            $this->note($owner, sprintf('Mine %02d', $i));
        }
        $this->actingAs($owner);

        Livewire::withQueryParams(['page' => 2])->test('notes.index')
            ->assertSee('Mine 01')
            ->set('search', 'Mine 07')
            ->assertSee('Mine 07');
    }
}

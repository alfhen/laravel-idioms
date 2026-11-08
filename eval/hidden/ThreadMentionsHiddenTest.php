<?php

namespace Tests\Hidden;

use App\Models\Reply;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThreadMentionsHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Reply::flushEventListeners();
        parent::tearDown();
    }

    /** @return list<list<string>> the To addresses of each delivered message */
    private function sentTo(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
            ->map(fn ($m) => array_map(fn ($a) => $a->getAddress(), $m->getOriginalMessage()->getTo()))
            ->values()->all();
    }

    private function queuedMails(): int
    {
        return Schema::hasTable('jobs') ? DB::table('jobs')->where('payload', 'like', '%MentionedInReply%')->count() : 0;
    }

    private function people(): array
    {
        $author = User::factory()->create(['username' => 'author', 'email' => 'author@example.test']);
        $bob = User::factory()->create(['username' => 'bob', 'email' => 'bob@example.test']);
        $alice = User::factory()->create(['username' => 'alice', 'email' => 'alice@example.test']);
        User::factory()->create(['username' => 'carol', 'email' => 'carol@example.test']);

        return [$author, $bob, $alice];
    }

    private function thread(User $owner): Thread
    {
        return Thread::factory()->create(['user_id' => $owner->id, 'reply_count' => 0]);
    }

    public function test_each_mentioned_user_gets_one_private_email_and_one_notification(): void
    {
        [$author, $bob, $alice] = $this->people();
        $thread = $this->thread($author);
        Sanctum::actingAs($author, ['*']);

        $this->postJson("/api/threads/{$thread->id}/replies", ['body' => '@Bob hi @bob and @alice, cc @AUTHOR @ghost'])->assertCreated();

        $to = $this->sentTo();
        sort($to);
        $this->assertSame([['alice@example.test'], ['bob@example.test']], $to);
        $this->assertSame(0, $this->queuedMails());
        $notified = DB::table('notifications')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([$bob->id, $alice->id], $notified);
        $this->assertSame(1, (int) DB::table('threads')->where('id', $thread->id)->value('reply_count'));
    }

    public function test_a_reply_without_mentions_notifies_nobody(): void
    {
        [$author] = $this->people();
        $thread = $this->thread($author);
        Sanctum::actingAs($author, ['*']);

        $this->postJson("/api/threads/{$thread->id}/replies", ['body' => 'email me at bob@example.test'])->assertCreated();

        $this->assertSame([], $this->sentTo());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_a_failed_write_notifies_nobody(): void
    {
        [$author] = $this->people();
        $thread = $this->thread($author);
        Sanctum::actingAs($author, ['*']);
        Reply::saved(function () {
            throw new \RuntimeException('disk full');
        });

        $status = $this->postJson("/api/threads/{$thread->id}/replies", ['body' => 'hi @bob'])->status();

        if ($status !== 500) {
            $this->assertSame(201, $status, 'the reply is not saved through Eloquent, so no failure was injected');

            return;
        }
        $this->assertSame([], $this->sentTo());
        $this->assertSame(0, $this->queuedMails());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('replies')->count());
        $this->assertSame(0, (int) DB::table('threads')->where('id', $thread->id)->value('reply_count'));
    }

    public function test_reply_count_counts_every_reply(): void
    {
        [$author, $bob] = $this->people();
        $thread = $this->thread($author);
        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/threads/{$thread->id}/replies", ['body' => 'one'])->assertCreated();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($bob, ['*']);
        $this->postJson("/api/threads/{$thread->id}/replies", ['body' => 'two'])->assertCreated();

        $this->assertSame(2, (int) DB::table('threads')->where('id', $thread->id)->value('reply_count'));
    }

    public function test_guest_without_accept_header_gets_json_401(): void
    {
        [$author] = $this->people();
        $thread = $this->thread($author);

        $response = $this->post("/api/threads/{$thread->id}/replies", ['body' => 'hi']);

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }
}

<?php

namespace Tests\Hidden;

use App\Models\Activity;
use App\Models\Comment;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ActivityFeedHiddenTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> activity id => created_at timestamp */
    private array $createdAt = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->actingAs(User::factory()->create());
    }

    private function record(Model $subject, string $description, string $at): Activity
    {
        $activity = new Activity;
        $activity->forceFill(['description' => $description]);
        $activity->subject()->associate($subject);
        $activity->created_at = Carbon::parse($at, 'UTC');
        $activity->save();

        $this->createdAt[$activity->getKey()] = Carbon::parse($at, 'UTC')->getTimestamp();

        return $activity;
    }

    private function makePost(): Post
    {
        return Post::factory()->create();
    }

    private function makeComment(): Comment
    {
        return Comment::factory()->create(['post_id' => $this->makePost()->id]);
    }

    private function makeInvoice(): Invoice
    {
        return Invoice::factory()->create();
    }

    private function nextUrl(TestResponse $response): ?string
    {
        $next = $response->json('links.next');
        if (! is_string($next) || $next === '') {
            $next = $response->json('next_page_url');
        }

        return is_string($next) && $next !== '' ? $next : null;
    }

    /** @return array{ids: list<int>, items: list<array>, pages: int, first: int} */
    private function walk(string $url): array
    {
        $ids = [];
        $items = [];
        $pages = 0;
        $first = null;

        while ($url !== null) {
            $response = $this->getJson($url);
            $response->assertOk();
            $data = $response->json('data');
            $this->assertIsArray($data, "page {$pages} has no data array");
            $first ??= count($data);
            foreach ($data as $item) {
                $ids[] = (int) $item['id'];
                $items[] = $item;
            }
            $url = $this->nextUrl($response);
            $pages++;
            $this->assertLessThan(40, $pages, 'the next-page links never end');
        }

        return ['ids' => $ids, 'items' => $items, 'pages' => $pages, 'first' => (int) $first];
    }

    private function assertNewestFirst(array $ids): void
    {
        for ($i = 1; $i < count($ids); $i++) {
            $this->assertGreaterThanOrEqual(
                $this->createdAt[$ids[$i]],
                $this->createdAt[$ids[$i - 1]],
                'the feed is not newest first',
            );
        }
    }

    /** 5 older, 35 sharing one second, 5 newer, across all three subject kinds. */
    private function seedBurst(): array
    {
        $ids = [];
        $makers = [fn () => $this->makePost(), fn () => $this->makeComment(), fn () => $this->makeInvoice()];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->record($makers[$i % 3](), "old {$i}", '2026-11-01 08:00:0'.$i)->id;
        }
        for ($i = 0; $i < 35; $i++) {
            $ids[] = $this->record($makers[$i % 3](), "burst {$i}", '2026-11-10 12:00:00')->id;
        }
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->record($makers[$i % 3](), "new {$i}", '2026-11-20 09:00:0'.$i)->id;
        }

        return $ids;
    }

    public function test_the_feed_is_newest_first_in_pages_of_twenty(): void
    {
        $this->record($this->makePost(), 'older', '2026-11-01 08:00:00');
        $this->record($this->makeInvoice(), 'newer', '2026-11-02 08:00:00');

        $response = $this->getJson('/activities');

        $response->assertOk();
        $this->assertSame(['newer', 'older'], collect($response->json('data'))->pluck('description')->all());

        $this->seedBurst();
        $walk = $this->walk('/activities');
        $this->assertSame(20, $walk['first']);
    }

    public function test_walking_every_page_returns_each_activity_exactly_once_when_many_share_a_timestamp(): void
    {
        $seeded = $this->seedBurst();

        $walk = $this->walk('/activities');

        $this->assertSame(count($walk['ids']), count(array_unique($walk['ids'])), 'an activity appeared on more than one page');
        $this->assertEqualsCanonicalizing($seeded, $walk['ids'], 'activities were skipped or invented');
        $this->assertNewestFirst($walk['ids']);
    }

    public function test_items_name_their_subject_kind_and_id(): void
    {
        $post = $this->makePost();
        $comment = $this->makeComment();
        $invoice = $this->makeInvoice();
        $onPost = $this->record($post, 'on post', '2026-11-01 08:00:00');
        $onComment = $this->record($comment, 'on comment', '2026-11-01 08:00:01');
        $onInvoice = $this->record($invoice, 'on invoice', '2026-11-01 08:00:02');

        $response = $this->getJson('/activities');

        $response->assertOk();
        $items = collect($response->json('data'))->keyBy('id');
        $this->assertSame('post', $items[$onPost->id]['type']);
        $this->assertSame((int) $post->id, (int) $items[$onPost->id]['subject']['id']);
        $this->assertSame('comment', $items[$onComment->id]['type']);
        $this->assertSame((int) $comment->id, (int) $items[$onComment->id]['subject']['id']);
        $this->assertSame('invoice', $items[$onInvoice->id]['type']);
        $this->assertSame((int) $invoice->id, (int) $items[$onInvoice->id]['subject']['id']);
    }

    public function test_type_post_returns_only_post_activities_across_every_page(): void
    {
        $postIds = [];
        for ($i = 0; $i < 25; $i++) {
            $postIds[] = $this->record($this->makePost(), "post {$i}", sprintf('2026-11-10 12:00:%02d', $i))->id;
        }
        for ($i = 0; $i < 6; $i++) {
            $this->record($this->makeComment(), "comment {$i}", sprintf('2026-11-10 12:01:%02d', $i));
            $this->record($this->makeInvoice(), "invoice {$i}", sprintf('2026-11-10 11:59:%02d', $i));
        }

        $walk = $this->walk('/activities?type=post');

        foreach ($walk['items'] as $item) {
            $this->assertSame('post', $item['type']);
        }
        $this->assertSame(count($walk['ids']), count(array_unique($walk['ids'])));
        $this->assertEqualsCanonicalizing($postIds, $walk['ids']);
    }

    public function test_type_comment_and_type_invoice_filter_too(): void
    {
        $this->record($this->makePost(), 'p', '2026-11-01 08:00:00');
        $c = $this->record($this->makeComment(), 'c', '2026-11-01 08:00:01');
        $inv = $this->record($this->makeInvoice(), 'i', '2026-11-01 08:00:02');

        $comments = $this->getJson('/activities?type=comment');
        $invoices = $this->getJson('/activities?type=invoice');

        $comments->assertOk();
        $invoices->assertOk();
        $this->assertSame([$c->id], array_map('intval', collect($comments->json('data'))->pluck('id')->all()));
        $this->assertSame([$inv->id], array_map('intval', collect($invoices->json('data'))->pluck('id')->all()));
    }

    public function test_an_unknown_type_is_rejected_or_empty(): void
    {
        $this->record($this->makePost(), 'p', '2026-11-01 08:00:00');
        $this->record($this->makeComment(), 'c', '2026-11-01 08:00:01');
        $this->record($this->makeInvoice(), 'i', '2026-11-01 08:00:02');

        foreach (['user', 'banana', "post' OR '1'='1"] as $type) {
            $response = $this->getJson('/activities?type='.urlencode($type));

            $this->assertContains($response->status(), [200, 400, 422], "?type={$type} answered {$response->status()}");
            if ($response->status() === 200) {
                $this->assertSame([], $response->json('data'), "?type={$type} returned activities");
            }
        }
    }

    public function test_an_array_type_is_never_a_server_error(): void
    {
        $this->record($this->makePost(), 'p', '2026-11-01 08:00:00');

        $response = $this->getJson('/activities?type[]=post');

        $this->assertLessThan(500, $response->status(), "answered {$response->status()}");
    }

    public function test_a_deleted_subject_does_not_break_the_feed(): void
    {
        $post = $this->makePost();
        $invoice = $this->makeInvoice();
        $comment = $this->makeComment();
        $onPost = $this->record($post, 'on deleted post', '2026-11-01 08:00:00');
        $onInvoice = $this->record($invoice, 'on deleted invoice', '2026-11-01 08:00:01');
        $onComment = $this->record($comment, 'on deleted comment', '2026-11-01 08:00:02');
        $kept = $this->record($this->makePost(), 'on kept post', '2026-11-01 08:00:03');
        $post->delete();
        $invoice->delete();
        $comment->delete();

        foreach (['/activities', '/activities?type=post', '/activities?type=invoice', '/activities?type=comment'] as $url) {
            $response = $this->getJson($url);
            $response->assertOk();
            foreach ($response->json('data') as $item) {
                if (in_array((int) $item['id'], [$onPost->id, $onInvoice->id, $onComment->id], true)) {
                    $this->assertTrue(
                        ! isset($item['subject']) || $item['subject'] === null || ! isset($item['subject']['id'])
                            || in_array((int) $item['subject']['id'], [$post->id, $invoice->id, $comment->id], true),
                        "{$url} invented a subject for a deleted one",
                    );
                }
            }
        }

        $all = collect($this->getJson('/activities')->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertContains($kept->id, $all->all());
    }
}

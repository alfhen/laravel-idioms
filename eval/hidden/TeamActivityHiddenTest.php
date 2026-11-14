<?php

namespace Tests\Hidden;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TeamActivityHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00', 'UTC'));
    }

    private function user(string $name, ?string $lastLoginUtc): User
    {
        $user = User::factory()->create(['name' => $name]);
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => $lastLoginUtc]);

        return $user;
    }

    private function viewer(): User
    {
        return $this->user('Viewer Person', '2026-11-14 09:57:00');
    }

    private function activeCount(TestResponse $response): int
    {
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($response->getContent())));
        $this->assertMatchesRegularExpression('/Active in the last 7 days:\s*(\d+)/i', $text);
        preg_match('/Active in the last 7 days:\s*(\d+)/i', $text, $match);

        return (int) $match[1];
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->user('Hidden Person', '2026-11-14 09:57:00');

        $response = $this->get('/admin/team');

        $response->assertRedirect();
        $this->assertStringEndsWith('/login', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $response->assertDontSee('Hidden Person');
    }

    public function test_the_page_lists_each_user_with_a_relative_last_seen(): void
    {
        $viewer = $this->viewer();
        $this->user('Recent Person', '2026-11-14 09:57:00');
        $this->user('Older Person', '2026-11-12 10:00:00');
        $this->user('Absent Person', null);

        $response = $this->actingAs($viewer)->get('/admin/team');

        $response->assertOk();
        $response->assertSeeText('Recent Person');
        $response->assertSeeText('3 minutes ago');
        $response->assertSeeText('Older Person');
        $response->assertSeeText('2 days ago');
        $response->assertSeeText('Absent Person');
        $this->assertMatchesRegularExpression('/\bnever\b/i', strip_tags($response->getContent()));
    }

    public function test_signing_in_records_the_time(): void
    {
        $user = $this->user('Signing Person', null);

        Auth::login($user);

        $stored = DB::table('users')->where('id', $user->id)->value('last_login_at');
        $this->assertNotNull($stored);
        $this->assertTrue(CarbonImmutable::parse($stored, 'UTC')->equalTo(now()), "stored {$stored}");
    }

    public function test_the_active_count_covers_today_and_the_previous_six_days(): void
    {
        $viewer = $this->viewer();
        $this->user('Two Days', '2026-11-12 10:00:00');
        $this->user('Five Days', '2026-11-09 08:00:00');
        $this->user('Ten Days', '2026-11-04 10:00:00');
        $this->user('Never', null);

        $this->assertSame(3, $this->activeCount($this->actingAs($viewer)->get('/admin/team')));
    }

    public function test_a_sign_in_just_after_copenhagen_midnight_six_days_ago_counts(): void
    {
        $viewer = $this->viewer();
        $this->user('Early Bird', '2026-11-07 23:30:00');

        $this->assertSame(2, $this->activeCount($this->actingAs($viewer)->get('/admin/team')));
    }

    public function test_a_sign_in_just_before_copenhagen_midnight_six_days_ago_does_not_count(): void
    {
        $viewer = $this->viewer();
        $this->user('Night Owl', '2026-11-07 22:30:00');

        $this->assertSame(1, $this->activeCount($this->actingAs($viewer)->get('/admin/team')));
    }
}

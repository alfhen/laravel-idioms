<?php

namespace Tests\Hidden;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Throwable;

class TeamInvitationsHiddenTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logged = [];

    /** @var list<TestResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'mail.default' => 'array']);
        Event::listen(MessageLogged::class, function (MessageLogged $record): void {
            $this->logged[] = $record;
        });
    }

    private function team(User $owner, string $name = 'Knitters'): int
    {
        return DB::table('teams')->insertGetId([
            'name' => $name, 'owner_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function invite(User $owner, int $team, string $email): TestResponse
    {
        Sanctum::actingAs($owner, ['*']);
        $response = $this->postJson("/api/teams/{$team}/invitations", ['email' => $email]);
        $this->app['auth']->forgetGuards();

        return $this->responses[] = $response;
    }

    private function lastMailedToken(): string
    {
        $messages = $this->app['mail.manager']->mailer('array')->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'no email was sent');
        $email = $messages->last()->getOriginalMessage();
        $body = html_entity_decode((string) $email->getHtmlBody().' '.(string) $email->getTextBody());
        $this->assertMatchesRegularExpression('~/invitations/accept\?(?:[^\s"\'<>]*&)?token=[^&\s"\'<>]+~', $body);
        preg_match('~/invitations/accept\?(?:[^\s"\'<>]*&)?token=([^&\s"\'<>]+)~', $body, $match);

        return urldecode($match[1]);
    }

    /** @return array{0: int, 1: User, 2: string} team id, invitee, plain token */
    private function invitation(string $email = 'jane@example.com'): array
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => $email]);
        $team = $this->team($owner);
        $this->invite($owner, $team, $email)->assertCreated();

        return [$team, $invitee, $this->lastMailedToken()];
    }

    private function accept(string $email, string $token, ?string $returnTo = null, string $ip = '10.0.0.1'): TestResponse
    {
        $uri = '/invitations/accept'.($returnTo === null ? '' : '?'.http_build_query(['return_to' => $returnTo]));

        return $this->responses[] = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post($uri, ['email' => $email, 'token' => $token]);
    }

    private function isMember(int $team, User $user): bool
    {
        return DB::table('team_user')->where('team_id', $team)->where('user_id', $user->id)->exists();
    }

    private function assertRedirectStaysOnSite(TestResponse $response): void
    {
        $this->assertTrue($response->isRedirection(), "expected a redirect, got {$response->status()}");
        $location = (string) $response->headers->get('Location');
        $this->assertDoesNotMatchRegularExpression('~^\s*[\\\\/]{2}|^\s*/\\\\~', $location, "off-site redirect: {$location}");
        $host = parse_url($location, PHP_URL_HOST);
        $this->assertContains($host, [null, 'localhost', parse_url((string) config('app.url'), PHP_URL_HOST)], "off-site redirect: {$location}");
    }

    private function text(mixed $value, int $depth = 0): string
    {
        if ($depth > 4) {
            return '';
        }
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        if ($value instanceof Throwable) {
            return $value->getMessage().' '.$this->text($value->getPrevious(), $depth + 1);
        }
        if ($value instanceof \Symfony\Component\HttpFoundation\Request) {
            return $value->getUri().' '.$value->getContent().' '.json_encode($value->request->all()).' '.json_encode($value->query->all());
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            try {
                $value = $value->toArray();
            } catch (Throwable) {
                $value = get_object_vars($value);
            }
        }
        if ($value instanceof \JsonSerializable) {
            return (string) json_encode($value);
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? implode(' ', array_map(fn ($v) => $this->text($v, $depth + 1), $value)).' '.implode(' ', array_keys($value)) : '';
    }

    public function test_the_owner_invites_a_registered_user(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['email' => 'jane@example.com']);
        $team = $this->team($owner);

        $response = $this->invite($owner, $team, 'jane@example.com')->assertCreated();

        $this->assertIsInt($response->json('id'));
        $this->assertSame('jane@example.com', $response->json('email'));
        $this->assertSame(1, DB::table('invitations')->where('team_id', $team)->where('email', 'jane@example.com')->count());
        $this->assertNotSame('', $this->lastMailedToken());
    }

    public function test_only_the_owner_can_invite(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        User::factory()->create(['email' => 'jane@example.com']);
        $team = $this->team($owner);

        $this->invite($member, $team, 'jane@example.com')->assertForbidden();

        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_the_emailed_token_lets_the_invitee_join(): void
    {
        [$team, $invitee, $token] = $this->invitation();

        $response = $this->accept('jane@example.com', $token);

        $this->assertTrue($this->isMember($team, $invitee));
        $this->assertTrue($response->isRedirection());
        $this->assertSame("/teams/{$team}", parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_a_wrong_token_or_email_joins_nobody(): void
    {
        [$team, $invitee, $token] = $this->invitation();
        $other = User::factory()->create(['email' => 'omar@example.com']);

        $this->accept('jane@example.com', $token.'x');
        $this->accept('jane@example.com', strrev($token));
        $this->accept('omar@example.com', $token);

        $this->assertFalse($this->isMember($team, $invitee));
        $this->assertFalse($this->isMember($team, $other));
    }

    public function test_an_invitation_can_be_accepted_only_once(): void
    {
        [$team, $invitee, $token] = $this->invitation();
        $this->accept('jane@example.com', $token);
        $this->assertTrue($this->isMember($team, $invitee));
        DB::table('team_user')->where('team_id', $team)->where('user_id', $invitee->id)->delete();

        $this->accept('jane@example.com', $token);

        $this->assertFalse($this->isMember($team, $invitee));
    }

    public function test_return_to_a_path_on_the_site_is_followed(): void
    {
        [$team, $invitee, $token] = $this->invitation();

        $response = $this->accept('jane@example.com', $token, "/teams/{$team}/members");

        $this->assertTrue($this->isMember($team, $invitee));
        $this->assertRedirectStaysOnSite($response);
        $this->assertSame("/teams/{$team}/members", parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_return_to_an_absolute_external_url_stays_on_the_site(): void
    {
        [$team, $invitee, $token] = $this->invitation();

        $response = $this->accept('jane@example.com', $token, 'https://evil.example/login');

        $this->assertTrue($this->isMember($team, $invitee));
        $this->assertRedirectStaysOnSite($response);
    }

    public function test_return_to_a_scheme_relative_url_stays_on_the_site(): void
    {
        [$team, $invitee, $token] = $this->invitation();

        $response = $this->accept('jane@example.com', $token, '//evil.example/login');

        $this->assertTrue($this->isMember($team, $invitee));
        $this->assertRedirectStaysOnSite($response);
    }

    public function test_return_to_a_backslash_url_stays_on_the_site(): void
    {
        [$team, $invitee, $token] = $this->invitation();

        $response = $this->accept('jane@example.com', $token, '/\\evil.example/login');

        $this->assertTrue($this->isMember($team, $invitee));
        $this->assertRedirectStaysOnSite($response);
    }

    public function test_the_sixth_attempt_in_a_minute_is_throttled_even_with_the_right_token(): void
    {
        [$team, $invitee, $token] = $this->invitation();
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->accept('jane@example.com', "wrong-{$i}")->status(), "attempt {$i} was throttled");
        }

        $this->accept('jane@example.com', $token)->assertStatus(429);

        $this->assertFalse($this->isMember($team, $invitee));
    }

    public function test_throttling_one_email_leaves_other_emails_from_the_same_ip_alone(): void
    {
        [, , $janeToken] = $this->invitation('jane@example.com');
        [$team, $omar, $omarToken] = $this->invitation('omar@example.com');
        for ($i = 0; $i < 6; $i++) {
            $this->accept('jane@example.com', "wrong-{$i}");
        }

        $response = $this->accept('omar@example.com', $omarToken);

        $this->assertNotSame(429, $response->status());
        $this->assertTrue($this->isMember($team, $omar));
    }

    public function test_throttling_one_ip_leaves_the_same_email_from_another_ip_alone(): void
    {
        [$team, $invitee, $token] = $this->invitation();
        for ($i = 0; $i < 6; $i++) {
            $this->accept('jane@example.com', "wrong-{$i}", null, '10.0.0.1');
        }

        $response = $this->accept('jane@example.com', $token, null, '10.0.0.2');

        $this->assertNotSame(429, $response->status());
        $this->assertTrue($this->isMember($team, $invitee));
    }

    public function test_the_plain_token_is_not_stored(): void
    {
        [, , $token] = $this->invitation();

        $stored = DB::table('invitations')->get()->flatMap(fn ($row) => array_values((array) $row));

        $this->assertNotEmpty($stored);
        foreach ($stored as $value) {
            $this->assertStringNotContainsString($token, (string) $value);
        }
    }

    public function test_the_token_never_appears_in_logs_or_responses(): void
    {
        [$team, $invitee, $token] = $this->invitation();
        $this->accept('omar@example.com', $token);
        $this->accept('jane@example.com', $token.'x');
        $this->accept('jane@example.com', $token, "/teams/{$team}");
        $this->assertTrue($this->isMember($team, $invitee));

        foreach ($this->logged as $record) {
            $this->assertStringNotContainsString($token, $record->message.' '.$this->text($record->context), "token logged at {$record->level}");
        }
        foreach ($this->responses as $response) {
            $this->assertStringNotContainsString($token, (string) $response->getContent());
            $this->assertStringNotContainsString($token, (string) $response->headers);
        }
    }
}

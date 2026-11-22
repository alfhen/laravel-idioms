<?php

namespace Tests\Hidden;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * Every request goes to an absolute URL: after a request on a forged host the
 * test URL generator would otherwise keep that host for later relative calls.
 *
 * Hostile requests run with the environment set to `production`, because the
 * framework skips its trusted-host check under the test runner; a build that
 * relies on that check must be able to show it. They carry a session CSRF token
 * so the CSRF check, which also stops skipping, does not reject them first.
 */
class PasswordResetLinksHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const URL_CHARS = '[^\s"\'<>()\[\]]';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'mail.default' => 'array']);
    }

    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedHosts([]);
        parent::tearDown();
    }

    private function canonical(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    private function withoutScheme(string $url): string
    {
        return (string) preg_replace('~^https?://~i', '', $url);
    }

    private function assertOnCanonicalSite(string $link): void
    {
        $this->assertStringStartsWith(
            $this->withoutScheme($this->canonical('/')),
            $this->withoutScheme($link),
            "mailed link is not on APP_URL: {$link}",
        );
    }

    private function attacker(string $variant, string $path): array
    {
        return $variant === 'host'
            ? ['http://evil.test'.$path, []]
            : [$this->canonical($path), ['X-Forwarded-Host' => 'evil.test']];
    }

    private function hostile(string $variant, string $path, array $data, ?User $as = null): TestResponse
    {
        [$url, $headers] = $this->attacker($variant, $path);
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            if ($as !== null) {
                $this->actingAs($as);
            }
            $response = $this->withSession(['_token' => 'hidden-csrf'])
                ->withHeaders($headers)
                ->post($url, $data + ['_token' => 'hidden-csrf']);
        } finally {
            $this->app['env'] = $env;
            SymfonyRequest::setTrustedHosts([]);
            $this->defaultHeaders = [];
            $this->app['auth']->forgetGuards();
        }

        return $response;
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    private function mails(): array
    {
        $messages = $this->app['mail.manager']->mailer('array')->getSymfonyTransport()->messages();

        return array_values(array_map(fn ($m) => $m->getOriginalMessage(), iterator_to_array($messages)));
    }

    private function body(\Symfony\Component\Mime\Email $email): string
    {
        return html_entity_decode((string) $email->getHtmlBody().' '.(string) $email->getTextBody(), ENT_QUOTES | ENT_HTML5);
    }

    private function lastMailTo(string $address): string
    {
        $mine = array_values(array_filter($this->mails(), function ($email) use ($address) {
            foreach ($email->getTo() as $to) {
                if (strcasecmp($to->getAddress(), $address) === 0) {
                    return true;
                }
            }

            return false;
        }));
        $this->assertNotEmpty($mine, "no email was sent to {$address}");

        return $this->body(end($mine));
    }

    /** @return array{0: string, 1: string, 2: string} link, token, email */
    private function resetLink(string $body): array
    {
        $c = self::URL_CHARS;
        $pattern = "~https?://{$c}*?/reset-password/([^/?\s\"'<>()\[\]]+)\?(?:{$c}*?&)?email=([^&\s\"'<>()\[\]]+){$c}*~";
        $this->assertMatchesRegularExpression($pattern, $body, 'no absolute /reset-password/{token}?email= link in the email');
        preg_match($pattern, $body, $m);

        return [$m[0], urldecode($m[1]), urldecode($m[2])];
    }

    private function inviteLink(string $body): string
    {
        $c = self::URL_CHARS;
        $pattern = "~https?://{$c}*?/invitations/\d+/accept(?:\?{$c}*)?~";
        $this->assertMatchesRegularExpression($pattern, $body, 'no absolute /invitations/{id}/accept link in the email');
        preg_match($pattern, $body, $m);

        return $m[0];
    }

    /** The same path and query, requested on APP_URL. */
    private function onCanonical(string $link): string
    {
        $parts = parse_url($link);

        return $this->canonical(($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : ''));
    }

    private function team(User $owner): int
    {
        return DB::table('teams')->insertGetId([
            'name' => 'Knitters', 'owner_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function isMember(int $team, User $user): bool
    {
        return DB::table('team_user')->where('team_id', $team)->where('user_id', $user->id)->exists();
    }

    private function assertResetLinkWorks(string $link, string $token, string $email, User $user): void
    {
        $this->get($this->onCanonical($link))->assertOk();

        $response = $this->post($this->canonical('/reset-password'), [
            'token' => $token,
            'email' => $email,
            'password' => 'Brand-new-Passw0rd-x7!',
            'password_confirmation' => 'Brand-new-Passw0rd-x7!',
        ]);

        $this->assertLessThan(400, $response->status(), "reset failed with {$response->status()}");
        $this->assertTrue(Hash::check('Brand-new-Passw0rd-x7!', $user->fresh()->password), 'the password was not changed');
    }

    private function assertInviteLinkWorks(string $link, int $team, User $invitee): void
    {
        $response = $this->actingAs($invitee)->get($this->onCanonical($link));

        $this->assertLessThan(400, $response->status(), "accepting failed with {$response->status()}");
        $this->assertTrue($this->isMember($team, $invitee), 'the invitee did not join the team');
    }

    private function assertHostileResetIsSafe(string $variant): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->hostile($variant, '/forgot-password', ['email' => 'jane@example.com']);

        if ($response->isClientError()) {
            $this->assertSame([], $this->mails(), 'a rejected request still sent email');

            return;
        }
        $this->assertLessThan(400, $response->status(), "unexpected {$response->status()}");
        $body = $this->lastMailTo('jane@example.com');
        $this->assertStringNotContainsStringIgnoringCase('evil.test', $body);
        [$link, $token, $email] = $this->resetLink($body);
        $this->assertOnCanonicalSite($link);
        $this->assertResetLinkWorks($link, $token, $email, $user);
    }

    private function assertHostileInviteIsSafe(string $variant): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'omar@example.com']);
        $team = $this->team($owner);

        $response = $this->hostile($variant, "/teams/{$team}/invitations", ['email' => 'omar@example.com'], $owner);

        if ($response->isClientError()) {
            $this->assertSame([], $this->mails(), 'a rejected request still sent email');
            $this->assertFalse($this->isMember($team, $invitee));

            return;
        }
        $this->assertLessThan(400, $response->status(), "unexpected {$response->status()}");
        $body = $this->lastMailTo('omar@example.com');
        $this->assertStringNotContainsStringIgnoringCase('evil.test', $body);
        $link = $this->inviteLink($body);
        $this->assertOnCanonicalSite($link);
        $this->assertInviteLinkWorks($link, $team, $invitee);
    }

    private function invite(User $owner, int $team, string $email): TestResponse
    {
        $response = $this->actingAs($owner)->post($this->canonical("/teams/{$team}/invitations"), ['email' => $email]);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    public function test_forgot_password_mails_a_working_reset_link_on_the_canonical_site(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->post($this->canonical('/forgot-password'), ['email' => 'jane@example.com']);

        $this->assertLessThan(400, $response->status());
        [$link, $token, $email] = $this->resetLink($this->lastMailTo('jane@example.com'));
        $this->assertOnCanonicalSite($link);
        $this->assertSame('jane@example.com', $email);
        $this->assertResetLinkWorks($link, $token, $email, $user);
    }

    public function test_forgot_password_on_a_forged_host_is_rejected_or_mails_a_canonical_link(): void
    {
        $this->assertHostileResetIsSafe('host');
    }

    public function test_forgot_password_with_a_forwarded_host_is_rejected_or_mails_a_canonical_link(): void
    {
        $this->assertHostileResetIsSafe('forwarded');
    }

    public function test_the_owner_invites_and_the_mailed_link_adds_the_invitee(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'omar@example.com']);
        $team = $this->team($owner);

        $this->assertLessThan(400, $this->invite($owner, $team, 'omar@example.com')->status());

        $link = $this->inviteLink($this->lastMailTo('omar@example.com'));
        $this->assertOnCanonicalSite($link);
        $this->assertFalse($this->isMember($team, $invitee));
        $this->assertInviteLinkWorks($link, $team, $invitee);
    }

    public function test_only_the_owner_can_invite(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        User::factory()->create(['email' => 'omar@example.com']);
        $team = $this->team($owner);

        $status = $this->invite($other, $team, 'omar@example.com')->status();

        $this->assertContains($status, [403, 404]);
        $this->assertSame([], $this->mails());
    }

    public function test_inviting_on_a_forged_host_is_rejected_or_mails_a_canonical_link(): void
    {
        $this->assertHostileInviteIsSafe('host');
    }

    public function test_inviting_with_a_forwarded_host_is_rejected_or_mails_a_canonical_link(): void
    {
        $this->assertHostileInviteIsSafe('forwarded');
    }

    public function test_the_invite_link_without_its_query_string_is_rejected(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'omar@example.com']);
        $team = $this->team($owner);
        $this->invite($owner, $team, 'omar@example.com');
        $link = $this->inviteLink($this->lastMailTo('omar@example.com'));

        $response = $this->actingAs($invitee)->get($this->canonical((string) parse_url($link, PHP_URL_PATH)));

        $this->assertTrue($response->isClientError(), "expected a 4xx, got {$response->status()}");
        $this->assertFalse($this->isMember($team, $invitee));
    }

    public function test_another_user_cannot_use_the_invite_link(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'omar@example.com']);
        $stranger = User::factory()->create(['email' => 'eve@example.com']);
        $team = $this->team($owner);
        $this->invite($owner, $team, 'omar@example.com');
        $link = $this->inviteLink($this->lastMailTo('omar@example.com'));

        $response = $this->actingAs($stranger)->get($this->onCanonical($link));

        $this->assertTrue($response->isClientError(), "expected a 4xx, got {$response->status()}");
        $this->assertFalse($this->isMember($team, $stranger));
        $this->assertFalse($this->isMember($team, $invitee));
    }
}

<?php

namespace Tests\Hidden;

use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Throwable;

class BlockUserHiddenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A browser's cookie jar: plain cookie name => decrypted value.
     *
     * @var array<string, string>
     */
    private array $jar = [];

    private string $defaultGuard;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.debug' => false,
            'mail.default' => 'array',
            'queue.default' => 'sync',
            // Sessions are stored server side, as in production, so ending them server side works.
            'session.driver' => 'database',
        ]);
        $this->defaultGuard = (string) config('auth.defaults.guard');
    }

    /**
     * Each request starts like a new PHP process: no cached guard user and no in-memory session.
     */
    private function freshProcess(): void
    {
        $this->app['auth']->forgetGuards();
        // Route auth middleware switches the default guard for the rest of the process.
        $this->app['auth']->shouldUse($this->defaultGuard);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /** A browser request that sends and keeps cookies. */
    private function browser(string $method, string $uri, array $data = []): TestResponse
    {
        $this->freshProcess();
        $key = $this->app['encrypter']->getKey();
        $cookies = [];
        foreach ($this->jar as $name => $value) {
            $cookies[$name] = encrypt(CookieValuePrefix::create($name, $key).$value, false);
        }

        $response = $this->call($method, $uri, $data, $cookies, [], ['HTTP_ACCEPT' => 'text/html']);

        foreach ($response->headers->getCookies() as $cookie) {
            $name = $cookie->getName();
            if ($cookie->getValue() === null || $cookie->getValue() === '' || ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time())) {
                unset($this->jar[$name]);

                continue;
            }
            try {
                $this->jar[$name] = CookieValuePrefix::remove($this->app['encrypter']->decrypt($cookie->getValue(), false));
            } catch (Throwable) {
                $this->jar[$name] = $cookie->getValue();
            }
        }

        return $response;
    }

    /** Closes the browser: session cookies are gone, persistent cookies stay. */
    private function closeBrowser(): void
    {
        unset($this->jar[config('session.cookie')]);
    }

    private function rememberCookieName(): ?string
    {
        foreach (array_keys($this->jar) as $name) {
            if (Str::startsWith($name, 'remember_')) {
                return $name;
            }
        }

        return null;
    }

    private function api(string $method, string $uri, ?string $token, array $data = []): TestResponse
    {
        $this->freshProcess();
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return $this->json($method, $uri, $data, $headers);
    }

    private function user(string $email = 'jane@example.com', string $password = 'correct-horse'): User
    {
        return User::factory()->create(['email' => $email, 'password' => $password]);
    }

    private function adminToken(): string
    {
        return User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true])->createToken('admin')->plainTextToken;
    }

    private function block(User $user, ?string $adminToken = null): void
    {
        $response = $this->api('POST', "/api/admin/users/{$user->id}/block", $adminToken ?? $this->adminToken());
        $this->assertTrue($response->isSuccessful(), "blocking failed with {$response->status()}: {$response->getContent()}");
    }

    private function unblock(User $user, string $adminToken): void
    {
        $response = $this->api('DELETE', "/api/admin/users/{$user->id}/block", $adminToken);
        $this->assertTrue($response->isSuccessful(), "unblocking failed with {$response->status()}: {$response->getContent()}");
    }

    private function login(string $email, string $password, bool $remember = false): TestResponse
    {
        $data = ['email' => $email, 'password' => $password];
        if ($remember) {
            $data['remember'] = '1';
        }

        return $this->browser('POST', '/login', $data);
    }

    private function assertSeesDashboard(string $why): void
    {
        $response = $this->browser('GET', '/dashboard');
        $this->assertSame(200, $response->status(), "{$why}: expected the dashboard, got {$response->status()} ".$response->headers->get('Location'));
    }

    private function assertKeptOut(string $why): void
    {
        $response = $this->browser('GET', '/dashboard');
        $status = $response->status();
        if ($response->isRedirection()) {
            $path = parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) ?? '/';
            $this->assertNotSame('/dashboard', rtrim($path, '/'), "{$why}: redirected back to the dashboard");

            return;
        }
        $this->assertContains($status, [401, 403, 419, 423], "{$why}: expected to be kept out of the dashboard, got {$status}");
    }

    private function lastResetToken(): ?string
    {
        $messages = $this->app['mail.manager']->mailer('array')->getSymfonyTransport()->messages();
        if ($messages->isEmpty()) {
            return null;
        }
        $email = $messages->last()->getOriginalMessage();
        $body = html_entity_decode((string) $email->getHtmlBody().' '.(string) $email->getTextBody());
        if (! preg_match('~/reset-password/([^/?\s"\'<>&]+)~', $body, $match)) {
            $this->fail("the reset email has no /reset-password/{token} link:\n{$body}");
        }

        return urldecode($match[1]);
    }

    private function mailCount(): int
    {
        return $this->app['mail.manager']->mailer('array')->getSymfonyTransport()->messages()->count();
    }

    public function test_a_normal_user_can_log_in_with_a_session(): void
    {
        $this->user();
        $this->assertKeptOut('guest');

        $this->login('jane@example.com', 'correct-horse');

        $this->assertSeesDashboard('after logging in');
    }

    public function test_a_blocked_user_cannot_log_in(): void
    {
        $user = $this->user();
        $this->block($user);

        $this->login('jane@example.com', 'correct-horse', remember: true);

        $this->assertKeptOut('blocked user after a login attempt');
        $this->closeBrowser();
        $this->assertKeptOut('blocked user after a login attempt, with any cookie it left');
    }

    public function test_a_blocked_user_cannot_get_a_new_api_token(): void
    {
        $user = $this->user();
        $this->block($user);

        $response = $this->api('POST', '/api/auth/token', null, ['email' => 'jane@example.com', 'password' => 'correct-horse', 'device_name' => 'iPhone']);

        $this->assertContains($response->status(), [401, 403, 422, 423], "expected a refusal, got {$response->status()}");
        $this->assertNull($response->json('token'));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_an_existing_api_token_stops_working_once_blocked(): void
    {
        $user = $this->user();
        $other = $this->user('omar@example.com');
        $token = $user->createToken('iPhone')->plainTextToken;
        $otherToken = $other->createToken('iPhone')->plainTextToken;
        $this->api('GET', '/api/me', $token)->assertOk();

        $this->block($user);

        $this->assertContains($this->api('GET', '/api/me', $token)->status(), [401, 403]);
        $this->assertContains($this->api('GET', '/api/me', $token)->status(), [401, 403]);
        $this->api('GET', '/api/me', $otherToken)->assertOk();
    }

    public function test_an_existing_session_ends_on_the_next_request(): void
    {
        $user = $this->user();
        $this->login('jane@example.com', 'correct-horse');
        $this->assertSeesDashboard('before blocking');

        $this->block($user);

        $this->assertKeptOut('blocked user with an existing session');
        $this->assertKeptOut('blocked user with an existing session, second request');
    }

    public function test_a_remember_me_cookie_stops_working_once_blocked(): void
    {
        $user = $this->user();
        $this->login('jane@example.com', 'correct-horse', remember: true);
        $this->assertNotNull($this->rememberCookieName(), 'logging in with remember set no remember-me cookie');
        $this->closeBrowser();
        $this->assertSeesDashboard('remember-me after the browser was closed');
        $this->closeBrowser();

        $this->block($user);

        $this->assertKeptOut('blocked user with a remember-me cookie');
    }

    public function test_other_users_stay_logged_in_when_someone_is_blocked(): void
    {
        $this->user();
        $jane = User::where('email', 'jane@example.com')->first();
        $this->user('omar@example.com');
        $this->login('omar@example.com', 'correct-horse', remember: true);
        $this->assertSeesDashboard('omar before');

        $this->block($jane);

        $this->assertSeesDashboard('omar after someone else was blocked');
    }

    public function test_the_password_reset_flow_works_for_a_normal_user(): void
    {
        $this->user();

        $this->browser('POST', '/forgot-password', ['email' => 'jane@example.com']);
        $token = $this->lastResetToken();
        $this->assertNotNull($token, 'no reset email was sent');
        $this->browser('POST', '/reset-password', [
            'token' => $token, 'email' => 'jane@example.com', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ]);
        $this->jar = [];

        $this->login('jane@example.com', 'new-password-123');

        $this->assertSeesDashboard('after resetting the password and logging in');
    }

    public function test_a_password_reset_does_not_let_a_blocked_user_back_in(): void
    {
        $user = $this->user();
        $adminToken = $this->adminToken();
        $this->browser('POST', '/forgot-password', ['email' => 'jane@example.com']);
        $token = $this->lastResetToken();
        $this->assertNotNull($token, 'no reset email was sent');

        $this->block($user, $adminToken);

        $this->browser('POST', '/reset-password', [
            'token' => $token, 'email' => 'jane@example.com', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ]);
        $this->assertKeptOut('blocked user after using a reset link mailed before the block');
        $this->login('jane@example.com', 'new-password-123');
        $this->assertKeptOut('blocked user logging in with the reset password');
        $this->login('jane@example.com', 'correct-horse');
        $this->assertKeptOut('blocked user logging in with the old password');
    }

    public function test_a_blocked_user_cannot_reset_their_way_in_with_a_fresh_link(): void
    {
        $user = $this->user();
        $this->block($user);
        $before = $this->mailCount();

        $this->browser('POST', '/forgot-password', ['email' => 'jane@example.com']);

        if ($this->mailCount() > $before) {
            $token = $this->lastResetToken();
            $this->browser('POST', '/reset-password', [
                'token' => $token, 'email' => 'jane@example.com', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
            ]);
            $this->assertKeptOut('blocked user after a reset requested while blocked');
            $this->login('jane@example.com', 'new-password-123');
        }
        $this->assertKeptOut('blocked user after a reset requested while blocked, then logging in');
    }

    public function test_after_unblocking_login_works_again(): void
    {
        $user = $this->user();
        $adminToken = $this->adminToken();
        $this->block($user, $adminToken);
        $this->login('jane@example.com', 'correct-horse');
        $this->assertKeptOut('while blocked');

        $this->unblock($user, $adminToken);
        $this->jar = [];

        $this->login('jane@example.com', 'correct-horse');
        $this->assertSeesDashboard('after unblocking');
        $issued = $this->api('POST', '/api/auth/token', null, ['email' => 'jane@example.com', 'password' => 'correct-horse', 'device_name' => 'iPhone']);
        $issued->assertCreated();
        $this->api('GET', '/api/me', $issued->json('token'))->assertOk()->assertJsonFragment(['email' => 'jane@example.com']);
    }

    public function test_only_admins_can_block_or_unblock(): void
    {
        $user = $this->user();
        $this->user('omar@example.com');
        $omarToken = User::where('email', 'omar@example.com')->first()->createToken('x')->plainTextToken;
        $this->login('jane@example.com', 'correct-horse');

        $this->assertSame(403, $this->api('POST', "/api/admin/users/{$user->id}/block", $omarToken)->status());
        $this->assertContains($this->api('POST', "/api/admin/users/{$user->id}/block", null)->status(), [401, 403]);

        $this->assertSeesDashboard('after a non-admin tried to block');
        $this->assertSame(403, $this->api('DELETE', "/api/admin/users/{$user->id}/block", $omarToken)->status());
    }
}

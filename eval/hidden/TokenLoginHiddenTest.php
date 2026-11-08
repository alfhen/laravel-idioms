<?php

namespace Tests\Hidden;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TokenLoginHiddenTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/token', ['email' => $email, 'password' => $password, 'device_name' => 'iPhone']);
    }

    private function user(string $email = 'jane@example.com'): User
    {
        return User::factory()->create(['email' => $email, 'password' => 'correct-horse']);
    }

    public function test_valid_credentials_return_a_working_token(): void
    {
        $user = $this->user();

        $response = $this->login('jane@example.com', 'correct-horse');

        $response->assertCreated();
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->app['auth']->forgetGuards();
        $me = $this->withToken($token)->getJson('/api/me')->assertOk();
        $profile = $me->json('data') ?? $me->json();
        $this->assertSame($user->id, $profile['id']);
        $this->assertSame('jane@example.com', $profile['email']);
        $this->assertStringNotContainsString('password', $me->getContent());
        $this->assertStringNotContainsString('remember_token', $me->getContent());
    }

    public function test_wrong_credentials_are_a_401_with_the_message(): void
    {
        $this->user();

        $this->login('jane@example.com', 'wrong')->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
        $this->login('ghost@example.com', 'wrong')->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_a_missing_field_is_a_422(): void
    {
        $this->postJson('/api/auth/token', ['password' => 'x', 'device_name' => 'iPhone'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_sixth_attempt_in_a_minute_is_throttled_even_with_the_right_password(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->login('jane@example.com', 'wrong')->assertUnauthorized();
        }

        $this->login('jane@example.com', 'correct-horse')->assertStatus(429);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_throttling_is_per_email_and_ip(): void
    {
        $this->user();
        $this->user('omar@example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->login('jane@example.com', 'wrong')->assertUnauthorized();
        }

        $this->login('omar@example.com', 'correct-horse')->assertCreated();
        $this->login('jane@example.com', 'correct-horse', '10.0.0.2')->assertCreated();
    }

    public function test_the_limit_resets_after_a_minute(): void
    {
        $this->user();
        for ($i = 0; $i < 6; $i++) {
            $this->login('jane@example.com', 'wrong');
        }

        $this->travel(61)->seconds();

        $this->login('jane@example.com', 'correct-horse')->assertCreated();
    }

    public function test_revoking_deletes_only_the_token_used(): void
    {
        $this->user();
        $first = $this->login('jane@example.com', 'correct-horse')->json('token');
        $second = $this->login('jane@example.com', 'correct-horse')->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($first)->deleteJson('/api/auth/token')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withToken($first)->getJson('/api/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/me')->assertOk();
    }

    public function test_guest_without_accept_header_gets_json_401(): void
    {
        $response = $this->get('/api/me');

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }
}

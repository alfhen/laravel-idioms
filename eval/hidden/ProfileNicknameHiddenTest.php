<?php

namespace Tests\Hidden;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileNicknameHiddenTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_the_profile(): void
    {
        $user = User::factory()->create(['name' => 'Jane']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/me')->assertOk();

        $profile = $response->json('data') ?? $response->json();
        $this->assertSame($user->id, $profile['id']);
        $this->assertSame('Jane', $profile['name']);
        $this->assertArrayHasKey('nickname', $profile);
        $this->assertNull($profile['nickname']);
    }

    public function test_the_nickname_can_be_set_and_cleared(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me', ['nickname' => 'Jay'])->assertSuccessful();
        $this->assertSame('Jay', $user->fresh()->nickname);

        $this->patchJson('/api/me', ['nickname' => null])->assertSuccessful();
        $this->assertNull($user->fresh()->nickname);
    }

    public function test_a_nickname_over_30_characters_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/me', ['nickname' => str_repeat('a', 31)])->assertStatus(422)->assertJsonValidationErrors('nickname');
        $this->assertNull($user->fresh()->nickname);
    }

    public function test_guest_without_accept_header_gets_json_401(): void
    {
        $response = $this->get('/api/me');

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }
}

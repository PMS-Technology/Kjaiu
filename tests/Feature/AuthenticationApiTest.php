<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kjaiu.jwt.secret', str_repeat('test-secret-', 4));
        config()->set('kjaiu.jwt.issuer', 'https://kjaiu.test');
    }

    public function test_nested_email_login_returns_a_compatible_jwt_and_user_shape(): void
    {
        $user = User::factory()->create([
            'name' => 'API Client',
            'email' => 'client@example.test',
            'password' => 'correct-password',
            'credit' => '25.50',
        ]);

        $login = $this->post('/v1/login', [
            'email' => [
                'email' => $user->email,
                'password' => 'correct-password',
            ],
        ]);

        $login->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', 'login successful')
            ->assertJsonStructure(['jwt']);

        $this->withHeader('Authorization', 'JWT '.$login->json('jwt'))
            ->getJson('/v1/user')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.client.email', $user->email)
            ->assertJsonPath('data.client.credit', '25.50')
            ->assertJsonStructure(['data' => ['client', 'country']]);
    }

    public function test_password_change_revokes_all_previously_issued_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'security@example.test',
            'password' => 'old-password',
        ]);
        $login = $this->post('/v1/login', [
            'email' => ['email' => $user->email, 'password' => 'old-password'],
        ]);
        $token = $login->json('jwt');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/v1/user/password', [
                'old_password' => 'old-password',
                'new_password' => 'new-password',
            ])
            ->assertJsonPath('status', 200);

        $this->withHeader('Authorization', 'JWT '.$token)
            ->getJson('/v1/user')
            ->assertJsonPath('status', 405);

        $this->post('/v1/login', [
            'email' => ['email' => $user->email, 'password' => 'new-password'],
        ])->assertJsonPath('status', 200)->assertJsonStructure(['jwt']);
    }

    public function test_suspended_administrator_cannot_use_an_existing_session(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'status' => 'Suspended',
        ]);

        $this->actingAs($administrator)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_root_redirects_to_the_administration_area(): void
    {
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/login');
    }
}

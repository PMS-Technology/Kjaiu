<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureActiveUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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

    public function test_accounts_outside_supported_user_groups_cannot_use_web_or_api_authentication(): void
    {
        $user = User::factory()->create([
            'email' => 'unsupported@example.test',
            'password' => 'correct-password',
            'role' => 'unsupported',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/v1/login', [
            'email' => ['email' => $user->email, 'password' => 'correct-password'],
        ])->assertJsonPath('status', 400)->assertJsonMissingPath('jwt');
    }

    public function test_password_change_revokes_all_previously_issued_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'security@example.test',
            'password' => 'old-password',
            'remember_token' => 'old-remember-token',
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

        $changed = $user->fresh();
        $this->assertSame(2, $changed->token_version);
        $this->assertNotSame('old-remember-token', $changed->remember_token);
        $this->assertTrue(Hash::check('new-password', $changed->password));

        $this->withHeader('Authorization', 'JWT '.$token)
            ->getJson('/v1/user')
            ->assertJsonPath('status', 405);

        $this->post('/v1/login', [
            'email' => ['email' => $user->email, 'password' => 'new-password'],
        ])->assertJsonPath('status', 200)->assertJsonStructure(['jwt']);
    }

    public function test_web_login_stores_the_fresh_integer_credential_version(): void
    {
        $user = User::factory()->create([
            'email' => 'session-version@example.test',
            'password' => 'correct-password',
            'token_version' => 7,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect('/portal')
            ->assertSessionHas(
                EnsureActiveUser::CREDENTIAL_VERSION_SESSION_KEY,
                fn (mixed $version): bool => is_int($version) && $version === 7,
            );

        $this->get('/portal')->assertOk();
    }

    #[DataProvider('invalidWebCredentialVersions')]
    public function test_protected_web_requests_reject_missing_stale_and_non_integer_credential_versions(
        bool $includeMarker,
        mixed $marker,
    ): void {
        $user = User::factory()->create(['token_version' => 7]);
        $session = [Auth::guard('web')->getName() => $user->id];
        if ($includeMarker) {
            $session[EnsureActiveUser::CREDENTIAL_VERSION_SESSION_KEY] = $marker;
        }

        $this->withSession($session)
            ->get('/portal')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public static function invalidWebCredentialVersions(): array
    {
        return [
            'missing' => [false, null],
            'stale integer' => [true, 6],
            'current numeric string' => [true, '7'],
        ];
    }

    public function test_remember_cookie_restores_an_active_user_after_the_original_session_expires(): void
    {
        $user = User::factory()->create([
            'email' => 'remembered@example.test',
            'password' => 'correct-password',
            'token_version' => 7,
        ]);
        [$recallerName, $recaller] = $this->rememberCookieFor($user, 'correct-password');
        $originalSessionId = session()->getId();

        $this->flushSession();
        $this->app->make('auth')->forgetGuards();

        $this->withCookie($recallerName, $recaller)
            ->get('/portal')
            ->assertOk()
            ->assertSessionHas(EnsureActiveUser::CREDENTIAL_VERSION_SESSION_KEY, 7);

        $this->assertNotSame($originalSessionId, session()->getId());
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertAuthenticatedAs($user);
    }

    public function test_remembered_session_with_a_stale_marker_is_rejected_after_token_version_bump(): void
    {
        $user = User::factory()->create([
            'email' => 'stale-remembered@example.test',
            'password' => 'correct-password',
            'token_version' => 7,
        ]);
        [$recallerName, $recaller] = $this->rememberCookieFor($user, 'correct-password');

        session()->forget(Auth::guard('web')->getName());
        $user->increment('token_version');
        $this->app->make('auth')->forgetGuards();

        $this->withCookie($recallerName, $recaller)
            ->get('/portal')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email')
            ->assertCookieExpired($recallerName);

        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertGuest();
    }

    public function test_api_logout_revokes_an_existing_remember_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'api-logout-remembered@example.test',
            'password' => 'correct-password',
            'token_version' => 7,
        ]);
        [$recallerName, $recaller] = $this->rememberCookieFor($user, 'correct-password');
        $oldRememberToken = $user->fresh()->remember_token;
        $apiLogin = $this->post('/v1/login', [
            'email' => ['email' => $user->email, 'password' => 'correct-password'],
        ])->assertJsonPath('status', 200);

        $this->withHeader('Authorization', 'Bearer '.$apiLogin->json('jwt'))
            ->postJson('/v1/logout')
            ->assertJsonPath('status', 200);

        $changed = $user->fresh();
        $this->assertSame(8, $changed->token_version);
        $this->assertNotSame($oldRememberToken, $changed->remember_token);

        $this->flushSession();
        $this->app->make('auth')->forgetGuards();

        $this->withCookie($recallerName, $recaller)
            ->get('/portal')
            ->assertRedirect('/login')
            ->assertSessionMissing(EnsureActiveUser::CREDENTIAL_VERSION_SESSION_KEY);

        $this->assertGuest();
    }

    public function test_inactive_user_restored_from_a_remember_cookie_is_logged_out(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive-remembered@example.test',
            'password' => 'correct-password',
        ]);
        [$recallerName, $recaller] = $this->rememberCookieFor($user, 'correct-password');

        $this->flushSession();
        $user->update(['status' => 'Suspended']);
        $this->app->make('auth')->forgetGuards();

        $this->withCookie($recallerName, $recaller)
            ->get('/portal')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email')
            ->assertSessionMissing(EnsureActiveUser::CREDENTIAL_VERSION_SESSION_KEY)
            ->assertCookieExpired($recallerName);

        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertGuest();
    }

    #[DataProvider('nonDatabaseSessionDrivers')]
    public function test_api_password_change_invalidates_an_existing_web_session_on_its_next_request(
        string $sessionDriver,
    ): void {
        $this->useSessionDriver($sessionDriver);
        $user = User::factory()->create([
            'email' => $sessionDriver.'-session@example.test',
            'password' => 'old-password',
            'token_version' => 4,
            'remember_token' => 'old-remember-token',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'old-password',
        ])->assertRedirect('/portal')
            ->assertSessionHas(EnsureActiveUser::CREDENTIAL_VERSION_SESSION_KEY, 4);

        $apiLogin = $this->post('/v1/login', [
            'email' => ['email' => $user->email, 'password' => 'old-password'],
        ])->assertJsonPath('status', 200);

        $this->withHeader('Authorization', 'Bearer '.$apiLogin->json('jwt'))
            ->putJson('/v1/user/password', [
                'old_password' => 'old-password',
                'new_password' => 'new-password',
            ])
            ->assertJsonPath('status', 200);

        $changed = $user->fresh();
        $this->assertSame(5, $changed->token_version);
        $this->assertNotSame('old-remember-token', $changed->remember_token);
        $this->get('/portal')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public static function nonDatabaseSessionDrivers(): array
    {
        return [
            'array' => ['array'],
            'file' => ['file'],
        ];
    }

    public function test_inactive_user_is_logged_out_on_the_next_protected_web_request(): void
    {
        $client = User::factory()->create(['status' => 'Active']);
        $this->actingAs($client);
        $client->update(['status' => 'Suspended']);

        $this->get('/portal')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_root_renders_the_public_homepage_and_protected_areas_require_login(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('云服务不该复杂')->assertSee('开始使用');
        $this->get('/portal')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_active_clients_and_administrators_share_the_web_login_and_land_on_the_portal(): void
    {
        $client = User::factory()->create([
            'email' => 'web-client@example.test',
            'password' => 'client-password',
        ]);
        $administrator = User::factory()->administrator()->create([
            'email' => 'web-admin@example.test',
            'password' => 'admin-password',
        ]);

        $this->post('/login', [
            'email' => $client->email,
            'password' => 'client-password',
        ])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($client);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/login', [
            'email' => $administrator->email,
            'password' => 'admin-password',
        ])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($administrator);
    }

    public function test_active_client_gets_a_403_from_admin_without_losing_the_session(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/admin')->assertForbidden();

        $this->assertAuthenticatedAs($client);
        $this->get('/portal')->assertOk();
    }

    private function useSessionDriver(string $driver): void
    {
        config(['session.driver' => $driver]);
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->make('auth')->forgetGuards();
    }

    private function rememberCookieFor(User $user, string $password): array
    {
        $recallerName = Auth::guard('web')->getRecallerName();
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
            'remember' => '1',
        ])->assertRedirect('/portal')->assertCookie($recallerName);
        $recaller = $response->getCookie($recallerName)?->getValue();

        $this->assertIsString($recaller);
        $this->assertNotSame('', $recaller);

        return [$recallerName, $recaller];
    }
}

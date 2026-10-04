<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The authentication surface is the compatibility boundary with the original
 * platform: legacy password hashes must verify and the register/login flows
 * must read and write the same columns.
 */
class AuthenticationApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_administrator_password_verifies_against_legacy_md5_hash(): void
    {
        $admin = new User([
            'user_login' => 'legacy-admin',
            'user_pass' => md5('secret-pass'),
            'user_status' => 1,
        ]);

        $this->assertTrue($admin->checkPassword('secret-pass'));
        $this->assertFalse($admin->checkPassword('wrong-pass'));
    }

    public function test_client_password_uses_the_prefixed_double_md5_scheme(): void
    {
        config(['kjaiu.password.authcode' => 'auth-code']);

        $hash = PasswordHasher::client('secret-pass', 'auth-code');

        $this->assertStringStartsWith('###', $hash);
        $this->assertSame('###' . md5(md5('auth-code' . 'secret-pass')), $hash);
        $this->assertTrue(PasswordHasher::checkClient('secret-pass', $hash));
        $this->assertFalse(PasswordHasher::checkClient('nope', $hash));
    }

    public function test_client_side_encrypted_password_is_accepted(): void
    {
        // AES-128-CBC, key "idcsmart.finance", IV "9311019310287172", base64 —
        // the scheme the client-area forms use before submit.
        $key = 'idcsmart.finance';
        $iv = '9311019310287172';
        $plain = 'secret-pass';

        $padded = $plain . str_repeat(chr(16 - (strlen($plain) % 16)), 16 - (strlen($plain) % 16));
        $payload = base64_encode(openssl_encrypt($padded, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv));

        $this->assertSame($plain, PasswordHasher::acceptedPlain($payload));
        $this->assertSame($plain, PasswordHasher::acceptedPlain($plain));
    }

    public function test_login_returns_a_jwt_for_valid_credentials(): void
    {
        $client = Client::create([
            'username' => 'api-user',
            'email' => 'api-user@example.com',
            'phonenumber' => '13800000000',
            'phone_code' => 86,
            'password' => PasswordHasher::client('secret-pass'),
            'status' => Client::STATUS_ACTIVE,
            'create_time' => time(),
            'api_password' => 'abcdef0123456789',
        ]);

        $response = $this->postJson('/v1/login', [
            'email' => $client->email,
            'password' => 'secret-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonStructure(['status', 'msg', 'data' => ['jwt']]);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $client = Client::create([
            'username' => 'api-user-2',
            'email' => 'api-user-2@example.com',
            'password' => PasswordHasher::client('secret-pass'),
            'status' => Client::STATUS_ACTIVE,
            'create_time' => time(),
        ]);

        $this->postJson('/v1/login', ['email' => $client->email, 'password' => 'wrong'])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    public function test_protected_endpoint_requires_a_token(): void
    {
        $this->getJson('/v1/user')
            ->assertStatus(401)
            ->assertJsonPath('status', 401);
    }

    /**
     * The panel is hash-routed, so `/admin/login` is not a page: a browser sent
     * there (a bookmark, or the panel's own expired-session redirect before it
     * switched to hash navigation) must land on the in-app login route rather
     * than on a bare JSON envelope.
     */
    public function test_admin_login_url_sends_a_browser_to_the_hash_route(): void
    {
        $this->get('/admin/login')
            ->assertStatus(302)
            ->assertRedirect('/admin#/login');
    }

    /**
     * The same URL stays a JSON endpoint for the callers that treat it as one —
     * the original platform answers `login_page` here.
     */
    public function test_admin_login_url_still_answers_json_to_api_callers(): void
    {
        $this->getJson('/admin/login')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonStructure(['status', 'msg', 'data' => ['captcha', 'login_captcha']]);

        $this->getJson('/admin/login_page')
            ->assertOk()
            ->assertJsonPath('status', 200);
    }

    /**
     * The SPA shell itself never requires a session — the login screen is part
     * of the same bundle, so gating the shell would lock everyone out.
     */
    public function test_admin_shell_is_served_without_a_session(): void
    {
        $this->get('/admin')->assertOk();
    }
}

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

    /**
     * Hashes imported from an installation carry its `web_authcode` as the salt.
     * Without reading that setting back, no imported credential can ever verify.
     */
    public function test_the_installed_authcode_is_used_when_the_env_value_is_blank(): void
    {
        config(['kjaiu.password.authcode' => '']);

        $salt = (string) \Illuminate\Support\Facades\DB::table('configuration')
            ->where('setting', 'web_authcode')
            ->value('value');

        $this->assertNotSame('', $salt, 'the fixture database should carry a web_authcode');

        $hash = '###' . md5(md5($salt . 'imported-secret'));

        $this->assertTrue(PasswordHasher::checkAdmin('imported-secret', $hash));
        $this->assertFalse(PasswordHasher::checkAdmin('wrong-secret', $hash));
    }

    /**
     * An explicit configuration value still wins, so an install with its own
     * salt is never silently switched to the table value.
     */
    public function test_an_explicit_authcode_overrides_the_stored_one(): void
    {
        config(['kjaiu.password.authcode' => 'explicit-salt']);

        $this->assertSame('explicit-salt', PasswordHasher::authCode('explicit-salt'));
        $this->assertTrue(PasswordHasher::checkAdmin('secret-pass', '###' . md5(md5('explicit-salt' . 'secret-pass'))));
    }

    public function test_the_admin_login_page_reports_the_captcha_toggle(): void
    {
        $this->getJson('/admin/login_page')
            ->assertOk()
            ->assertJsonStructure(['data' => ['is_captcha', 'captcha', 'login_captcha']]);
    }

    /**
     * The login screen sniffs the captcha response body: PNG bytes when the
     * toggle is on, and a failure envelope when it is off.
     */
    public function test_the_captcha_endpoint_answers_with_an_image_only_when_enabled(): void
    {
        \Illuminate\Support\Facades\DB::table('configuration')
            ->where('setting', 'allow_login_admin_captcha')
            ->update(['value' => '0']);
        \App\Services\Admin\SettingService::flush();

        $disabled = $this->get('/admin/verify?name=allow_login_admin_captcha');
        $disabled->assertOk()->assertJsonPath('status', 400);

        \Illuminate\Support\Facades\DB::table('configuration')
            ->where('setting', 'allow_login_admin_captcha')
            ->update(['value' => '1']);
        \App\Services\Admin\SettingService::flush();

        $enabled = $this->get('/admin/verify?name=allow_login_admin_captcha');
        $enabled->assertOk();
        $this->assertStringContainsString('image/', (string) $enabled->headers->get('Content-Type'));
        $this->assertNotSame('', (string) $enabled->getContent());

        \Illuminate\Support\Facades\DB::table('configuration')
            ->where('setting', 'allow_login_admin_captcha')
            ->update(['value' => '0']);
        \App\Services\Admin\SettingService::flush();
    }

    /**
     * The full enabled-captcha path: the image issues a code into the session,
     * the login accepts that code, and a second attempt cannot reuse it.
     */
    public function test_admin_login_accepts_the_issued_captcha_only_once(): void
    {
        $admin = User::create([
            'user_login' => 'captcha-admin-' . uniqid(),
            'user_nickname' => 'captcha-admin',
            'user_email' => 'captcha-admin@example.com',
            'user_type' => 1,
            'user_status' => User::STATUS_ENABLED,
            'create_time' => time(),
        ]);
        $admin->setPassword('captcha-secret');
        $admin->save();

        \Illuminate\Support\Facades\DB::table('configuration')
            ->where('setting', 'allow_login_admin_captcha')
            ->update(['value' => '1']);
        \App\Services\Admin\SettingService::flush();

        try {
            // Reaching the image is what stores the expected code.
            $this->get('/admin/verify?name=allow_login_admin_captcha')->assertOk();
            $code = (string) session('captcha.allow_login_admin_captcha');

            $this->assertNotSame('', $code, 'the captcha endpoint should issue a code');

            $this->postJson('/admin/login', [
                'username' => $admin->user_login,
                'password' => 'captcha-secret',
                'captcha' => $code,
            ])
                ->assertOk()
                ->assertJsonPath('status', 200)
                ->assertJsonPath('msg', '登录成功');

            // A captcha is single use: the same code must not work twice.
            $this->postJson('/admin/login', [
                'username' => $admin->user_login,
                'password' => 'captcha-secret',
                'captcha' => $code,
            ])->assertJsonPath('status', 400);
        } finally {
            \Illuminate\Support\Facades\DB::table('configuration')
                ->where('setting', 'allow_login_admin_captcha')
                ->update(['value' => '0']);
            \App\Services\Admin\SettingService::flush();
        }
    }
}

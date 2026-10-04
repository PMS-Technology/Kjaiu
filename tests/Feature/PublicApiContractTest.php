<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\JwtService;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contract tests for the public API (/v1).
 *
 * The endpoint paths, the `{status,msg,data}` envelope and the
 * `authorization: JWT <token>` header are the compatibility boundary with
 * downstream installations, so they are asserted directly rather than only
 * through behaviour tests.
 */
class PublicApiContractTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Endpoints that must be reachable without a token.
     */
    public static function publicEndpoints(): array
    {
        return [
            'login page' => ['GET', '/v1/login'],
            'register page' => ['GET', '/v1/register'],
            'password reset page' => ['GET', '/v1/pwreset'],
            'captcha' => ['GET', '/v1/captcha'],
            'gateways' => ['GET', '/v1/gateway'],
            'products' => ['GET', '/v1/products'],
            'product categories' => ['GET', '/v1/products/cates'],
            'news' => ['GET', '/v1/news'],
            'knowledge base' => ['GET', '/v1/knowledgebase'],
            'downloads' => ['GET', '/v1/downloads'],
        ];
    }

    /**
     * Endpoints that must require a token.
     *
     * Each data set is a one-element array: PHPUnit passes the outer array's
     * entries to the test method as arguments, so a bare string here is
     * rejected as an invalid data set and the test method silently runs zero
     * times.
     */
    public static function protectedEndpoints(): array
    {
        return [
            'profile' => ['/v1/user'],
            'security centre' => ['/v1/security_info'],
            'cart' => ['/v1/cart'],
            'services' => ['/v1/hosts'],
            'service categories' => ['/v1/hosts/cates'],
            'tickets' => ['/v1/tickets'],
            'affiliate' => ['/v1/affiliates'],
            'recharge info' => ['/v1/funds'],
            'transactions' => ['/v1/transactions/funds'],
            'messages' => ['/v1/message'],
            'login log' => ['/v1/log/login'],
            'system log' => ['/v1/log/system'],
            'real name auth' => ['/v1/real_name_auth'],
        ];
    }

    #[DataProvider('publicEndpoints')]
    public function test_public_endpoints_answer_with_the_platform_envelope(string $method, string $uri): void
    {
        $response = $this->json($method, $uri);

        $response->assertOk()
            ->assertJsonStructure(['status', 'msg', 'data'])
            ->assertJsonPath('status', 200);
    }

    #[DataProvider('protectedEndpoints')]
    public function test_protected_endpoints_reject_an_anonymous_caller(string $uri): void
    {
        // The HTTP status is 401 and the envelope carries the same 401 so both
        // a browser and a downstream client can react correctly.
        $this->json('GET', $uri)
            ->assertStatus(401)
            ->assertJsonPath('status', 401)
            ->assertJsonPath('data', null);
    }

    #[DataProvider('protectedEndpoints')]
    public function test_protected_endpoints_accept_a_valid_token(string $uri): void
    {
        // `/v1/affiliates` answers 400 while the programme is closed, and the
        // reference installation happens to store the enabling flag. Relying on
        // that row makes the test pass only against a populated database, so the
        // precondition is written here instead.
        $this->enableAffiliateProgramme();

        $client = $this->makeClient();
        $token = (new JwtService())->issue($client);

        $response = $this->json('GET', $uri, [], ['authorization' => 'JWT ' . $token]);

        $response->assertOk()->assertJsonPath('status', 200);
    }

    /**
     * Turn the affiliate programme on for the duration of one test.
     *
     * Both spellings are written: the reference installation stores the
     * camel-cased key while this application reads the snake-cased one.
     */
    protected function enableAffiliateProgramme(): void
    {
        foreach (['affiliate_enabled', 'AffiliateEnabled'] as $setting) {
            \Illuminate\Support\Facades\DB::table('configuration')->updateOrInsert(
                ['setting' => $setting],
                ['value' => '1', 'create_time' => time(), 'update_time' => time()],
            );
        }

        \App\Models\Configuration::flushCache();
        \App\Services\Admin\SettingService::flush();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->json('GET', '/v1/user', [], ['authorization' => 'JWT not-a-real-token'])
            ->assertStatus(401)
            ->assertJsonPath('status', 401);
    }

    public function test_a_bearer_prefixed_token_is_also_accepted(): void
    {
        $client = $this->makeClient();
        $token = (new JwtService())->issue($client);

        $this->json('GET', '/v1/user', [], ['authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('status', 200);
    }

    public function test_api_key_login_uses_the_documented_field_names(): void
    {
        $client = $this->makeClient(['api_password' => 'abcdef0123456789', 'api_open' => 1]);

        $this->postJson('/v1/login_api', [
            'account' => $client->email,
            'password' => 'abcdef0123456789',
        ])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonStructure(['data' => ['jwt']]);

        // A wrong key must not authenticate.
        $this->postJson('/v1/login_api', [
            'account' => $client->email,
            'password' => 'wrong-key',
        ])->assertJsonPath('status', 400);
    }

    public function test_a_client_with_api_disabled_cannot_use_api_key_login(): void
    {
        $client = $this->makeClient(['api_password' => 'abcdef0123456789', 'api_open' => 0]);

        $this->postJson('/v1/login_api', [
            'account' => $client->email,
            'password' => 'abcdef0123456789',
        ])->assertJsonPath('status', 400);
    }

    public function test_list_endpoints_return_the_documented_pagination_shape(): void
    {
        $client = $this->makeClient();
        $token = (new JwtService())->issue($client);

        $this->json('GET', '/v1/tickets', [], ['authorization' => 'JWT ' . $token])
            ->assertOk()
            ->assertJsonStructure(['status', 'msg', 'data' => ['list', 'total', 'page', 'limit', 'total_page']]);
    }

    protected function makeClient(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'username' => 'contract-' . uniqid(),
            'email' => uniqid() . '@example.com',
            'phonenumber' => '138' . random_int(10000000, 99999999),
            'phone_code' => 86,
            'password' => PasswordHasher::client('secret-pass'),
            'status' => Client::STATUS_ACTIVE,
            'create_time' => time(),
        ], $attributes));
    }
}

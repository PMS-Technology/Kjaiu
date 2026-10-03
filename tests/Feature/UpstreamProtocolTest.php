<?php

namespace Tests\Feature;

use App\Integrations\Upstream\SupplierClient;
use App\Integrations\Upstream\SupplierClientFactory;
use App\Models\FinanceApi;
use App\Models\Host;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The upstream (供应商) client is the second half of the compatibility surface:
 * a downstream installation of the original platform speaks exactly this
 * protocol, so the requests it produces and the way it reads replies are
 * asserted here rather than only exercised against a live supplier.
 *
 * Every fixture below mirrors a reply captured from a real ZJMF 3.7.6
 * installation. The shapes matter: the live platform returns the login token
 * at the top level (`{"jwt":...,"status":200,"msg":"login successful"}`), not
 * under `data`, and reports an unauthenticated call as `status` 405 rather
 * than an HTTP 401.
 */
class UpstreamProtocolTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A login reply in the shape the live platform actually sends.
     *
     * @return array<string, mixed>
     */
    protected function liveLoginReply(string $jwt): array
    {
        return ['jwt' => $jwt, 'status' => 200, 'msg' => 'login successful'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_authenticates_once_and_reuses_the_token(): void
    {
        $api = $this->makeSupplier();

        Http::fake([
            'upstream.test/v1/login_api' => Http::response($this->liveLoginReply('upstream-jwt-token')),
            'upstream.test/v1/products*' => Http::response([
                'status' => 200,
                'msg' => 'Success message',
                'data' => ['first_group' => [], 'currency' => ['id' => 1, 'code' => 'CNY']],
            ]),
        ]);

        $client = new SupplierClient($api);

        // Two calls must produce exactly one login.
        $client->products();
        $client->products();

        Http::assertSentCount(3);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/login_api')
                && $request['account'] === 'reseller'
                && $request['password'] === 'api-key-secret';
        });

        // Subsequent calls carry the documented JWT header.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/products')
                && $request->hasHeader('authorization', 'JWT upstream-jwt-token');
        });
    }

    /**
     * The regression that made every real handshake fail: the token lives at
     * the top level of the reply. Reading only `data.jwt` yields null here.
     */
    public function test_the_token_is_read_from_the_top_level_of_the_login_reply(): void
    {
        $api = $this->makeSupplier();

        Http::fake([
            'upstream.test/v1/login_api' => Http::response($this->liveLoginReply('top-level-token')),
            'upstream.test/v1/hosts*' => Http::response([
                'status' => 200, 'msg' => '请求成功', 'data' => ['total' => 0, 'host' => []],
            ]),
        ]);

        $result = (new SupplierClient($api))->hostStatus(new Host([
            'uid' => 1, 'productid' => 1, 'domain' => 'example.test',
            'billingcycle' => 'monthly', 'upstream_configoption' => json_encode(['upstream_host_id' => 5]),
        ]));

        // A failed handshake would surface as "上游接口登录失败".
        Http::assertSent(fn ($request) => $request->hasHeader('authorization', 'JWT top-level-token'));
        $this->assertNotSame('上游接口登录失败', $result['msg']);
    }

    /**
     * Some builds nest the token under `data`; that shape must keep working.
     */
    public function test_a_nested_token_is_still_accepted(): void
    {
        $api = $this->makeSupplier();

        Http::fake([
            'upstream.test/v1/login_api' => Http::response([
                'status' => 200, 'msg' => '登录成功', 'data' => ['jwt' => 'nested-token'],
            ]),
            'upstream.test/v1/products*' => Http::response([
                'status' => 200, 'msg' => '请求成功', 'data' => [],
            ]),
        ]);

        (new SupplierClient($api))->products();

        Http::assertSent(fn ($request) => $request->hasHeader('authorization', 'JWT nested-token'));
    }

    public function test_a_failed_login_returns_a_failure_envelope_rather_than_throwing(): void
    {
        $api = $this->makeSupplier();

        Http::fake([
            'upstream.test/v1/login_api' => Http::response([
                'status' => 400,
                'msg' => 'Account or API key error',
            ]),
        ]);

        $result = (new SupplierClient($api))->products();

        $this->assertFalse($result['status']);
        $this->assertIsString($result['msg']);
        $this->assertNotSame('', $result['msg']);
    }

    /**
     * The live platform rejects a missing or expired token with `status` 405
     * inside a 200 response, so the re-authentication path must be driven by
     * that code as well as by a bare HTTP 401.
     */
    public function test_a_status_405_body_triggers_re_authentication(): void
    {
        $api = $this->makeSupplier();

        $logins = 0;

        Http::fake(function ($request) use (&$logins) {
            if (str_contains($request->url(), '/v1/login_api')) {
                $logins++;

                return Http::response($this->liveLoginReply('token-' . $logins));
            }

            if ($request->hasHeader('authorization', 'JWT token-1')) {
                return Http::response(['status' => 405, 'msg' => '请登陆后再试']);
            }

            return Http::response([
                'status' => 200,
                'msg' => '请求成功',
                'data' => ['total' => 1, 'host' => [['id' => 7]]],
            ]);
        });

        $result = (new SupplierClient($api))->request('GET', 'hosts');

        $this->assertTrue($result['status'], 'retry after a 405 body should succeed');
        $this->assertSame(2, $logins, 'exactly one re-authentication should happen');
    }

    public function test_an_expired_token_triggers_one_re_authentication(): void
    {
        $api = $this->makeSupplier();

        $logins = 0;

        Http::fake(function ($request) use (&$logins) {
            if (str_contains($request->url(), '/v1/login_api')) {
                $logins++;

                return Http::response($this->liveLoginReply('token-' . $logins));
            }

            // The first authenticated attempt is rejected, the retry succeeds.
            if ($request->hasHeader('authorization', 'JWT token-1')) {
                return Http::response('', 401);
            }

            return Http::response([
                'status' => 200,
                'msg' => '请求成功',
                'data' => ['list' => [['id' => 7]]],
            ]);
        });

        $result = (new SupplierClient($api))->products();

        $this->assertTrue($result['status'], 'retry after 401 should succeed');
        $this->assertSame(2, $logins, 'exactly one re-authentication should happen');
    }

    public function test_a_business_payload_containing_a_status_key_is_not_mistaken_for_an_error(): void
    {
        // Host power state and invoice status payloads legitimately contain a
        // `status` key; the client must not read that as a failed envelope.
        $api = $this->makeSupplier();

        Http::fake([
            'upstream.test/v1/login_api' => Http::response($this->liveLoginReply('jwt-1')),
            'upstream.test/v1/hosts*' => Http::response([
                'status' => 200,
                'msg' => '请求成功',
                'data' => [
                    'id' => 3,
                    'status' => 'Active',
                    'domainstatus' => 'Active',
                ],
            ]),
        ]);

        $result = (new SupplierClient($api))->request('GET', 'hosts/3');

        $this->assertTrue($result['status']);
        $this->assertSame('Active', $result['data']['status']);
    }

    public function test_transport_failures_are_reported_as_a_failed_envelope(): void
    {
        $api = $this->makeSupplier();

        Http::fake([
            'upstream.test/v1/login_api' => Http::response($this->liveLoginReply('jwt-1')),
            'upstream.test/v1/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('connection refused'),
        ]);

        $result = (new SupplierClient($api))->request('GET', 'hosts');

        $this->assertFalse($result['status']);
    }

    public function test_a_supplier_without_a_hostname_fails_before_any_request(): void
    {
        Http::fake();

        $api = $this->makeSupplier(['hostname' => '']);

        $result = (new SupplierClient($api))->testConnection();

        $this->assertFalse($result['status']);
        Http::assertNothingSent();
    }

    public function test_the_factory_skips_manual_and_resource_suppliers(): void
    {
        // Manual and resource-pool suppliers have no HTTP API to call, so the
        // factory must return null rather than a client that would fail later.
        $manual = $this->makeSupplier(['type' => FinanceApi::TYPE_MANUAL]);

        $this->assertNull(SupplierClientFactory::make($manual->id));
        $this->assertNull(SupplierClientFactory::make(999999));
    }

    public function test_a_host_creation_posts_the_documented_cart_payload(): void
    {
        $api = $this->makeSupplier();

        $product = Product::create([
            'type' => 'cloud', 'gid' => 1, 'name' => 'Upstream product',
            'pay_type' => json_encode([]), 'create_time' => time(),
            'api_type' => Product::API_TYPE_ZJMF, 'zjmf_api_id' => $api->id, 'upstream_pid' => 42,
        ]);

        $host = new Host([
            'uid' => 1, 'productid' => $product->id, 'domain' => 'example.test',
            'billingcycle' => 'monthly', 'password' => 'p@ssword', 'amount' => 10.00,
            'domainstatus' => Host::STATUS_PENDING, 'upstream_configoption' => '[]',
        ]);

        Http::fake([
            'upstream.test/v1/login_api' => Http::response($this->liveLoginReply('jwt-1')),
            'upstream.test/v1/*' => Http::response([
                'status' => 200, 'msg' => '请求成功', 'data' => ['host_id' => 88],
            ]),
        ]);

        $result = (new SupplierClient($api))->createAccount($host);

        $this->assertTrue($result['status']);

        // The order push must reference the upstream product id, not the local one.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v1/')) {
                return false;
            }

            $body = $request->data();

            return (int) ($body['product_id'] ?? $body['pid'] ?? 0) === 42;
        });
    }

    protected function makeSupplier(array $attributes = []): FinanceApi
    {
        return FinanceApi::create(array_merge([
            'name' => '测试上游',
            'hostname' => 'https://upstream.test',
            'username' => 'reseller',
            'password' => 'api-key-secret',
            'type' => FinanceApi::TYPE_API,
            'status' => 1,
            'is_resource' => 0,
            'is_using' => 0,
            'auto_update' => 1,
            'create_time' => time(),
        ], $attributes));
    }
}

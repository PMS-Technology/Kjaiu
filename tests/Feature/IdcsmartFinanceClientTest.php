<?php

namespace Tests\Feature;

use App\Integrations\Idcsmart\FinanceClient;
use App\Integrations\Idcsmart\FinanceException;
use App\Integrations\Idcsmart\FinanceMutationAuthException;
use App\Models\SupplierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdcsmartFinanceClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_it_authenticates_and_exposes_only_reviewed_operations(): void
    {
        Http::fake([
            'supplier.test/zjmf_api_login' => Http::response(
                ['status' => 200, 'jwt' => 'jwt-one'],
                200,
                ['Content-Type' => 'application/json'],
            ),
            'supplier.test/api/product/list*' => Http::response(
                ['status' => 200, 'msg' => 'ok', 'data' => ['list' => []]],
                200,
                ['Content-Type' => 'application/json'],
            ),
            'supplier.test/api/product/42' => Http::response(
                ['status' => 200, 'data' => ['product' => ['id' => 42]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $account = $this->account();
        $client = $this->client($account);
        $this->assertSame([], $client->products(['page' => 2])->data['list']);
        $this->assertSame(42, $client->product(42)->data['product']['id']);

        $cached = Cache::get($this->jwtCacheKey($account));
        $this->assertIsString($cached);
        $this->assertStringNotContainsString('jwt-one', $cached);
        $this->assertSame('jwt-one', Crypt::decryptString($cached));
        foreach ([
            'setConfig',
            'quote',
            'clearCart',
            'addToCart',
            'settleCart',
            'applyCredit',
            'hostHeader',
        ] as $method) {
            $this->assertTrue(method_exists($client, $method));
        }
        foreach ([
            'renewalPage',
            'renew',
            'suspend',
            'unsuspend',
            'cancel',
        ] as $method) {
            $this->assertFalse(method_exists($client, $method));
        }

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://supplier.test/zjmf_api_login'
            && $request->method() === 'POST'
            && $request['username'] === 'api-user'
            && $request['password'] === 'api-password');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://supplier.test/api/product/list')
            && $request->method() === 'GET'
            && $request['page'] === 2
            && $request->hasHeader('Authorization', 'Bearer jwt-one'));
    }

    public function test_reviewed_operation_routes_and_form_payloads_match_the_legacy_contract(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = [
                $request->method(),
                parse_url($request->url(), PHP_URL_PATH),
                $request->data(),
                $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'),
            ];

            return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-one'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'added'],
                '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 501, 'hostid' => [601]]],
                '/apply_credit' => ['status' => 1001, 'msg' => 'paid'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Pending']]],
            }, 200, ['Content-Type' => 'application/json']);
        });

        $client = $this->client($this->account());
        $correlation = [
            'downstream_url' => 'https://billing.test',
            'downstream_token' => str_repeat('a', 32),
            'downstream_id' => 100000000000001,
        ];
        $client->clearCart($correlation);
        $client->addToCart([
            'pid' => 'product/42',
            'billingcycle' => 'month',
            'qty' => 1,
            'configoption' => [
                'server' => ['cpu' => 2, 'image' => 'ubuntu'],
                'network' => ['ipv6' => true],
            ],
        ]);
        $client->settleCart($correlation);
        $client->applyCredit(['invoiceid' => 'invoice/501', 'use_credit' => 1, 'enough' => 1]);
        $this->assertSame('Pending', $client->hostHeader('host/601')->data['host_data']['domainstatus']);

        $this->assertSame([
            ['POST', '/zjmf_api_login'],
            ['POST', '/cart/clear'],
            ['POST', '/cart/add_to_shop'],
            ['POST', '/cart/settle'],
            ['POST', '/apply_credit'],
            ['GET', '/host/header'],
        ], array_map(fn (array $request): array => array_slice($request, 0, 2), $requests));
        $this->assertSame($correlation, $requests[1][2]);
        $this->assertSame([
            'pid' => 'product/42',
            'billingcycle' => 'month',
            'qty' => 1,
            'configoption' => [
                'server' => ['cpu' => 2, 'image' => 'ubuntu'],
                'network' => ['ipv6' => true],
            ],
        ], $requests[2][2]);
        $this->assertSame($correlation, $requests[3][2]);
        $this->assertSame([
            'invoiceid' => 'invoice/501',
            'use_credit' => 1,
            'enough' => 1,
        ], $requests[4][2]);
        $this->assertSame(['host_id' => 'host/601'], $requests[5][2]);
        $this->assertSame([true, true, true, true], array_column(array_slice($requests, 1, 4), 3));
    }

    public function test_calculation_routes_use_safe_methods_and_normalize_the_authoritative_quote(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = [
                'method' => $request->method(),
                'path' => parse_url($request->url(), PHP_URL_PATH),
                'data' => $request->data(),
                'form' => $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'),
            ];

            return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-calculation'],
                '/cart/set_config' => ['status' => 200, 'data' => ['product' => ['id' => 42]]],
                '/cart/get_total' => ['status' => 200, 'data' => [
                    'sale_total' => '9.5',
                    'total' => '9.50',
                    'currency' => 'cny',
                ]],
            }, 200, ['Content-Type' => 'application/json']);
        });

        $client = $this->client($this->account());
        $this->assertSame(42, $client->setConfig([
            'pid' => 'product/42',
            'billingcycle' => 'month',
        ])->data['product']['id']);
        $quoteParameters = [
            'pid' => 'product/42',
            'billingcycle' => 'month',
            'qty' => 1,
            'configoption' => ['image' => 'ubuntu'],
        ];
        $quote = $client->quote($quoteParameters)->data['quote'];

        $this->assertSame([
            'amount' => '9.50',
            'currency' => 'CNY',
            'source' => 'data.sale_total',
        ], $quote);
        $this->assertSame([
            ['method' => 'POST', 'path' => '/zjmf_api_login'],
            ['method' => 'GET', 'path' => '/cart/set_config'],
            ['method' => 'POST', 'path' => '/cart/get_total'],
        ], array_map(
            fn (array $request): array => array_intersect_key($request, array_flip(['method', 'path'])),
            $requests,
        ));
        $this->assertSame([
            'pid' => 'product/42',
            'billingcycle' => 'month',
        ], $requests[1]['data']);
        $this->assertSame($quoteParameters, $requests[2]['data']);
        $this->assertTrue($requests[2]['form']);
    }

    public function test_quote_refreshes_auth_and_retries_as_a_non_ambiguous_safe_post(): void
    {
        $loginCount = 0;
        $quoteCount = 0;
        Http::fake(function (Request $request) use (&$loginCount, &$quoteCount) {
            if (str_ends_with($request->url(), '/zjmf_api_login')) {
                $loginCount++;

                return Http::response(
                    ['status' => 200, 'jwt' => 'jwt-quote-'.$loginCount],
                    200,
                    ['Content-Type' => 'application/json'],
                );
            }

            $quoteCount++;

            return Http::response(
                $quoteCount === 1
                    ? ['status' => 401, 'msg' => 'expired']
                    : ['status' => 200, 'products' => ['total' => '8.00'], 'currency_code' => 'CNY'],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $quote = $this->client($this->account())->quote(['pid' => 42, 'qty' => 1])->data['quote'];

        $this->assertSame('8.00', $quote['amount']);
        $this->assertSame('products.total', $quote['source']);
        $this->assertSame(2, $loginCount);
        $this->assertSame(2, $quoteCount);
        Http::assertSentCount(4);
    }

    public function test_malformed_safe_post_response_is_never_classified_as_mutation_ambiguous(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-malformed-quote']
                    : ['status' => 200, 'sale_total' => ['10.00'], 'currency' => 'CNY'],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        try {
            $this->client($this->account())->quote(['pid' => 42, 'qty' => 1]);
            $this->fail('Expected a malformed quote response exception.');
        } catch (FinanceException $exception) {
            $this->assertFalse($exception->outcomeIsAmbiguous());
            $this->assertSame('/cart/get_total', $exception->safeContext()['endpoint']);
            $this->assertSame('POST', $exception->safeContext()['method']);
        }

        Http::assertSentCount(2);
    }

    #[DataProvider('authStatuses')]
    public function test_it_refreshes_and_retries_catalog_reads_once_for_auth_envelopes(int $authStatus): void
    {
        $loginCount = 0;
        $productCount = 0;
        $authorization = [];

        Http::fake(function (Request $request) use (
            &$loginCount,
            &$productCount,
            &$authorization,
            $authStatus,
        ) {
            if (str_ends_with($request->url(), '/zjmf_api_login')) {
                $loginCount++;

                return Http::response(
                    ['status' => 200, 'jwt' => 'jwt-'.$loginCount],
                    200,
                    ['Content-Type' => 'application/json'],
                );
            }

            $productCount++;
            $authorization[] = $request->header('Authorization')[0] ?? null;

            return Http::response(
                $productCount === 1
                    ? ['status' => $authStatus, 'msg' => 'expired']
                    : ['status' => 200, 'data' => ['list' => []]],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $response = $this->client($this->account())->products();

        $this->assertSame(200, $response->status);
        $this->assertSame(2, $loginCount);
        $this->assertSame(2, $productCount);
        $this->assertSame(['Bearer jwt-1', 'Bearer jwt-2'], $authorization);
        Http::assertSentCount(4);
    }

    #[DataProvider('authStatuses')]
    public function test_host_header_refreshes_and_retries_once_for_auth_envelopes(int $authStatus): void
    {
        $loginCount = 0;
        $hostCount = 0;
        $hostRequests = [];
        Http::fake(function (Request $request) use (&$loginCount, &$hostCount, &$hostRequests, $authStatus) {
            if (str_ends_with($request->url(), '/zjmf_api_login')) {
                $loginCount++;

                return Http::response(
                    ['status' => 200, 'jwt' => 'jwt-'.$loginCount],
                    200,
                    ['Content-Type' => 'application/json'],
                );
            }

            $hostCount++;
            $hostRequests[] = [
                'method' => $request->method(),
                'path' => parse_url($request->url(), PHP_URL_PATH),
                'query' => $request->data(),
            ];

            return Http::response(
                $hostCount === 1
                    ? ['status' => $authStatus, 'msg' => 'expired']
                    : ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active']]],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $response = $this->client($this->account())->hostHeader('host-1');

        $this->assertSame('Active', $response->data['host_data']['domainstatus']);
        $this->assertSame(2, $loginCount);
        $this->assertSame(2, $hostCount);
        $this->assertSame([
            ['method' => 'GET', 'path' => '/host/header', 'query' => ['host_id' => 'host-1']],
            ['method' => 'GET', 'path' => '/host/header', 'query' => ['host_id' => 'host-1']],
        ], $hostRequests);
        Http::assertSentCount(4);
    }

    #[DataProvider('mutationAuthCases')]
    public function test_mutations_never_replay_after_auth_envelopes(
        string $method,
        array $parameters,
        string $path,
        int $authStatus,
    ): void {
        $loginCount = 0;
        $mutationCount = 0;
        $mutationRequest = null;
        Http::fake(function (Request $request) use (
            &$loginCount,
            &$mutationCount,
            &$mutationRequest,
            $authStatus,
        ) {
            if (str_ends_with($request->url(), '/zjmf_api_login')) {
                $loginCount++;

                return Http::response(
                    ['status' => 200, 'jwt' => 'jwt-'.$loginCount],
                    200,
                    ['Content-Type' => 'application/json'],
                );
            }

            $mutationCount++;
            $mutationRequest = $request;

            return Http::response(
                ['status' => $authStatus, 'msg' => 'expired'],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        try {
            $this->client($this->account())->{$method}($parameters);
            $this->fail('Expected a mutation authentication exception.');
        } catch (FinanceMutationAuthException $exception) {
            $this->assertTrue($exception->outcomeIsAmbiguous());
            $this->assertSame($authStatus, $exception->applicationStatus());
            $this->assertSame($path, $exception->safeContext()['endpoint']);
            $this->assertSame('POST', $exception->safeContext()['method']);
            $serialized = json_encode([$exception->getMessage(), $exception->safeContext()]);
            $this->assertStringNotContainsString('operation-secret', $serialized);
        }

        $this->assertSame(1, $loginCount);
        $this->assertSame(1, $mutationCount);
        $this->assertSame('POST', $mutationRequest->method());
        $this->assertSame($path, parse_url($mutationRequest->url(), PHP_URL_PATH));
        $this->assertSame($parameters, $mutationRequest->data());
        $this->assertTrue($mutationRequest->hasHeader(
            'Content-Type',
            'application/x-www-form-urlencoded',
        ));
        Http::assertSentCount(2);
    }

    #[DataProvider('settlementReferenceForms')]
    public function test_settlement_accepts_supported_reference_forms(array $envelope): void
    {
        Http::fake(function (Request $request) use ($envelope) {
            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-one']
                    : $envelope,
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $response = $this->client($this->account())->settleCart(['downstream_id' => 11]);

        $this->assertSame($envelope['status'], $response->status);
        $this->assertSame($envelope, $response->envelope());
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidSettlementReferences')]
    public function test_settlement_rejects_responses_without_a_usable_reference(mixed $reference): void
    {
        Http::fake(function (Request $request) use ($reference) {
            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-one']
                    : ['status' => 200, 'data' => ['invoiceid' => $reference]],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        try {
            $this->client($this->account())->settleCart(['downstream_id' => 11]);
            $this->fail('Expected a malformed settlement response exception.');
        } catch (FinanceException $exception) {
            $this->assertTrue($exception->outcomeIsAmbiguous());
            $this->assertSame('/cart/settle', $exception->safeContext()['endpoint']);
        }

        Http::assertSentCount(2);
    }

    #[DataProvider('conflictingReferenceEnvelopes')]
    public function test_mutation_responses_reject_conflicting_or_multiple_references(array $envelope): void
    {
        Http::fake(function (Request $request) use ($envelope) {
            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-reference-conflict']
                    : $envelope,
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        try {
            $this->client($this->account())->settleCart(['downstream_id' => 11]);
            $this->fail('Expected conflicting supplier references to be rejected.');
        } catch (FinanceException $exception) {
            $this->assertTrue($exception->outcomeIsAmbiguous());
            $this->assertSame('/cart/settle', $exception->safeContext()['endpoint']);
        }

        Http::assertSentCount(2);
    }

    public function test_apply_credit_accepts_only_structurally_valid_status_1001_as_paid(): void
    {
        $creditCount = 0;
        Http::fake(function (Request $request) use (&$creditCount) {
            if (str_ends_with($request->url(), '/zjmf_api_login')) {
                return Http::response(
                    ['status' => 200, 'jwt' => 'jwt-credit-profile'],
                    200,
                    ['Content-Type' => 'application/json'],
                );
            }

            $creditCount++;

            return Http::response(
                $creditCount === 1
                    ? ['status' => 1001, 'msg' => 'paid', 'data' => []]
                    : ['status' => 200, 'msg' => 'request accepted', 'data' => []],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $client = $this->client($this->account());
        $response = $client->applyCredit(['invoiceid' => 'invoice-501']);
        $this->assertSame(1001, $response->status);

        try {
            $client->applyCredit(['invoiceid' => 'invoice-502']);
            $this->fail('Expected application status 200 not to confirm payment.');
        } catch (FinanceException $exception) {
            $this->assertTrue($exception->outcomeIsAmbiguous());
            $this->assertSame(200, $exception->applicationStatus());
            $this->assertSame('/apply_credit', $exception->safeContext()['endpoint']);
        }

        $this->assertSame(2, $creditCount);
        Http::assertSentCount(3);
    }

    #[DataProvider('malformedApplyCreditEnvelopes')]
    public function test_apply_credit_rejects_malformed_status_1001_envelopes(array $envelope): void
    {
        Http::fake(function (Request $request) use ($envelope) {
            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-malformed-credit']
                    : $envelope,
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        try {
            $this->client($this->account())->applyCredit(['invoiceid' => 'invoice-501']);
            $this->fail('Expected malformed payment confirmation to be rejected.');
        } catch (FinanceException $exception) {
            $this->assertTrue($exception->outcomeIsAmbiguous());
            $this->assertSame(1001, $exception->applicationStatus());
            $this->assertSame('/apply_credit', $exception->safeContext()['endpoint']);
        }

        Http::assertSentCount(2);
    }

    public function test_host_header_requires_host_data(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-one']
                    : ['status' => 200, 'data' => []],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        try {
            $this->client($this->account())->hostHeader(11);
            $this->fail('Expected a malformed host response exception.');
        } catch (FinanceException $exception) {
            $this->assertFalse($exception->outcomeIsAmbiguous());
            $this->assertSame('/host/header', $exception->safeContext()['endpoint']);
        }

        Http::assertSentCount(2);
    }

    #[DataProvider('genericMutationMethods')]
    public function test_generic_mutations_require_a_usable_success_result(
        string $method,
        array $parameters,
        string $path,
    ): void {
        Http::fake(function (Request $request) {
            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-one']
                    : ['status' => 200, 'msg' => '', 'data' => []],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        try {
            $this->client($this->account())->{$method}($parameters);
            $this->fail('Expected a malformed mutation response exception.');
        } catch (FinanceException $exception) {
            $this->assertTrue($exception->outcomeIsAmbiguous());
            $this->assertSame($path, $exception->safeContext()['endpoint']);
        }

        Http::assertSentCount(2);
    }

    public function test_it_rejects_and_redacts_supplier_errors(): void
    {
        Http::fake([
            'supplier.test/zjmf_api_login' => Http::response(
                ['status' => 200, 'jwt' => 'jwt-sensitive'],
                200,
                ['Content-Type' => 'application/json'],
            ),
            'supplier.test/api/product/list*' => Http::response(
                ['status' => 400, 'msg' => 'password=api-password Bearer jwt-sensitive'],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        try {
            $this->client($this->account())->products();
            $this->fail('Expected a supplier exception.');
        } catch (FinanceException $exception) {
            $serialized = json_encode([
                'message' => $exception->getMessage(),
                'context' => $exception->safeContext(),
            ]);
            $this->assertStringNotContainsString('api-password', $serialized);
            $this->assertStringNotContainsString('jwt-sensitive', $serialized);
            $this->assertStringNotContainsString('api-user', $serialized);
            $this->assertSame(400, $exception->applicationStatus());
        }
    }

    public function test_it_redacts_success_messages_before_exposing_them(): void
    {
        Http::fake([
            'supplier.test/zjmf_api_login' => Http::response(
                ['status' => 200, 'jwt' => 'jwt-success-secret'],
                200,
                ['Content-Type' => 'application/json'],
            ),
            'supplier.test/cart/clear' => Http::response(
                [
                    'status' => 200,
                    'msg' => 'password=api-password Bearer jwt-success-secret token=operation-secret',
                ],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->client($this->account())->clearCart([
            'downstream_token' => 'operation-secret',
        ]);

        $this->assertStringNotContainsString('api-password', $response->message);
        $this->assertStringNotContainsString('jwt-success-secret', $response->message);
        $this->assertStringNotContainsString('operation-secret', $response->message);
        $this->assertStringContainsString('[REDACTED]', $response->message);
    }

    #[DataProvider('malformedCatalogEnvelopes')]
    public function test_it_rejects_malformed_catalog_success_envelopes(
        string $method,
        array $arguments,
        array $envelope,
        string $endpoint,
    ): void {
        Http::fake(function (Request $request) use ($envelope) {
            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-one']
                    : $envelope,
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        try {
            $this->client($this->account())->{$method}(...$arguments);
            $this->fail('Expected an endpoint-specific envelope exception.');
        } catch (FinanceException $exception) {
            $this->assertSame(200, $exception->applicationStatus());
            $this->assertSame($endpoint, $exception->safeContext()['endpoint']);
            $this->assertStringContainsString('success envelope', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_it_rejects_unsafe_base_urls_before_sending_requests(): void
    {
        foreach ([
            'http://supplier.test',
            'https://user:password@supplier.test',
            'https://supplier.test?token=secret',
            'https://supplier.test/path/../admin',
            'https://localhost',
            'https://127.0.0.1',
            'https://10.0.0.1',
            'https://100.64.0.1',
            'https://2130706433',
            'https://[::1]',
            'https://[fc00::1]',
        ] as $url) {
            try {
                $this->client($this->account($url));
                $this->fail('Expected an unsafe URL exception for '.$url);
            } catch (FinanceException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertInstanceOf(FinanceClient::class, $this->client($this->account()));
        Http::assertNothingSent();
    }

    public function test_it_validates_dns_answers_and_pins_each_request(): void
    {
        $resolveCount = 0;
        $resolveOptions = [];
        Http::fake(function (Request $request, array $options) use (&$resolveOptions) {
            $resolveOptions[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

            return Http::response(
                str_ends_with($request->url(), '/zjmf_api_login')
                    ? ['status' => 200, 'jwt' => 'jwt-one']
                    : ['status' => 200, 'data' => ['list' => []]],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $client = $this->client($this->account(), function () use (&$resolveCount): array {
            $resolveCount++;

            return ['8.8.8.8', '2001:4860:4860::8888'];
        });
        $client->products();

        $this->assertSame(2, $resolveCount);
        $this->assertSame([
            ['supplier.test:443:8.8.8.8'],
            ['supplier.test:443:8.8.8.8'],
        ], $resolveOptions);

        Http::fake();
        try {
            $this->client($this->account(), fn (): array => ['8.8.8.8', '10.0.0.1'])->products();
            $this->fail('Expected a non-global DNS answer to be rejected.');
        } catch (FinanceException $exception) {
            $this->assertStringContainsString('not publicly routable', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_invalid_cached_ciphertext_is_evicted_and_reauthenticated(): void
    {
        $loginCount = 0;
        Http::fake(function (Request $request) use (&$loginCount) {
            if (str_ends_with($request->url(), '/zjmf_api_login')) {
                $loginCount++;

                return Http::response(
                    ['status' => 200, 'jwt' => 'fresh-jwt'],
                    200,
                    ['Content-Type' => 'application/json'],
                );
            }

            return Http::response(
                ['status' => 200, 'data' => ['list' => []]],
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        $account = $this->account();
        Cache::put($this->jwtCacheKey($account), 'plaintext-or-corrupt-jwt');
        $this->client($account)->products();

        $this->assertSame(1, $loginCount);
        $this->assertSame('fresh-jwt', Crypt::decryptString(Cache::get($this->jwtCacheKey($account))));
    }

    public static function authStatuses(): array
    {
        return [
            'unauthorized' => [401],
            'legacy token expired' => [405],
        ];
    }

    public static function mutationAuthCases(): array
    {
        $mutations = [
            'clear cart' => [
                'clearCart',
                [
                    'downstream_url' => 'https://billing.test',
                    'downstream_token' => 'operation-secret',
                    'downstream_id' => 11,
                ],
                '/cart/clear',
            ],
            'add to cart' => [
                'addToCart',
                [
                    'pid' => 'product/42',
                    'billingcycle' => 'month',
                    'qty' => 1,
                    'configoption' => ['image' => 'ubuntu'],
                ],
                '/cart/add_to_shop',
            ],
            'settle cart' => [
                'settleCart',
                [
                    'downstream_url' => 'https://billing.test',
                    'downstream_token' => 'operation-secret',
                    'downstream_id' => 11,
                ],
                '/cart/settle',
            ],
            'apply credit' => [
                'applyCredit',
                ['invoiceid' => 'invoice/501', 'use_credit' => 1, 'enough' => 1],
                '/apply_credit',
            ],
        ];
        $cases = [];

        foreach (self::authStatuses() as $statusName => [$status]) {
            foreach ($mutations as $mutationName => [$method, $parameters, $path]) {
                $cases[$mutationName.' '.$statusName] = [$method, $parameters, $path, $status];
            }
        }

        return $cases;
    }

    public static function settlementReferenceForms(): array
    {
        return [
            'top-level invoice ID' => [['status' => 1001, 'invoiceid' => 'invoice-501']],
            'data invoice ID' => [['status' => 200, 'data' => ['invoiceid' => 501]]],
            'top-level host ID' => [['status' => 200, 'hostid' => 'host-601']],
            'data host ID list' => [['status' => 200, 'data' => ['hostid' => [601]]]],
            'matching duplicated IDs' => [[
                'status' => 200,
                'invoiceid' => 'invoice-501',
                'hostid' => 'host-601',
                'data' => [
                    'invoiceid' => 'invoice-501',
                    'hostid' => 'host-601',
                ],
            ]],
        ];
    }

    public static function invalidSettlementReferences(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'zero integer' => [0],
            'zero string' => ['0'],
            'empty list' => [[]],
            'multiple IDs' => [[501, 502]],
            'associative array' => [['id' => 501]],
            'control character' => ["invoice\0id"],
            'overlong ID' => [str_repeat('a', 129)],
        ];
    }

    public static function conflictingReferenceEnvelopes(): array
    {
        return [
            'conflicting invoice IDs' => [[
                'status' => 200,
                'invoiceid' => 'invoice-top',
                'data' => ['invoiceid' => 'invoice-data'],
            ]],
            'conflicting host IDs' => [[
                'status' => 200,
                'hostid' => 'host-top',
                'data' => ['hostid' => 'host-data'],
            ]],
            'multiple invoice IDs' => [[
                'status' => 200,
                'data' => ['invoiceid' => ['invoice-one', 'invoice-two']],
            ]],
            'multiple host IDs' => [[
                'status' => 200,
                'data' => ['hostid' => ['host-one', 'host-two']],
            ]],
            'duplicate host list still has multiple values' => [[
                'status' => 200,
                'data' => ['hostid' => ['host-one', 'host-one']],
            ]],
        ];
    }

    public static function malformedApplyCreditEnvelopes(): array
    {
        return [
            'message is not a string' => [['status' => 1001, 'msg' => ['paid']]],
            'data is not an object' => [['status' => 1001, 'data' => 'paid']],
            'conflicting invoice IDs' => [[
                'status' => 1001,
                'invoiceid' => 'invoice-one',
                'data' => ['invoiceid' => 'invoice-two'],
            ]],
            'multiple host IDs' => [[
                'status' => 1001,
                'data' => ['hostid' => ['host-one', 'host-two']],
            ]],
            'unsafe invoice ID' => [[
                'status' => 1001,
                'data' => ['invoiceid' => "invoice\0id"],
            ]],
        ];
    }

    public static function genericMutationMethods(): array
    {
        return [
            'clear cart' => ['clearCart', ['downstream_id' => 11], '/cart/clear'],
            'add to cart' => ['addToCart', ['pid' => 42], '/cart/add_to_shop'],
        ];
    }

    public static function malformedCatalogEnvelopes(): array
    {
        return [
            'products list is missing' => [
                'products',
                [],
                ['status' => 200, 'data' => ['products' => []]],
                '/api/product/list',
            ],
            'product id is missing' => [
                'product',
                [42],
                ['status' => 200, 'data' => ['product' => ['name' => 'Compute']]],
                '/api/product/42',
            ],
        ];
    }

    private function account(string $baseUrl = 'https://supplier.test'): SupplierAccount
    {
        return SupplierAccount::create([
            'name' => 'Supplier '.uniqid(),
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => $baseUrl,
            'credentials' => [
                'username' => 'api-user',
                'password' => 'api-password',
            ],
        ]);
    }

    private function client(SupplierAccount $account, ?callable $resolver = null): FinanceClient
    {
        return new FinanceClient($account, $resolver ?? fn (): array => ['8.8.8.8']);
    }

    private function jwtCacheKey(SupplierAccount $account): string
    {
        return 'idcsmart_finance:jwt:'.hash('sha256', implode('|', [
            (string) $account->getKey(),
            rtrim((string) $account->base_url, '/'),
            'api-user',
            'api-password',
        ]));
    }
}

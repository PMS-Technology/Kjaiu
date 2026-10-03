<?php

namespace App\Integrations\Upstream;

use App\Models\FinanceApi;
use App\Models\Host;
use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Client for a remote 智简魔方财务 / ZJMF installation, and the single entry
 * point every other subsystem uses to reach an upstream supplier.
 *
 * Protocol (see /v1 docs):
 *   - POST /v1/login_api {account, password} -> data.jwt
 *   - every later call carries `authorization: JWT <jwt>`
 *   - the envelope is {status, msg, data} where status 200 is success and
 *     1001 means "nothing to pay"
 *
 * No method on this class throws: transport faults, auth failures and
 * malformed replies all collapse into the same failed envelope, because the
 * automation layer runs unattended.
 */
class SupplierClient
{
    /** JWT lifetime granted by the upstream; we refresh a little early. */
    protected const TOKEN_TTL = 7200;

    /** Safety margin so a token never expires mid-flight. */
    protected const TOKEN_SKEW = 120;

    protected FinanceApi $api;

    /** Requests fired during this instance's lifetime, for diagnostics. */
    protected array $calls = [];

    public function __construct(FinanceApi $api)
    {
        $this->api = $api;
    }

    public function financeApi(): FinanceApi
    {
        return $this->api;
    }

    /**
     * Verify the supplier is reachable and the credentials still work.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function testConnection(): array
    {
        if ($this->api->baseUrl() === '') {
            return UpstreamResponse::fail('上游接口地址未填写')->toArray();
        }

        $token = $this->token(true);

        if ($token === null) {
            $this->markStatus(FinanceApi::STATUS_ERROR);

            return UpstreamResponse::fail('链接失败：账号或API密钥不正确')->toArray();
        }

        $credit = $this->credit();

        $this->markStatus($credit['status'] ? FinanceApi::STATUS_OK : FinanceApi::STATUS_ERROR);

        return [
            'status' => $credit['status'],
            'msg' => $credit['status'] ? '链接成功' : (string) $credit['msg'],
            'data' => [
                'data' => $credit['data'],
                'desc' => $credit['status'] ? '链接成功' : '链接失败',
            ],
        ];
    }

    /**
     * Upstream product catalogue.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function products(): array
    {
        return $this->get('products');
    }

    /**
     * Detail (configuration options, pricing) for one upstream product.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function productDetail(int $upstreamPid): array
    {
        return $this->get('productsconfig', ['product_id' => $upstreamPid]);
    }

    /**
     * Place an order upstream: push the item into the remote cart, then check
     * it out. The real platform takes two calls, so we mirror that; the
     * resulting invoice id is what the caller records against the local order.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function createOrder(array $payload): array
    {
        $cart = $this->addToCart($payload);

        if (! $cart['status']) {
            return $cart;
        }

        // The add-to-cart reply is a bare `{"status":200,"msg":"Added successfully"}`
        // with no position, so the index has to come from the cart itself. Only
        // the entry we just appended is ours: checking out every position would
        // also buy whatever else the upstream account had left in its cart.
        $position = $this->lastCartPosition();

        return $this->checkout([$position], (string) ($payload['payment'] ?? ''));
    }

    /**
     * Index of the most recently added line in the upstream cart.
     *
     * The cart listing carries no explicit position field — the index of an
     * entry within `cart_products` is the value `cart/checkout` expects, and a
     * newly added product is appended to the end.
     */
    public function lastCartPosition(): int
    {
        $result = $this->get('cart');

        if (! $result['status']) {
            return 0;
        }

        $data = is_array($result['data']) ? $result['data'] : [];
        $items = $data['cart_products'] ?? [];

        if (! is_array($items) || $items === []) {
            return 0;
        }

        return max(0, count(array_values($items)) - 1);
    }

    /**
     * Add a product to the upstream cart (step one of createOrder).
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function addToCart(array $payload): array
    {
        if (empty($payload['product_id'])) {
            return UpstreamResponse::fail('缺少上游产品ID')->toArray();
        }

        $body = [
            'product_id' => (int) $payload['product_id'],
            'billingcycle' => (string) ($payload['billingcycle'] ?? 'monthly'),
            'qty' => (int) ($payload['qty'] ?? 1),
            'host' => (string) ($payload['host'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
        ];

        if (isset($payload['configoption']) && $payload['configoption'] !== []) {
            $body['configoption'] = is_string($payload['configoption'])
                ? $payload['configoption']
                : json_encode($payload['configoption'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($payload['customfield']) && $payload['customfield'] !== []) {
            $body['customfield'] = is_string($payload['customfield'])
                ? $payload['customfield']
                : json_encode($payload['customfield'], JSON_UNESCAPED_UNICODE);
        }

        return $this->post('cart/products', $body);
    }

    /**
     * Check out the upstream cart for the given positions.
     *
     * `payment` is the gateway identifier; the live platform rejects unknown
     * values with "Wrong payment method" and treats an empty string as "use
     * the account's default gateway", which is what an unattended order wants.
     *
     * @param  array<int, int|string>  $positions
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function checkout(array $positions = [0], string $payment = ''): array
    {
        $result = $this->post('cart/checkout', [
            'payment' => $payment,
            'position' => array_values($positions),
        ]);

        if (! $result['status']) {
            return $result;
        }

        $data = is_array($result['data']) ? $result['data'] : [];
        $invoiceId = (int) ($data['invoiceid'] ?? 0);

        return [
            'status' => true,
            'msg' => $invoiceId > 0 ? '下单成功' : '购买成功，无需支付',
            'data' => $data + ['invoiceid' => $invoiceId],
        ];
    }

    /**
     * Provision the upstream half of a service.
     *
     * The upstream has no "create host" call of its own: the service exists
     * once the cart is checked out. We therefore order, pay from the upstream
     * balance when possible, then resolve the freshly created upstream host.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function createAccount(Host $host): array
    {
        $product = $host->product;

        if ($product === null || (int) $product->upstream_pid <= 0) {
            return UpstreamResponse::fail('该产品未关联上游商品')->toArray();
        }

        $payload = [
            'product_id' => (int) $product->upstream_pid,
            'billingcycle' => (string) ($host->billingcycle ?: 'monthly'),
            'qty' => 1,
            'host' => (string) $host->domain,
            'password' => (string) $host->password,
        ];

        $options = $this->decodeJson($host->upstream_configoption);

        if ($options !== []) {
            if (isset($options['configoption'])) {
                $payload['configoption'] = $options['configoption'];
            }

            if (isset($options['customfield'])) {
                $payload['customfield'] = $options['customfield'];
            }
        }

        $ordered = $this->createOrder($payload);

        if (! $ordered['status']) {
            return $ordered;
        }

        $invoiceId = (int) ($ordered['data']['invoiceid'] ?? 0);

        if ($invoiceId > 0) {
            $paid = $this->payInvoice($invoiceId);

            if (! $paid['status']) {
                // Order exists upstream; report the payment failure so the
                // admin can settle it manually rather than losing the order.
                return [
                    'status' => false,
                    'msg' => '上游下单成功但支付失败：' . (string) $paid['msg'],
                    'data' => $ordered['data'] + ['paid' => false],
                ];
            }
        }

        $resolved = $this->resolveUpstreamHost($host);

        return [
            'status' => true,
            'msg' => '开通成功',
            'data' => [
                'invoiceid' => $invoiceId,
                'upstream_host_id' => $resolved['id'] ?? 0,
                'dedicatedip' => $resolved['dedicatedip'] ?? '',
                'assignedips' => $resolved['assignedips'] ?? [],
                'username' => $resolved['username'] ?? '',
                'password' => $resolved['password'] ?? $payload['password'],
            ],
        ];
    }

    /**
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function suspendAccount(Host $host): array
    {
        return $this->moduleAction($host, 'off');
    }

    /**
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function unsuspendAccount(Host $host): array
    {
        return $this->moduleAction($host, 'on');
    }

    /**
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function terminateAccount(Host $host): array
    {
        $id = $this->upstreamHostId($host);

        if ($id <= 0) {
            return UpstreamResponse::fail('未找到对应的上游产品')->toArray();
        }

        return $this->delete("hosts/{$id}");
    }

    /**
     * Renew the upstream service for another cycle.
     *
     * The upstream renew call is a page (GET) plus a submit (POST); we ask for
     * the page again after submitting so the caller can read the new due date.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function renewHost(Host $host): array
    {
        $id = $this->upstreamHostId($host);

        if ($id <= 0) {
            return UpstreamResponse::fail('未找到对应的上游产品')->toArray();
        }

        $result = $this->post("hosts/{$id}/renew", [
            'billingcycle' => (string) ($host->billingcycle ?: 'monthly'),
            // Empty means "use the account's default gateway"; the live
            // platform rejects an unrecognised identifier such as "credit".
            'payment' => '',
        ]);

        if (! $result['status']) {
            return $result;
        }

        $page = $this->get("hosts/{$id}/renew");

        return [
            'status' => true,
            'msg' => '续费成功',
            'data' => [
                'renew' => $result['data'],
                'page' => $page['status'] ? $page['data'] : null,
            ],
        ];
    }

    /**
     * Power / provisioning state of the upstream service.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function hostStatus(Host $host): array
    {
        $id = $this->upstreamHostId($host);

        if ($id <= 0) {
            return UpstreamResponse::fail('未找到对应的上游产品')->toArray();
        }

        return $this->get("hosts/{$id}/module/status", ['type' => 'host']);
    }

    /**
     * Reset the upstream service password.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function changePassword(Host $host, string $password): array
    {
        return $this->moduleAction($host, 'repassword', ['password' => $password]);
    }

    /**
     * Reinstall the upstream service operating system.
     *
     * @param  array<string, mixed>  $options
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function reinstall(Host $host, array $options = []): array
    {
        $osId = (string) ($options['os_id'] ?? $host->dcim_os ?? '');

        if ($osId === '') {
            return UpstreamResponse::fail('未指定操作系统')->toArray();
        }

        $payload = ['os_id' => $osId];

        if (! empty($options['password'])) {
            $payload['dcim'] = [
                'password' => (string) $options['password'],
                'port' => (int) ($options['port'] ?? 22),
                'part_type' => (int) ($options['part_type'] ?? 0),
            ];
        }

        return $this->moduleAction($host, 'reinstall', $payload);
    }

    /**
     * Upstream balance plus the currency it is expressed in.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function credit(): array
    {
        $result = $this->get('funds');

        if (! $result['status']) {
            return $result;
        }

        $data = is_array($result['data']) ? $result['data'] : [];

        // The upstream names the currency block `currency` in some builds and
        // `_currency` in others, and may nest it a level down.
        $currency = $data['currency'] ?? $data['_currency'] ?? null;

        if (! is_array($currency) && isset($data['data']['_currency'])) {
            $currency = $data['data']['_currency'];
        }

        if (is_array($currency) && array_is_list($currency)) {
            $currency = $currency[0] ?? null;
        }

        return [
            'status' => true,
            'msg' => (string) $result['msg'],
            'data' => [
                'credit' => $data['credit'] ?? ($data['balance'] ?? 0),
                'currency' => is_array($currency) ? $currency : ['prefix' => '', 'suffix' => '', 'code' => 'CNY'],
            ],
        ];
    }

    /**
     * Low-level escape hatch: one arbitrary call against the upstream.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function request(string $method, string $path, array $payload = []): array
    {
        $method = strtoupper($method);

        return match ($method) {
            'GET', 'HEAD' => $this->get($path, $payload),
            'DELETE' => $this->delete($path, $payload),
            'PUT' => $this->send($method, $path, $payload),
            'PATCH' => $this->send($method, $path, $payload),
            default => $this->post($path, $payload),
        };
    }

    // ---------------------------------------------------------------------
    // Transport
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->send('POST', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function put(string $path, array $payload = []): array
    {
        return $this->send('PUT', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function delete(string $path, array $payload = []): array
    {
        return $this->send('DELETE', $path, $payload);
    }

    /**
     * Perform one authenticated exchange, re-authenticating once on a 401.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status:bool,msg:string,data:mixed}
     */
    protected function send(string $method, string $path, array $payload = [], bool $isRetry = false, bool $forceAuth = false): array
    {
        $base = $this->api->baseUrl();

        if ($base === '') {
            return UpstreamResponse::fail('上游接口地址未填写')->toArray();
        }

        $token = $this->token($forceAuth);

        if ($token === null) {
            return UpstreamResponse::fail('上游接口登录失败')->toArray();
        }

        $url = $base . '/v1/' . ltrim($path, '/');
        $started = microtime(true);

        try {
            $response = $this->pending($token)->send($method, $url, [
                'query' => $method === 'GET' ? $this->normaliseQuery($payload) : [],
                'json' => $method === 'GET' ? [] : $payload,
            ]);
        } catch (ConnectionException $e) {
            $duration = (microtime(true) - $started) * 1000;

            $this->log($method, $path, 0, $duration, false, $e->getMessage());

            return UpstreamResponse::fail(
                '上游接口连接失败：' . $e->getMessage(),
                null,
                0,
                true,
            )->toArray();
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $started) * 1000;

            $this->log($method, $path, 0, $duration, false, $e->getMessage());

            return UpstreamResponse::fail('上游接口异常：' . $e->getMessage())->toArray();
        }

        $duration = (microtime(true) - $started) * 1000;
        $httpStatus = $response->status();

        // Expired or revoked token. A bare HTTP 401 means the same thing, and
        // the live platform additionally reports it as `status` 405 inside a
        // 200 response ("请登陆后再试"), so both shapes must re-authenticate.
        if (! $isRetry && ($httpStatus === 401 || $this->isUnauthenticatedBody($response))) {
            $this->forgetToken();
            $this->log($method, $path, $httpStatus, $duration, false, 'token expired, retrying');

            return $this->send($method, $path, $payload, true, true);
        }

        $parsed = $this->parse($response, $method, $path, $duration);

        $this->log(
            $method,
            $path,
            $httpStatus,
            $duration,
            (bool) $parsed['status'],
            (string) $parsed['msg'],
        );

        return $parsed;
    }

    /**
     * Whether a reply signals a missing or expired token without using 401.
     *
     * The live platform answers an unauthenticated call with `status` 405 and
     * the message "请登陆后再试" inside an HTTP 200 response, so the HTTP status
     * alone is not enough to detect it.
     */
    protected function isUnauthenticatedBody(Response $response): bool
    {
        $json = $response->json();

        if (! is_array($json)) {
            return false;
        }

        $status = $json['status'] ?? null;

        return $status === 405 || $status === '405';
    }

    /**
     * Build the pending request with the shared timeout and auth header.
     *
     * `throwIf` is disabled so a 4xx arrives as a normal response: the 401
     * re-authentication path needs to inspect the status rather than catch it.
     */
    protected function pending(?string $token): PendingRequest
    {
        $request = Http::timeout($this->timeout())
            ->throwIf(false)
            // The retry helper has its own throw flag, which defaults to true
            // and would bubble a 4xx out as an exception before we can inspect
            // it; disable it so failures stay in the response object.
            ->retry(2, 200, function (\Throwable $e, PendingRequest $request) {
                // Only transport faults are worth retrying; a business error
                // from the upstream would fail identically on a second try.
                return $e instanceof ConnectionException;
            }, throw: false)
            ->acceptJson()
            ->asJson();

        if ($token !== null && $token !== '') {
            $request = $request->withHeaders(['authorization' => 'JWT ' . $token]);
        }

        return $request;
    }

    /**
     * Normalise the upstream envelope into our three-key shape.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    protected function parse(Response $response, string $method, string $path, float $duration): array
    {
        $httpStatus = $response->status();

        if ($response->clientError() && $httpStatus !== 401) {
            return UpstreamResponse::fail(
                '上游接口返回错误（HTTP ' . $httpStatus . '）',
                null,
                $httpStatus,
            )->toArray();
        }

        if ($response->serverError()) {
            return UpstreamResponse::fail(
                '上游接口服务异常（HTTP ' . $httpStatus . '）',
                null,
                $httpStatus,
                true,
            )->toArray();
        }

        $json = $response->json();

        if (! is_array($json)) {
            return UpstreamResponse::fail('上游接口返回格式无法解析', $response->body(), $httpStatus)->toArray();
        }

        // Some builds double-wrap the envelope: {status,msg,data:{status,msg,data}}.
        // Unwrap only when the inner payload is unambiguously another envelope —
        // a business `data.status` (host power state, invoice status) must not
        // be mistaken for one, so a `msg` alongside it is required.
        if (isset($json['data']) && is_array($json['data'])) {
            $inner = $json['data'];

            if (isset($inner['status'], $inner['msg']) && array_key_exists('data', $inner) && ! array_is_list($inner)) {
                $json = $inner;
            }
        }

        $status = $json['status'] ?? null;
        $msg = (string) ($json['msg'] ?? '');
        $data = $json['data'] ?? null;

        if ($status === 200 || $status === '200') {
            return ['status' => true, 'msg' => $msg !== '' ? $msg : '请求成功', 'data' => $data];
        }

        if ($status === 1001 || $status === '1001') {
            // Nothing to pay: still a success for our purposes.
            return ['status' => true, 'msg' => $msg !== '' ? $msg : '无需支付', 'data' => $data];
        }

        return [
            'status' => false,
            'msg' => $msg !== '' ? $msg : '上游接口请求失败',
            'data' => $data,
        ];
    }

    protected function normaliseQuery(array $query): array
    {
        foreach ($query as $key => $value) {
            if (is_bool($value)) {
                $query[$key] = $value ? 1 : 0;
            }

            if (is_array($value)) {
                $query[$key] = array_values($value);
            }
        }

        return $query;
    }

    protected function timeout(): int
    {
        return (int) config('kjaiu.upstream.timeout', 30);
    }

    // ---------------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------------

    /**
     * Cached JWT for this supplier, logging in when there is none.
     */
    protected function token(bool $force = false): ?string
    {
        if (($this->api->baseUrl() ?? '') === '') {
            return null;
        }

        if ($force) {
            $this->forgetToken();
        }

        $key = $this->tokenKey();
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->login();
    }

    /**
     * POST /v1/login_api and cache the returned JWT.
     *
     * Deliberately not routed through send(): login is the one call that must
     * not carry a token, and routing it through send() would recurse.
     */
    protected function login(): ?string
    {
        $url = $this->api->baseUrl() . '/v1/login_api';
        $started = microtime(true);

        try {
            $response = Http::timeout($this->timeout())
                ->retry(2, 200, fn (\Throwable $e) => $e instanceof ConnectionException)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'account' => (string) $this->api->username,
                    'password' => (string) $this->api->password,
                ]);
        } catch (\Throwable $e) {
            $this->log('POST', 'login_api', 0, (microtime(true) - $started) * 1000, false, $e->getMessage());

            return null;
        }

        $duration = (microtime(true) - $started) * 1000;
        $json = $response->json();
        $jwt = $this->extractJwt(is_array($json) ? $json : null);

        if ($jwt === null) {
            // Surface the upstream's own message so a credential problem is
            // distinguishable from an unexpected payload shape.
            $msg = is_array($json) ? (string) ($json['msg'] ?? '') : '';
            $detail = $msg !== '' ? $msg : 'no jwt in response';

            $this->log('POST', 'login_api', $response->status(), $duration, false, $detail);

            return null;
        }

        $ttl = max(60, $this->jwtTtl($jwt));
        Cache::put($this->tokenKey(), $jwt, $ttl);

        $this->log('POST', 'login_api', $response->status(), $duration, true, 'authenticated');

        return $jwt;
    }

    /**
     * Pull the JWT out of a login reply.
     *
     * The live platform returns it at the top level next to `status`:
     *   {"jwt":"...", "status":200, "msg":"login successful"}
     * Older builds (and the shape this class originally assumed) nest it under
     * `data`. Accept both so neither upstream generation breaks the handshake.
     *
     * @param  array<string, mixed>|null  $json
     */
    protected function extractJwt(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        foreach ([$json['jwt'] ?? null, $json['data']['jwt'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Use the token's own `exp` when present so a short-lived token is not
     * cached past its expiry; otherwise trust the documented 2h lifetime.
     */
    protected function jwtTtl(string $jwt): int
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return self::TOKEN_TTL - self::TOKEN_SKEW;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $exp = is_array($payload) ? ($payload['exp'] ?? null) : null;

        if (! is_numeric($exp)) {
            return self::TOKEN_TTL - self::TOKEN_SKEW;
        }

        return max(60, (int) $exp - time() - self::TOKEN_SKEW);
    }

    protected function forgetToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    /**
     * Cache key carries the supplier id so two upstreams never share a token.
     */
    protected function tokenKey(): string
    {
        return 'kjaiu.upstream.jwt.' . (int) $this->api->id;
    }

    // ---------------------------------------------------------------------
    // Host helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $extra
     * @return array{status:bool,msg:string,data:mixed}
     */
    protected function moduleAction(Host $host, string $action, array $extra = []): array
    {
        $id = $this->upstreamHostId($host);

        if ($id <= 0) {
            return UpstreamResponse::fail('未找到对应的上游产品')->toArray();
        }

        return $this->post("hosts/{$id}/module/{$action}", $extra);
    }

    /**
     * The upstream's own id for a local host.
     *
     * The mirrored schema has no dedicated column, so the id is carried in the
     * product's upstream payload and, failing that, recovered by matching the
     * service's domain in the upstream host list.
     */
    public function upstreamHostId(Host $host): int
    {
        $options = $this->decodeJson($host->upstream_configoption);

        foreach (['upstream_host_id', 'host_id', 'id'] as $key) {
            if (! empty($options[$key])) {
                return (int) $options[$key];
            }
        }

        $resolved = $this->resolveUpstreamHost($host);

        return (int) ($resolved['id'] ?? 0);
    }

    /**
     * Find the upstream host row matching this local service.
     *
     * @return array<string, mixed>
     */
    public function resolveUpstreamHost(Host $host): array
    {
        $domain = trim((string) $host->domain);

        if ($domain === '') {
            return [];
        }

        $result = $this->get('hosts', [
            'keywords' => $domain,
            'page' => 1,
            'limit' => 50,
        ]);

        if (! $result['status']) {
            return [];
        }

        foreach ($this->hostRows($result['data']) as $row) {
            if (trim((string) ($row['domain'] ?? '')) === $domain) {
                return $row;
            }
        }

        return [];
    }

    /**
     * Pull the host list out of whichever envelope depth the upstream used.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function hostRows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        foreach (['host', '_host', 'list'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_filter($data[$key], 'is_array');
            }
        }

        // Either a bare list of hosts, or a single host payload.
        if (array_is_list($data)) {
            return array_filter($data, 'is_array');
        }

        return isset($data['domain']) ? [$data] : [];
    }

    /**
     * Pay an upstream invoice from the upstream account balance.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function payInvoice(int $invoiceId): array
    {
        return $this->post("invoices/{$invoiceId}/fund", []);
    }

    protected function markStatus(int $status): void
    {
        try {
            if ((int) $this->api->status !== $status) {
                $this->api->status = $status;
                $this->api->save();
            }
        } catch (\Throwable) {
            // A read-only replica must not break connectivity checks.
        }
    }

    protected function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(
        string $method,
        string $path,
        int $httpStatus,
        float $duration,
        bool $ok,
        string $msg,
        array $context = [],
    ): void {
        $this->calls[] = [
            'method' => $method,
            'path' => $path,
            'status' => $httpStatus,
            'duration' => $duration,
            'ok' => $ok,
            'msg' => $msg,
        ];

        UpstreamCallLog::record(
            (int) $this->api->id,
            $method,
            $path,
            $httpStatus,
            $duration,
            $ok,
            $msg,
            $context,
        );
    }

    /**
     * Calls made through this instance, for tests and diagnostics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}

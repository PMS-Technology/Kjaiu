<?php

namespace App\Integrations\Idcsmart;

use App\Models\SupplierAccount;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class FinanceClient
{
    private const JWT_TTL_SECONDS = 7200;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const REQUEST_TIMEOUT_SECONDS = 30;

    private const PROFILE_PRODUCTS = 'products';

    private const PROFILE_PRODUCT = 'product';

    private const PROFILE_SET_CONFIG = 'set_config';

    private const PROFILE_QUOTE = 'quote';

    private const PROFILE_GENERIC_MUTATION = 'generic_mutation';

    private const PROFILE_SETTLEMENT = 'settlement';

    private const PROFILE_APPLY_CREDIT = 'apply_credit';

    private const PROFILE_HOST_HEADER = 'host_header';

    private readonly string $baseUrl;

    private readonly string $destinationHost;

    private readonly int $destinationPort;

    private readonly array $credentials;

    private readonly Closure $resolver;

    private array $sensitiveValues = [];

    public function __construct(
        private readonly SupplierAccount $account,
        ?callable $resolver = null,
    ) {
        if ($account->driver !== SupplierAccount::DRIVER_IDCSMART_FINANCE) {
            throw new FinanceException('The supplier account uses an unsupported driver.');
        }
        if ($account->is_active === false) {
            throw new FinanceException('The supplier account is disabled.');
        }

        [$this->baseUrl, $this->destinationHost, $this->destinationPort] =
            $this->normalizeBaseUrl((string) $account->base_url);
        $this->resolver = $resolver === null
            ? Closure::fromCallable([$this, 'resolveHostAddresses'])
            : Closure::fromCallable($resolver);
        $credentials = $account->credentials;
        if (! is_array($credentials)
            || ! is_string($credentials['username'] ?? null)
            || trim($credentials['username']) === ''
            || ! is_string($credentials['password'] ?? null)
            || $credentials['password'] === '') {
            throw new FinanceException('The supplier account credentials are incomplete.');
        }

        $this->credentials = [
            'username' => trim($credentials['username']),
            'password' => $credentials['password'],
        ];
        $this->sensitiveValues = array_values($this->credentials);
    }

    public function products(array $query = []): FinanceResponse
    {
        return $this->request(
            '/api/product/list',
            $query,
            successProfile: self::PROFILE_PRODUCTS,
        );
    }

    public function product(string|int $productId): FinanceResponse
    {
        return $this->request(
            '/api/product/'.$this->encodedIdentifier($productId),
            [],
            successProfile: self::PROFILE_PRODUCT,
        );
    }

    public function setConfig(array $parameters): FinanceResponse
    {
        return $this->safeRequest(
            'GET',
            '/cart/set_config',
            $parameters,
            self::PROFILE_SET_CONFIG,
        );
    }

    public function quote(array $parameters): FinanceResponse
    {
        $response = $this->safeRequest(
            'POST',
            '/cart/get_total',
            $parameters,
            self::PROFILE_QUOTE,
        );
        $quote = $this->normalizedQuote($response->envelope());

        return new FinanceResponse(
            $response->status,
            $response->message,
            array_replace($response->data, ['quote' => $quote]),
            $response->envelope(),
        );
    }

    public function clearCart(array $parameters): FinanceResponse
    {
        return $this->mutationRequest(
            '/cart/clear',
            $parameters,
            self::PROFILE_GENERIC_MUTATION,
            [200, 400],
        );
    }

    public function addToCart(array $parameters): FinanceResponse
    {
        return $this->mutationRequest(
            '/cart/add_to_shop',
            $parameters,
            self::PROFILE_GENERIC_MUTATION,
            [200],
        );
    }

    public function settleCart(array $parameters): FinanceResponse
    {
        return $this->mutationRequest(
            '/cart/settle',
            $parameters,
            self::PROFILE_SETTLEMENT,
            [200, 1001],
        );
    }

    public function applyCredit(array $parameters): FinanceResponse
    {
        return $this->mutationRequest(
            '/apply_credit',
            $parameters,
            self::PROFILE_APPLY_CREDIT,
            [1001],
        );
    }

    public function hostHeader(string|int $hostId): FinanceResponse
    {
        return $this->request(
            '/host/header',
            ['host_id' => $this->identifier($hostId)],
            successProfile: self::PROFILE_HOST_HEADER,
        );
    }

    public static function forgetCachedJwt(SupplierAccount $account): void
    {
        $cacheIdentity = clone $account;
        $cacheIdentity->is_active = true;
        $client = new self($cacheIdentity);
        Cache::forget($client->jwtCacheKey());
    }

    private function request(
        string $path,
        array $parameters,
        string $successProfile,
    ): FinanceResponse {
        return $this->safeRequest('GET', $path, $parameters, $successProfile);
    }

    private function safeRequest(
        string $method,
        string $path,
        array $parameters,
        string $successProfile,
    ): FinanceResponse {
        $this->registerSensitivePayload($parameters);
        $jwt = $this->jwt();
        $envelope = $this->send($method, $path, $parameters, $jwt);

        if (in_array($envelope['status'], [401, 405], true)) {
            $jwt = $this->jwt(true);
            $envelope = $this->send($method, $path, $parameters, $jwt);
        }

        if ($envelope['status'] !== 200) {
            $message = is_string($envelope['msg'] ?? null)
                ? $envelope['msg']
                : 'The supplier rejected the request.';

            throw new FinanceException(
                $this->redact($message),
                200,
                $envelope['status'],
                $this->context($method, $path, $parameters),
            );
        }

        $this->validateSuccessEnvelope($successProfile, $envelope, $path, $parameters, $method, false);

        return new FinanceResponse(
            $envelope['status'],
            is_string($envelope['msg'] ?? null) ? $this->redact($envelope['msg']) : '',
            is_array($envelope['data'] ?? null) ? $envelope['data'] : [],
            $envelope,
        );
    }

    private function mutationRequest(
        string $path,
        array $parameters,
        string $successProfile,
        array $acceptedStatuses,
    ): FinanceResponse {
        $this->registerSensitivePayload($parameters);
        $jwt = $this->jwt();
        $envelope = $this->send('POST', $path, $parameters, $jwt, true);

        if (in_array($envelope['status'], [401, 405], true)) {
            Cache::forget($this->jwtCacheKey());

            throw new FinanceMutationAuthException(
                $envelope['status'],
                $this->context('POST', $path, $parameters),
            );
        }

        if (! in_array($envelope['status'], $acceptedStatuses, true)) {
            $message = is_string($envelope['msg'] ?? null)
                ? $envelope['msg']
                : 'The supplier rejected the request.';

            throw new FinanceException(
                $this->redact($message),
                200,
                $envelope['status'],
                $this->context('POST', $path, $parameters),
                $path === '/apply_credit',
            );
        }

        $this->validateSuccessEnvelope($successProfile, $envelope, $path, $parameters, 'POST', true);

        return new FinanceResponse(
            $envelope['status'],
            is_string($envelope['msg'] ?? null) ? $this->redact($envelope['msg']) : '',
            is_array($envelope['data'] ?? null) ? $envelope['data'] : [],
            $envelope,
        );
    }

    private function jwt(bool $force = false): string
    {
        $cacheKey = $this->jwtCacheKey();
        if ($force) {
            Cache::forget($cacheKey);
        } else {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                try {
                    $jwt = trim(Crypt::decryptString($cached));
                } catch (DecryptException) {
                    Cache::forget($cacheKey);
                    $jwt = '';
                }
                if ($jwt !== '') {
                    $this->sensitiveValues[] = $jwt;

                    return $jwt;
                }
            }
            if ($cached !== null) {
                Cache::forget($cacheKey);
            }
        }

        $envelope = $this->send('POST', '/zjmf_api_login', $this->credentials);
        if ($envelope['status'] !== 200
            || ! is_string($envelope['jwt'] ?? null)
            || trim($envelope['jwt']) === '') {
            $message = is_string($envelope['msg'] ?? null)
                ? $envelope['msg']
                : 'Supplier authentication failed.';

            throw new FinanceException(
                $this->redact($message),
                200,
                $envelope['status'],
                $this->context('POST', '/zjmf_api_login'),
            );
        }

        $jwt = trim($envelope['jwt']);
        $this->sensitiveValues[] = $jwt;
        Cache::put($cacheKey, Crypt::encryptString($jwt), self::JWT_TTL_SECONDS);

        return $jwt;
    }

    private function send(
        string $method,
        string $path,
        array $parameters,
        ?string $jwt = null,
        bool $outcomeAmbiguous = false,
    ): array {
        $this->registerSensitivePayload($parameters);
        $request = $this->pendingRequest($jwt);

        try {
            $response = match ($method) {
                'GET' => $request->get($this->baseUrl.$path, $parameters),
                'POST' => $request->post($this->baseUrl.$path, $parameters),
                default => throw new FinanceException('The supplier request method is unsupported.'),
            };
        } catch (ConnectionException) {
            throw new FinanceException(
                'The supplier connection failed.',
                null,
                null,
                $this->context($method, $path, $parameters),
                $outcomeAmbiguous,
            );
        }

        return $this->decode($response, $method, $path, $parameters, $outcomeAmbiguous);
    }

    private function pendingRequest(?string $jwt): PendingRequest
    {
        $request = Http::acceptJson()
            ->asForm()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->withOptions($this->destinationOptions());

        return $jwt === null ? $request : $request->withToken($jwt);
    }

    private function decode(
        Response $response,
        string $method,
        string $path,
        array $parameters,
        bool $outcomeAmbiguous,
    ): array {
        if ($response->status() !== 200) {
            throw new FinanceException(
                'The supplier returned an unexpected HTTP status.',
                $response->status(),
                null,
                $this->context($method, $path, $parameters),
                $outcomeAmbiguous,
            );
        }

        $contentType = strtolower(trim((string) $response->header('Content-Type')));
        if (! preg_match('/^application\/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)/i', $contentType)) {
            throw new FinanceException(
                'The supplier returned a non-JSON response.',
                200,
                null,
                $this->context($method, $path, $parameters),
                $outcomeAmbiguous,
            );
        }

        try {
            $envelope = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FinanceException(
                'The supplier returned malformed JSON.',
                200,
                null,
                $this->context($method, $path, $parameters),
                $outcomeAmbiguous,
            );
        }

        if (! is_array($envelope)
            || ! array_key_exists('status', $envelope)
            || ! is_int($envelope['status'])) {
            throw new FinanceException(
                'The supplier returned an invalid application envelope.',
                200,
                null,
                $this->context($method, $path, $parameters),
                $outcomeAmbiguous,
            );
        }

        return $envelope;
    }

    private function validateSuccessEnvelope(
        string $profile,
        array $envelope,
        string $path,
        array $parameters,
        string $method = 'GET',
        bool $outcomeAmbiguous = false,
    ): void {
        $fieldTypesAreValid = (! array_key_exists('msg', $envelope) || is_string($envelope['msg']))
            && (! array_key_exists('data', $envelope) || is_array($envelope['data']));
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        [$referencesAreValid, $invoiceId, $hostId] = in_array($profile, [
            self::PROFILE_GENERIC_MUTATION,
            self::PROFILE_SETTLEMENT,
            self::PROFILE_APPLY_CREDIT,
        ], true)
            ? $this->validatedReferences($envelope)
            : [true, null, null];
        $valid = $fieldTypesAreValid && $referencesAreValid && match ($profile) {
            self::PROFILE_PRODUCTS => is_array($data['list'] ?? null),
            self::PROFILE_PRODUCT => is_array($data['product'] ?? null)
                && $this->isIdentifier($data['product']['id'] ?? null),
            self::PROFILE_SET_CONFIG => is_array($envelope['product'] ?? $data['product'] ?? null),
            self::PROFILE_QUOTE => $this->normalizedQuote($envelope) !== null,
            self::PROFILE_GENERIC_MUTATION => $this->hasGenericMutationResult(
                $envelope,
                $invoiceId,
                $hostId,
            ),
            self::PROFILE_SETTLEMENT => $invoiceId !== null || $hostId !== null,
            self::PROFILE_APPLY_CREDIT => $envelope['status'] === 1001,
            self::PROFILE_HOST_HEADER => is_array($data['host_data'] ?? null),
            default => false,
        };

        if (! $valid) {
            $profileName = $profile ?? 'generic';

            throw new FinanceException(
                'The supplier returned an invalid '.$profileName.' success envelope.',
                200,
                $envelope['status'],
                $this->context($method, $path, $parameters),
                $outcomeAmbiguous,
            );
        }
    }

    private function hasGenericMutationResult(
        array $envelope,
        ?string $invoiceId,
        ?string $hostId,
    ): bool {
        if (is_string($envelope['msg'] ?? null) && trim($envelope['msg']) !== '') {
            return true;
        }

        $data = $envelope['data'] ?? null;

        return (is_array($data) && $data !== [])
            || $invoiceId !== null
            || $hostId !== null;
    }

    private function normalizedQuote(array $envelope): ?array
    {
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        if ((array_key_exists('products', $envelope) && ! is_array($envelope['products']))
            || (array_key_exists('products', $data) && ! is_array($data['products']))) {
            return null;
        }
        $products = is_array($envelope['products'] ?? null) ? $envelope['products'] : [];
        $dataProducts = is_array($data['products'] ?? null) ? $data['products'] : [];
        $saleTotals = $this->quoteValues([
            'data.sale_total' => $data['sale_total'] ?? null,
            'sale_total' => $envelope['sale_total'] ?? null,
        ]);
        $totals = $this->quoteValues([
            'products.total' => $products['total'] ?? null,
            'data.products.total' => $dataProducts['total'] ?? null,
            'data.total' => $data['total'] ?? null,
            'total' => $envelope['total'] ?? null,
        ]);
        if ($saleTotals === null || $totals === null) {
            return null;
        }
        if ($saleTotals !== []
            && $totals !== []
            && count(array_unique(array_column([...$saleTotals, ...$totals], 'amount'))) !== 1) {
            return null;
        }
        $amounts = $saleTotals !== [] ? $saleTotals : $totals;
        if ($amounts === [] || count(array_unique(array_column($amounts, 'amount'))) !== 1) {
            return null;
        }

        $currencyValues = $this->quoteCurrencies($envelope, $data);
        if ($currencyValues === null) {
            return null;
        }
        if (count($currencyValues) !== 1) {
            return null;
        }

        return [
            'amount' => $amounts[0]['amount'],
            'currency' => $currencyValues[0],
            'source' => $amounts[0]['source'],
        ];
    }

    private function quoteValues(array $values): ?array
    {
        $normalized = [];
        foreach ($values as $source => $amount) {
            if ($amount === null) {
                continue;
            }
            if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
                return null;
            }
            if (is_float($amount) && ! is_finite($amount)) {
                return null;
            }
            $amount = trim((string) $amount);
            if (preg_match('/\A\d+(?:\.\d{1,2})?\z/', $amount) !== 1) {
                return null;
            }
            [$major, $minor] = array_pad(explode('.', $amount, 2), 2, '');
            if (strlen($major) > 16) {
                return null;
            }
            $normalized[] = [
                'amount' => ltrim($major, '0').'.'.str_pad($minor, 2, '0'),
                'source' => $source,
            ];
            if (str_starts_with($normalized[array_key_last($normalized)]['amount'], '.')) {
                $normalized[array_key_last($normalized)]['amount'] = '0'.$normalized[array_key_last($normalized)]['amount'];
            }
        }

        return $normalized;
    }

    private function quoteCurrencies(array $envelope, array $data): ?array
    {
        $currencies = [];
        foreach ([$envelope, $data] as $source) {
            foreach (['currency', 'currency_code'] as $key) {
                if (! array_key_exists($key, $source)) {
                    continue;
                }
                $currency = $source[$key];
                if ($key === 'currency' && is_array($currency)) {
                    if (! array_key_exists('code', $currency)) {
                        return null;
                    }
                    $currency = $currency['code'];
                }
                if (! is_string($currency)
                    || preg_match('/\A[A-Za-z0-9]{3,8}\z/', trim($currency)) !== 1) {
                    return null;
                }
                $currencies[] = strtoupper(trim($currency));
            }
        }

        return array_values(array_unique($currencies));
    }

    private function validatedReferences(array $envelope): array
    {
        [$invoiceIsValid, $invoiceId] = $this->validatedReference($envelope, 'invoiceid');
        [$hostIsValid, $hostId] = $this->validatedReference($envelope, 'hostid');

        return [$invoiceIsValid && $hostIsValid, $invoiceId, $hostId];
    }

    private function validatedReference(array $envelope, string $key): array
    {
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $values = [];
        foreach ([$envelope, $data] as $source) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];
            if (is_array($value)) {
                if (! array_is_list($value) || count($value) !== 1) {
                    return [false, null];
                }
                $value = $value[0];
            }
            if (! $this->isIdentifier($value)) {
                return [false, null];
            }
            $values[] = trim((string) $value);
        }

        $values = array_values(array_unique($values));
        if (count($values) > 1) {
            return [false, null];
        }

        return [true, $values[0] ?? null];
    }

    private function isIdentifier(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        return $value !== ''
            && $value !== '0'
            && strlen($value) <= 128
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    private function jwtCacheKey(): string
    {
        $identity = implode('|', [
            (string) ($this->account->getKey() ?? 'new'),
            $this->baseUrl,
            $this->credentials['username'],
            $this->credentials['password'],
        ]);

        return 'idcsmart_finance:jwt:'.hash('sha256', $identity);
    }

    private function normalizeBaseUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new FinanceException('The supplier base URL is invalid.');
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            throw new FinanceException('The supplier base URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === ''
            || (! filter_var($host, FILTER_VALIDATE_IP)
                && ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw new FinanceException('The supplier base URL host is invalid.');
        }
        if (! filter_var($host, FILTER_VALIDATE_IP) && $this->looksLikeAlternativeIpv4($host)) {
            throw new FinanceException('The supplier destination is not publicly routable.');
        }

        if ($scheme !== 'https') {
            throw new FinanceException('Supplier connections require HTTPS.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new FinanceException('The supplier destination is not publicly routable.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && ! $this->isGlobalIp($host)) {
            throw new FinanceException('The supplier destination is not publicly routable.');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new FinanceException('The supplier base URL port is invalid.');
        }

        $path = $parts['path'] ?? '';
        $decodedPath = rawurldecode($path);
        if (str_contains($decodedPath, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decodedPath)) {
            throw new FinanceException('The supplier base URL path is invalid.');
        }
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new FinanceException('The supplier base URL path is invalid.');
            }
        }

        $authorityHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$host.']'
            : $host;
        $authority = $authorityHost.($port === null ? '' : ':'.$port);

        return [$scheme.'://'.$authority.rtrim($path, '/'), $host, $port ?? 443];
    }

    private function destinationOptions(): array
    {
        if (filter_var($this->destinationHost, FILTER_VALIDATE_IP)) {
            if (! $this->isGlobalIp($this->destinationHost)) {
                throw new FinanceException('The supplier destination is not publicly routable.');
            }

            return ['verify' => $this->account->verifiesTls(), 'proxy' => ''];
        }

        try {
            $addresses = ($this->resolver)($this->destinationHost);
        } catch (Throwable) {
            throw new FinanceException('The supplier destination could not be resolved safely.');
        }
        if (! is_array($addresses)) {
            throw new FinanceException('The supplier destination could not be resolved safely.');
        }
        if ($addresses === []) {
            throw new FinanceException('The supplier destination could not be resolved safely.');
        }
        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->isGlobalIp($address)) {
                throw new FinanceException('The supplier destination is not publicly routable.');
            }
        }
        $addresses = array_values(array_unique($addresses));
        if (! defined('CURLOPT_RESOLVE')) {
            throw new FinanceException('The PHP HTTP transport cannot pin supplier destinations.');
        }

        $address = $addresses[0];
        $pinnedAddress = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$address.']'
            : $address;

        return [
            'verify' => $this->account->verifiesTls(),
            'proxy' => '',
            'curl' => [
                CURLOPT_RESOLVE => [
                    $this->destinationHost.':'.$this->destinationPort.':'.$pinnedAddress,
                ],
            ],
        ];
    }

    private function resolveHostAddresses(string $host): array
    {
        $addresses = [];
        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } catch (Throwable) {
            $records = false;
        }
        if (is_array($records)) {
            foreach ($records as $record) {
                if (is_string($record['ip'] ?? null)) {
                    $addresses[] = $record['ip'];
                }
                if (is_string($record['ipv6'] ?? null)) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            $ipv4 = gethostbynamel($host);
            if (is_array($ipv4)) {
                $addresses = $ipv4;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isGlobalIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (['100.64.0.0/10', '224.0.0.0/4', '240.0.0.0/4'] as $range) {
                if ($this->ipInRange($ip, $range)) {
                    return false;
                }
            }

            return true;
        }

        foreach ([
            '::ffff:0:0/96',
            '64:ff9b::/96',
            '64:ff9b:1::/48',
            '100::/64',
            '2001::/23',
            '2002::/16',
            '3fff::/20',
            '5f00::/16',
            'ff00::/8',
        ] as $range) {
            if ($this->ipInRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        [$network, $prefix] = explode('/', $range, 2);
        $packedIp = inet_pton($ip);
        $packedNetwork = inet_pton($network);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $prefix = (int) $prefix;
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
    }

    private function looksLikeAlternativeIpv4(string $host): bool
    {
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return false;
        }
        foreach ($parts as $part) {
            if (! preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/i', $part)) {
                return false;
            }
        }

        return true;
    }

    private function identifier(string|int $identifier): string
    {
        $identifier = (string) $identifier;
        if ($identifier === ''
            || strlen($identifier) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $identifier)) {
            throw new FinanceException('The upstream identifier is invalid.');
        }

        return $identifier;
    }

    private function encodedIdentifier(string|int $identifier): string
    {
        return rawurlencode($this->identifier($identifier));
    }

    private function context(string $method, string $path, array $parameters = []): array
    {
        $context = [
            'supplier_account_id' => $this->account->getKey(),
            'method' => $method,
            'endpoint' => $path,
        ];

        if ($parameters !== []) {
            $context['request'] = $this->redactPayload($parameters);
        }

        return $context;
    }

    private function registerSensitivePayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|token|secret|key|authorization/i', $key)) {
                $this->registerSensitiveValue($value);
            } elseif (is_array($value)) {
                $this->registerSensitivePayload($value);
            }
        }
    }

    private function registerSensitiveValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->registerSensitiveValue($item);
            }

            return;
        }
        if ((is_string($value) || is_int($value) || is_float($value)) && (string) $value !== '') {
            $this->sensitiveValues[] = (string) $value;
        }
    }

    private function redactPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|token|secret|key|authorization/i', $key)) {
                $payload[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactPayload($value);
            } elseif ((is_string($value) || is_int($value) || is_float($value))
                && in_array((string) $value, $this->sensitiveValues, true)) {
                $payload[$key] = '[REDACTED]';
            }
        }

        return $payload;
    }

    private function redact(string $message): string
    {
        foreach (array_unique($this->sensitiveValues) as $value) {
            if ($value !== '') {
                $message = str_replace($value, '[REDACTED]', $message);
            }
        }

        $message = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message) ?? $message;
        $message = preg_replace(
            '/\b(password|passwd|token|jwt|authorization|api[_-]?key|secret)\b\s*[:=]\s*[^\s,;&]+/i',
            '$1=[REDACTED]',
            $message,
        ) ?? $message;

        return $message;
    }
}

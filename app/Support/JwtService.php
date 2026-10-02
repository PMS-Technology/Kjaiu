<?php

namespace App\Support;

use App\Models\Client;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * HS256 token service for the public API (/v1).
 *
 * The original platform issues a JWT containing a `userinfo` claim and passes
 * it in an `authorization: JWT <token>` header. Both the header format and the
 * claim layout are reproduced so existing downstream clients keep working.
 */
class JwtService
{
    public const ISSUER = 'www.idcsmart.com';
    public const AUDIENCE = 'www.idcsmart.com';

    public function __construct(private readonly ?string $secret = null)
    {
    }

    protected function secret(): string
    {
        $secret = $this->secret ?: (string) config('kjaiu.api.jwt_secret');

        if ($secret === '') {
            $secret = (string) config('app.key');
        }

        return $secret;
    }

    /**
     * Issue a token for a client account.
     */
    public function issue(Client $client, ?string $ip = null, ?int $ttl = null): string
    {
        $now = time();
        $ttl = $ttl ?? (int) config('kjaiu.api.jwt_ttl', 7200);

        $payload = [
            'userinfo' => [
                'id' => $client->id,
                'username' => $client->username,
            ],
            'iss' => (string) config('kjaiu.api.jwt_issuer', self::ISSUER),
            'aud' => (string) config('kjaiu.api.jwt_audience', self::AUDIENCE),
            'ip' => $ip ?: request()->ip(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ];

        return JWT::encode($payload, $this->secret(), 'HS256');
    }

    /**
     * Decode a token, returning the payload or null when invalid/expired.
     */
    public function decode(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), 'HS256'));

            // A shallow (array) cast leaves nested claims as stdClass, which
            // callers would then have to unwrap by hand; round-trip instead so
            // the payload is consistently nested arrays.
            return json_decode(json_encode($decoded), true) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract the client id from a decoded payload.
     */
    public function clientId(array $payload): ?int
    {
        $userinfo = $payload['userinfo'] ?? null;

        // Tolerate either a nested array or an object so a shallow decode
        // cannot silently break every authenticated request.
        if (is_object($userinfo)) {
            $userinfo = (array) $userinfo;
        }

        $id = is_array($userinfo) ? ($userinfo['id'] ?? null) : null;

        return $id === null ? null : (int) $id;
    }

    /**
     * Read the raw token out of the request, honouring the original
     * `authorization: JWT <token>` header as well as a plain Bearer token.
     */
    public static function tokenFromRequest(\Illuminate\Http\Request $request): ?string
    {
        $header = $request->header('authorization') ?? $request->header('Authorization');

        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $header = trim($header);

        if (preg_match('/^JWT\s+(.+)$/i', $header, $m) === 1) {
            return trim($m[1]);
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1) {
            return trim($m[1]);
        }

        return $header;
    }
}

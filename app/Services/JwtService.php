<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;
use UnexpectedValueException;

class JwtService
{
    public function issue(User $user): string
    {
        $now = time();
        $payload = [
            'userinfo' => [
                'id' => $user->id,
                'username' => $user->username ?: $user->name,
            ],
            'sub' => (string) $user->id,
            'iss' => config('kjaiu.jwt.issuer'),
            'aud' => config('kjaiu.jwt.issuer'),
            'ver' => (int) $user->token_version,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + (int) config('kjaiu.jwt.ttl', 7200),
        ];

        $header = $this->encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $claims = $this->encode($payload);
        $signature = $this->base64Url(hash_hmac('sha256', "$header.$claims", $this->secret(), true));

        return "$header.$claims.$signature";
    }

    public function parse(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Malformed token.');
        }

        [$header, $claims, $signature] = $parts;
        $decodedHeader = json_decode($this->decodeBase64($header), true, flags: JSON_THROW_ON_ERROR);
        if (($decodedHeader['typ'] ?? null) !== 'JWT' || ($decodedHeader['alg'] ?? null) !== 'HS256') {
            throw new UnexpectedValueException('Unsupported token header.');
        }

        $expected = $this->base64Url(hash_hmac('sha256', "$header.$claims", $this->secret(), true));

        if (! hash_equals($expected, $signature)) {
            throw new UnexpectedValueException('Invalid token signature.');
        }

        $payload = json_decode($this->decodeBase64($claims), true, flags: JSON_THROW_ON_ERROR);
        $now = time();
        $issuer = (string) config('kjaiu.jwt.issuer');

        if (($payload['nbf'] ?? 0) > $now || ($payload['exp'] ?? 0) <= $now) {
            throw new UnexpectedValueException('Token has expired or is not active.');
        }
        if (($payload['iss'] ?? null) !== $issuer || ($payload['aud'] ?? null) !== $issuer) {
            throw new UnexpectedValueException('Invalid token issuer or audience.');
        }
        if (! ctype_digit((string) ($payload['sub'] ?? '')) || ! is_int($payload['ver'] ?? null)) {
            throw new UnexpectedValueException('Invalid token subject.');
        }

        return $payload;
    }

    private function secret(): string
    {
        $secret = (string) config('kjaiu.jwt.secret');
        if ($secret === '') {
            throw new RuntimeException('KJAIU_JWT_SECRET is not configured.');
        }

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('KJAIU_JWT_SECRET is not valid base64.');
            }
            $secret = $decoded;
        }

        if (strlen($secret) < 32) {
            throw new RuntimeException('KJAIU_JWT_SECRET must contain at least 32 bytes.');
        }

        return $secret;
    }

    private function encode(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeBase64(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new UnexpectedValueException('Malformed token payload.');
        }

        return $decoded;
    }
}

<?php

namespace App\Support;

use App\Models\Configuration;

/**
 * Legacy-compatible password hashing.
 *
 * The original platform (智简魔方财务 / ZJMF v3.7.6) stores administrator
 * passwords as md5($plain) and client passwords as "###" . md5(md5($authCode . $plain)).
 * Both schemes are reproduced here so existing data and the upstream/downstream
 * API stay interchangeable.
 */
class PasswordHasher
{
    public const CLIENT_PREFIX = '###';

    /**
     * Salt used by both legacy schemes.
     *
     * The original keeps it in `shd_configuration.web_authcode`, not in a file
     * this application controls, so an explicit argument or `.env` value wins
     * and the table is only consulted when neither is set. Without this the
     * stored hashes of an imported installation can never verify, because they
     * were all built with that salt.
     *
     * The table read goes through `Configuration::all_map()`, which caches for
     * the request lifecycle; nothing is memoised here so a flushed cache takes
     * effect immediately.
     */
    public static function authCode(string $explicit = ''): string
    {
        if ($explicit !== '') {
            return $explicit;
        }

        $configured = (string) config('kjaiu.password.authcode', '');

        if ($configured !== '') {
            return $configured;
        }

        try {
            return (string) (Configuration::all_map()['web_authcode'] ?? '');
        } catch (\Throwable) {
            // No database yet (install, key:generate): behave as an empty salt.
            return '';
        }
    }

    /**
     * Hash an administrator password (ThinkCMF `cmf_password`).
     */
    public static function admin(string $plain, string $authCode = ''): string
    {
        return self::client($plain, $authCode);
    }

    /**
     * Hash a client-area password.
     */
    public static function client(string $plain, string $authCode = ''): string
    {
        $authCode = self::authCode($authCode);

        return self::CLIENT_PREFIX . md5(md5($authCode . $plain));
    }

    /**
     * Verify an administrator password against a stored hash.
     */
    public static function checkAdmin(string $plain, ?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        if (str_starts_with($stored, self::CLIENT_PREFIX)) {
            return hash_equals($stored, self::client($plain));
        }

        return hash_equals($stored, md5($plain));
    }

    /**
     * Verify a client-area password against a stored hash.
     */
    public static function checkClient(string $plain, ?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        if (! str_starts_with($stored, self::CLIENT_PREFIX)) {
            // Pre-2.0 hash: substr(md5(prefix), 0, 12) . md5($pw) . substr(md5(prefix), -4, 4)
            $decor = md5((string) config('kjaiu.table_prefix', 'shd_'));
            $legacy = substr($decor, 0, 12) . md5($plain) . substr($decor, -4, 4);

            return hash_equals($stored, $legacy);
        }

        return hash_equals($stored, self::client($plain));
    }

    /**
     * The API password used by the upstream/downstream protocol is stored in
     * plain text in `shd_clients.api_password` on the original platform.
     */
    public static function apiPassword(int $length = 16): string
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }

    /**
     * Decrypt a client-side AES-CBC payload.
     *
     * Client-area forms encrypt passwords before submit with
     * AES-128-CBC, key "idcsmart.finance", IV "9311019310287172", Pkcs7, base64.
     */
    public static function decryptClientPayload(string $payload, string $key = 'idcsmart.finance', string $iv = '9311019310287172'): ?string
    {
        $raw = base64_decode(strtr($payload, ' ', '+'), true);

        if ($raw === false || strlen($raw) < 16 || strlen($raw) % 16 !== 0) {
            return null;
        }

        $plain = openssl_decrypt($raw, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        if ($plain === false) {
            return null;
        }

        // Strip PKCS7 padding manually (ZERO_PADDING above keeps it intact).
        $pad = ord(substr($plain, -1));

        if ($pad > 0 && $pad <= 16) {
            $plain = substr($plain, 0, -$pad);
        }

        return $plain;
    }

    /**
     * Accept either an encrypted or a plain password, as posted by either the
     * browser client or an API integration.
     */
    public static function acceptedPlain(string $submitted): string
    {
        $decrypted = self::decryptClientPayload($submitted);

        return $decrypted !== null && $decrypted !== '' ? $decrypted : $submitted;
    }
}

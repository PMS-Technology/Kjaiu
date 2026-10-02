<?php

namespace App\Support;

use App\Models\Configuration;
use Illuminate\Support\Facades\Cache;

/**
 * Client-area settings reader.
 *
 * Every behaviour toggle the administrator panel exposes lives in
 * `shd_configuration`; the client area must read them rather than hardcoding,
 * otherwise the panel's switches stop having an effect. Values are cached for
 * the request via Configuration::all_map().
 */
class Settings
{
    /** Settings that decide which login / register tabs render. */
    public const LOGIN_FLAGS = [
        'allow_login_email',
        'allow_login_phone',
        'allow_login_register',
        'allow_login_code_captcha',
        'allow_login_email_captcha',
        'allow_login_phone_captcha',
        'allow_register_email',
        'allow_register_phone',
        'allow_register_email_captcha',
        'allow_register_phone_captcha',
        'allow_resetpwd_captcha',
        'allow_email_forgetpwd_captcha',
        'allow_phone_forgetpwd_captcha',
        'allow_phone',
        'allow_email',
        'allow_email_bind_captcha',
        'allow_phone_bind_captcha',
        'is_captcha',
        'shd_allow_sms_send',
        'shd_allow_email_send',
        'shd_allow_sms_send_global',
        'second_verify_home',
        'certifi_open',
        'addfunds_enabled',
        'allow_user_language',
        'main_tenance_mode',
    ];

    /**
     * Read one setting as a trimmed string.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $map = Configuration::all_map();

        if (! array_key_exists($key, $map)) {
            return $default;
        }

        $value = $map[$key];

        return $value === null ? $default : $value;
    }

    /**
     * Read one setting as a boolean flag (any non-empty, non-"0" value is true).
     */
    public static function on(string $key, bool $default = false): bool
    {
        $value = self::get($key, null);

        if ($value === null || $value === '') {
            return $default;
        }

        return ! in_array((string) $value, ['0', 'off', 'false', 'no'], true);
    }

    /**
     * Read one setting as an integer.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    /**
     * Read one setting as a float.
     */
    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (float) $value;
    }

    /**
     * Comma-separated setting as a list.
     *
     * @return array<int, string>
     */
    public static function list(string $key, array $default = []): array
    {
        $value = self::get($key);

        if ($value === null || trim((string) $value) === '') {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }

    /**
     * The `second_verify_action_home` list, i.e. which client-area actions
     * require the second verification code.
     *
     * @return array<int, string>
     */
    public static function secondVerifyActions(): array
    {
        return self::list('second_verify_action_home');
    }

    /**
     * Whether the second verification challenge applies to one action.
     */
    public static function needsSecondVerify(string $action): bool
    {
        if (trim((string) self::get('second_verify_action_home', '')) === '') {
            return false;
        }

        return in_array($action, self::secondVerifyActions(), true);
    }

    /**
     * All settings as a plain map, shaped the way the original injects
     * `{$Setting}` into its templates.
     */
    public static function map(): array
    {
        $map = Configuration::all_map();

        return is_array($map) ? $map : [];
    }

    /**
     * Site-wide presentation settings shared by every view.
     */
    public static function site(): array
    {
        return [
            'web_name' => (string) (self::get('web_name') ?: config('kjaiu.name')),
            'company_name' => (string) (self::get('company_name') ?: config('kjaiu.name')),
            'logo_url' => (string) (self::get('logo_url') ?: ''),
            'logo_url_home' => (string) (self::get('logo_url_home') ?: ''),
            'logo_url_home_mini' => (string) (self::get('logo_url_home_mini') ?: ''),
            'web_tos_url' => (string) (self::get('web_tos_url') ?: self::get('server_clause_url') ?: ''),
            'web_privacy_url' => (string) (self::get('web_privacy_url') ?: self::get('privacy_clause_url') ?: ''),
            'server_clause_url' => (string) (self::get('server_clause_url') ?: ''),
            'certifi_open' => self::on('certifi_open'),
            'allow_user_language' => self::on('allow_user_language', true),
            'main_tenance_mode' => self::on('main_tenance_mode'),
            'main_tenance_mode_message' => (string) (self::get('main_tenance_mode_message') ?: '维护模式已开启，请联系管理员或稍后再试！'),
            'unread_num' => 0,
        ];
    }

    /**
     * Cache the settings map again after an administrator edit.
     */
    public static function forget(): void
    {
        Cache::forget('kjaiu.configuration');
    }
}

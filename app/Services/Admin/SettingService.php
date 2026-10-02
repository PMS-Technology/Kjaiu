<?php

namespace App\Services\Admin;

use App\Models\Configuration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read/write access to the `shd_configuration` key-value store.
 *
 * The original platform keeps every platform setting in this single table and
 * the admin endpoints are thin getter/setter pairs around groups of keys
 * (`config_general/general`, `config_general/safe`, …). Grouping the keys here
 * keeps the controllers declarative and guarantees the getter and setter agree
 * on the exact key set the SPA round-trips.
 */
class SettingService
{
    /** Cache key for the whole settings table. */
    private const CACHE_KEY = 'configuration_all';

    /**
     * Definition of every settings tab the admin API exposes.
     *
     * Shape: [group => [publicKey => configurationKey]]. When both names match
     * the key is listed once as a value.
     *
     * @var array<string, array<string,string>>
     */
    public const GROUPS = [
        'general' => [
            'company_name' => 'company_name',
            'domain' => 'domain',
            'system_url' => 'system_url',
            'system_title' => 'system_title',
            'main_phone' => 'main_phone',
            'company_qq' => 'company_qq',
            'main_address' => 'main_address',
            'record_no' => 'record_no',
            'map' => 'map',
            'www_logo' => 'www_logo',
            'seo_keywords' => 'seo_keywords',
            'seo_desc' => 'seo_desc',
            'company_profile' => 'company_profile',
            'per_page_limit' => 'per_page_limit',
            'credit_limit' => 'credit_limit',
            'allow_custom_clients_id' => 'allow_custom_clients_id',
            'custom_clients_id_start' => 'custom_clients_id_start',
            'client_start_id' => 'client_start_id',
            'main_tenance_mode' => 'main_tenance_mode',
            'main_tenance_mode_message' => 'main_tenance_mode_message',
            'main_tenance_mode_url' => 'main_tenance_mode_url',
            'debug_model' => 'debug_model',
            'home_ip_check' => 'home_ip_check',
            'admin_ip_check' => 'admin_ip_check',
            'cancellation_time' => 'cancellation_time',
            'admin_path' => 'admin_path',
        ],
        'local' => [
            'language' => 'language',
            'allow_user_language' => 'allow_user_language',
            'lang_list' => 'lang_list',
        ],
        'support' => [
            'company_name' => 'company_name',
            'main_phone' => 'main_phone',
            'company_qq' => 'company_qq',
            'company_email' => 'company_email',
            'main_address' => 'main_address',
            'company_profile' => 'company_profile',
            'record_no' => 'record_no',
        ],
        'invoice' => [
            'allow_apply_invoice' => 'allow_apply_invoice',
            'invoice_type' => 'invoice_type',
            'invoice_express' => 'invoice_express',
            'invoice_title_type' => 'invoice_title_type',
            'allow_custom_invoice_id' => 'allow_custom_invoice_id',
            'custom_invoice_id_start' => 'custom_invoice_id_start',
            'invoice_mail_notice' => 'invoice_mail_notice',
            'upgrade_down_product_config' => 'upgrade_down_product_config',
        ],
        'recharge' => [
            'recharge_open' => 'recharge_open',
            'recharge_min' => 'recharge_min',
            'recharge_max' => 'recharge_max',
            'recharge_give' => 'recharge_give',
            'recharge_gateway' => 'recharge_gateway',
            'recharge_pay_after' => 'recharge_pay_after',
        ],
        'affiliate' => [
            'affiliate_open' => 'affiliate_open',
            'affiliate_type' => 'affiliate_type',
            'affiliate_bates' => 'affiliate_bates',
            'affiliate_renew' => 'affiliate_renew',
            'affiliate_renew_bates' => 'affiliate_renew_bates',
            'affiliate_withdraw_min' => 'affiliate_withdraw_min',
            'affiliate_withdraw_fee' => 'affiliate_withdraw_fee',
            'affiliate_withdraw_auto' => 'affiliate_withdraw_auto',
            'affiliate_audit_type' => 'affiliate_audit_type',
            'affiliate_settle_time' => 'affiliate_settle_time',
        ],
        'safe' => [
            'home_ip_check' => 'home_ip_check',
            'admin_ip_check' => 'admin_ip_check',
            'cancellation_time' => 'cancellation_time',
            'admin_login_error_num' => 'admin_login_error_num',
            'home_login_error_num' => 'home_login_error_num',
            'admin_login_error_time' => 'admin_login_error_time',
            'home_login_error_time' => 'home_login_error_time',
        ],
        'other' => [
            'per_page_limit' => 'per_page_limit',
            'allow_resource_api' => 'allow_resource_api',
            'allow_resource_api_realname' => 'allow_resource_api_realname',
            'allow_resource_api_phone' => 'allow_resource_api_phone',
            'sale_is_use' => 'sale_is_use',
            'force_bind_phone' => 'force_bind_phone',
            'certifi_open' => 'certifi_open',
            'certifi_isrealname' => 'certifi_isrealname',
        ],
        'apiconfig' => [
            'allow_resource_api' => 'allow_resource_api',
            'allow_resource_api_realname' => 'allow_resource_api_realname',
            'allow_resource_api_phone' => 'allow_resource_api_phone',
        ],
        'register_login' => [
            'allow_phone' => 'allow_phone',
            'allow_email' => 'allow_email',
            'allow_id' => 'allow_id',
            'allow_wechat' => 'allow_wechat',
            'allow_email_register_code' => 'allow_email_register_code',
            'wechat_login_appid' => 'wechat_login_appid',
            'wechat_login_secret' => 'wechat_login_secret',
            'allow_register_phone' => 'allow_register_phone',
            'allow_register_email' => 'allow_register_email',
            'allow_register_wechat' => 'allow_register_wechat',
            'allow_login_phone' => 'allow_login_phone',
            'allow_login_email' => 'allow_login_email',
            'allow_login_wechat' => 'allow_login_wechat',
            'clients_profoptional_checked' => 'clients_profoptional_checked',
            'login_register_custom_require' => 'login_register_custom_require',
        ],
        'captcha' => [
            'captcha_length' => 'captcha_length',
            'captcha_combination' => 'captcha_combination',
            'allow_register_email_captcha' => 'allow_register_email_captcha',
            'allow_register_phone_captcha' => 'allow_register_phone_captcha',
            'allow_login_phone_captcha' => 'allow_login_phone_captcha',
            'allow_login_email_captcha' => 'allow_login_email_captcha',
            'allow_login_code_captcha' => 'allow_login_code_captcha',
            'allow_login_id_captcha' => 'allow_login_id_captcha',
            'allow_phone_forgetpwd_captcha' => 'allow_phone_forgetpwd_captcha',
            'allow_email_forgetpwd_captcha' => 'allow_email_forgetpwd_captcha',
            'allow_resetpwd_captcha' => 'allow_resetpwd_captcha',
        ],
        'secondverify' => [
            'second_verify' => 'second_verify',
            'second_verify_action' => 'second_verify_action',
            'second_verify_action_type' => 'second_verify_action_type',
            'second_verify_home' => 'second_verify_home',
            'second_verify_action_home' => 'second_verify_action_home',
            'second_verify_action_home_type' => 'second_verify_action_home_type',
            'second_verify_admin' => 'second_verify_admin',
            'second_verify_action_admin' => 'second_verify_action_admin',
        ],
        'buy_product' => [
            'buy_product_must_bind_phone' => 'buy_product_must_bind_phone',
            'certifi_isrealname' => 'certifi_isrealname',
            'order_page_style' => 'order_page_style',
        ],
        'credit_limit' => [
            'credit_limit' => 'credit_limit',
            'credit_limit_amount' => 'credit_limit_amount',
            'credit_limit_bill_generation_date' => 'credit_limit_bill_generation_date',
            'credit_limit_bill_repayment_period' => 'credit_limit_bill_repayment_period',
            'credit_limit_liquidated_damages' => 'credit_limit_liquidated_damages',
            'credit_limit_liquidated_damages_percent' => 'credit_limit_liquidated_damages_percent',
        ],
    ];

    /**
     * Cron/automation keys written by `/automatic-tasks`.
     *
     * @var array<int,string>
     */
    public const CRON_KEYS = [
        'cron_day_start_time',
        'cron_host_suspend', 'cron_host_suspend_time', 'cron_host_suspend_send',
        'cron_host_unsuspend', 'cron_host_unsuspend_send',
        'cron_host_terminate', 'cron_host_terminate_time', 'cron_host_terminate_high',
        'cron_host_terminate_time_hostingaccount', 'cron_host_terminate_time_server',
        'cron_host_terminate_time_cloud', 'cron_host_terminate_time_dcimcloud',
        'cron_host_terminate_time_dcim', 'cron_host_terminate_time_software',
        'cron_host_terminate_time_cdn', 'cron_host_terminate_time_other',
        'cron_invoice_create_default_days', 'cron_invoice_create_hour',
        'cron_invoice_create_day', 'cron_invoice_create_monthly',
        'cron_invoice_create_quarterly', 'cron_invoice_create_semiannually',
        'cron_invoice_create_annually', 'cron_invoice_create_biennially',
        'cron_invoice_create_triennially', 'cron_invoice_create_fourly',
        'cron_invoice_create_fively', 'cron_invoice_create_sixly',
        'cron_invoice_create_sevenly', 'cron_invoice_create_eightly',
        'cron_invoice_create_ninely', 'cron_invoice_create_tenly',
        'cron_invoice_pay_email', 'cron_invoice_unpaid_email',
        'cron_invoice_first_overdue_email', 'cron_invoice_first_overdue_email_switch',
        'cron_invoice_second_overdue_email', 'cron_invoice_second_overdue_email_switch',
        'cron_invoice_third_overdue_email', 'cron_invoice_third_overdue_email_switch',
        'cron_invoice_recharge_delete', 'cron_invoice_recharge_delete1',
        'cron_invoice_recharge_delete_time',
        'cron_ticket_close_time', 'cron_ticket_close_time_switch',
        'cron_client_delete', 'cron_client_delete_time',
        'cron_other_cancel_request', 'cron_other_client_update',
        'cron_last_run_time',
        'cron_order_unpaid_time_high', 'cron_order_unpaid_time', 'cron_order_unpaid_action',
        'cron_credit_limit_suspend_time_switch', 'cron_credit_limit_suspend_time',
        'cron_credit_limit_invoice_unpaid_email_switch', 'cron_credit_limit_invoice_unpaid_email',
        'cron_credit_limit_invoice_first_overdue_email_switch', 'cron_credit_limit_invoice_first_overdue_email',
        'cron_credit_limit_invoice_second_overdue_email_switch', 'cron_credit_limit_invoice_second_overdue_email',
        'cron_credit_limit_invoice_third_overdue_email_switch', 'cron_credit_limit_invoice_third_overdue_email',
        'certifi_is_stop', 'certifi_stop_day', 'certifi_open',
    ];

    /**
     * Every key currently stored, keyed by setting name.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return DB::table('configuration')
                ->pluck('value', 'setting')
                ->map(fn ($v) => (string) $v)
                ->all();
        });
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::value($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Persist a batch of key => value pairs, inserting missing keys so a fresh
     * install picks up settings the original shipped as rows.
     *
     * @param  array<string,mixed>  $values
     */
    public static function putMany(array $values): void
    {
        $now = time();

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $value = $value === null ? '' : (string) $value;

            $exists = DB::table('configuration')->where('setting', $key)->exists();

            if ($exists) {
                DB::table('configuration')->where('setting', $key)->update([
                    'value' => $value,
                    'update_time' => $now,
                ]);
            } else {
                DB::table('configuration')->insert([
                    'setting' => $key,
                    'value' => $value,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }

        self::flush();
    }

    /**
     * Pick only the keys the caller is allowed to write, so a posted form can
     * be passed straight through without trusting its shape.
     *
     * @param  array<string,mixed>  $input
     * @param  array<int,string>  $keys
     * @return array<string,mixed>
     */
    public static function pick(array $input, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * Read one of the GROUPS definitions into its public shape.
     *
     * @return array<string,mixed>
     */
    public static function group(string $name): array
    {
        $map = self::GROUPS[$name] ?? [];
        $all = self::all();
        $out = [];

        foreach ($map as $public => $key) {
            $out[$public] = $all[$key] ?? null;
        }

        return $out;
    }

    /**
     * Write a group from posted input, keeping configuration keys and public
     * field names in sync.
     *
     * @param  array<string,mixed>  $input
     */
    public static function saveGroup(string $name, array $input): void
    {
        $map = self::GROUPS[$name] ?? [];
        $values = [];

        foreach ($map as $public => $key) {
            if (array_key_exists($public, $input)) {
                $values[$key] = $input[$public];
            }
        }

        self::putMany($values);
    }

    /**
     * Read a group as raw array/JSON values (for list-shaped settings).
     *
     * @return array<string,mixed>
     */
    public static function groupDecoded(string $name, array $jsonKeys = []): array
    {
        $out = self::group($name);

        foreach ($jsonKeys as $key) {
            if (isset($out[$key]) && is_string($out[$key])) {
                $decoded = json_decode($out[$key], true);

                if (is_array($decoded)) {
                    $out[$key] = $decoded;
                }
            }
        }

        return $out;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}

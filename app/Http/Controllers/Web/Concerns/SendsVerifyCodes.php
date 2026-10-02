<?php

namespace App\Http\Controllers\Web\Concerns;

use App\Models\User;
use App\Services\VerifyCodeService;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Verification-code plumbing shared by the auth pages, the security centre and
 * the cart's inline registration.
 *
 * The original issues codes from a family of small endpoints
 * (`register_email_send`, `login_send`, `reset_phone_send`, `bind_phone`,
 * `second_verify_send`, ...) which all take the same shape: a `mk` signature,
 * an account, an optional graphic captcha and an optional dialling code.
 */
trait SendsVerifyCodes
{
    /**
     * The per-session signing token the original exposes as `$Setting.msfntk`.
     *
     * It is not a security boundary on its own (it lives in a public setting),
     * but the original templates post it and integrations replay it, so it is
     * issued and checked here for wire compatibility.
     */
    protected function issueMk(): string
    {
        $mk = (string) Session::get('msfntk');

        if ($mk === '') {
            $mk = Str::random(32);
            Session::put('msfntk', $mk);
        }

        return $mk;
    }

    /**
     * Validate the posted `mk` signature against the session one.
     */
    protected function checkMk(Request $request): bool
    {
        $submitted = trim((string) $request->input('mk', ''));
        $expected = (string) Session::get('msfntk');

        if ($expected === '' || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /**
     * Whether a graphic captcha is required for the given setting flag.
     *
     * Each tab has its own administrator toggle (`allow_login_email_captcha`,
     * `allow_register_phone_captcha`, ...); the toggle is the switch, so the
     * panel controls the client area directly.
     */
    protected function captchaRequired(string $flag): bool
    {
        return Settings::on($flag);
    }

    /**
     * Validate the posted graphic captcha against the session copy.
     *
     * Returns true when no captcha was required or the code matches.
     */
    protected function checkCaptcha(Request $request, string $flag): bool
    {
        if (! $this->captchaRequired($flag)) {
            return true;
        }

        $submitted = strtoupper(trim((string) $request->input('captcha', '')));
        $expected = strtoupper((string) Session::get('captcha.' . $flag, ''));

        if ($submitted === '' || $expected === '') {
            return false;
        }

        // A captcha is single-use: clear it whether or not it matched.
        Session::forget('captcha.' . $flag);

        return hash_equals($expected, $submitted);
    }

    /**
     * Issue a code for a scene + account and hand it to the transport.
     *
     * @return array{0:bool, 1:string} sent flag and message
     */
    protected function dispatchCode(string $scene, string $account): array
    {
        $account = trim($account);

        if ($account === '') {
            return [false, '接收账号不能为空'];
        }

        $codes = new VerifyCodeService();
        $code = (string) random_int(100000, 999999);

        $codes->issue($scene, $account, $code);

        $channel = filter_var($account, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        if (! $codes->send($channel, $account, $code, $scene)) {
            return [false, $channel === 'email' ? '验证码发送失败，请检查邮件配置' : '验证码发送失败，请检查短信配置'];
        }

        return [true, '验证码已发送'];
    }

    /**
     * Check and consume a code for a scene + account pair.
     */
    protected function consumeCode(string $scene, string $account, string $code): bool
    {
        if (trim($code) === '') {
            return false;
        }

        return (new VerifyCodeService())->consume($scene, trim($account), trim($code));
    }

    /**
     * International dialling codes for the phone `<select>`.
     *
     * @return array<int, array{phone_code:string, link:string, iso:string, name:string}>
     */
    protected function phoneCodes(): array
    {
        return DB::table('sms_country')
            ->orderBy('num_code')
            ->get(['iso', 'name', 'name_zh', 'phone_code'])
            ->map(fn ($row) => [
                'phone_code' => (string) $row->phone_code,
                'link' => '+' . $row->phone_code,
                'iso' => (string) $row->iso,
                'name' => (string) ($row->name_zh ?: $row->name),
            ])
            ->all();
    }

    /**
     * Sales representatives offered by the register form.
     *
     * The original only renders the select when `setsaler` is 2.
     *
     * @return array<int, array{id:int, user_nickname:string}>
     */
    protected function salesReps(): array
    {
        if ((string) Settings::get('setsaler') !== '2') {
            return [];
        }

        return User::query()
            ->where('is_sale', 1)
            ->get(['id', 'user_nickname', 'user_login'])
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'user_nickname' => $user->displayName(),
            ])
            ->all();
    }

    /**
     * Literal custom field names the register form must collect, as configured
     * by `login_register_custom_require`.
     *
     * @return array<int, array{name:string, label:string}>
     */
    protected function literalRegisterFields(): array
    {
        $labels = Settings::get('login_register_custom_require_list');
        $decoded = is_string($labels) ? json_decode($labels, true) : null;

        if (! is_array($decoded)) {
            $decoded = [];
        }

        return array_values(array_map(
            fn ($name) => [
                'name' => (string) $name,
                'label' => (string) ($decoded[$name] ?? $name),
            ],
            Settings::list('login_register_custom_require'),
        ));
    }
}

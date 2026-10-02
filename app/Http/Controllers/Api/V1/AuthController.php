<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Models\Configuration;
use App\Support\ApiResponse;
use App\Support\JwtService;
use App\Support\PasswordHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Login / register / password reset for the public API.
 *
 * Mirrors the original platform's four login modes (phone+password,
 * phone+code, email+password, email+code) plus the API-key login used by
 * downstream installations.
 */
class AuthController extends ApiController
{
    /**
     * GET /v1/login — which login modes this installation offers.
     */
    public function loginPage()
    {
        return $this->ok([
            'allow_login_phone' => (int) Configuration::value('allow_login_phone', 1),
            'allow_login_email' => (int) Configuration::value('allow_login_email', 1),
            'allow_login_code' => (int) Configuration::value('allow_login_code', 0),
            'allow_api_login' => 1,
            'is_captcha' => (int) Configuration::value('allow_login_email_captcha', 0)
                || (int) Configuration::value('allow_login_phone_captcha', 0),
            'phone_code' => $this->phoneCodes(),
        ]);
    }

    /**
     * POST /v1/login
     *
     * Accepts the documented `_phone` / `_phone_code` / `_email` mode markers.
     */
    public function login(Request $request)
    {
        $mode = $this->detectLoginMode($request);

        $client = match ($mode) {
            'phone' => $this->findByPhone($request->input('phone_code', 86), (string) $request->input('phone')),
            'email' => Client::query()->where('email', (string) $request->input('email'))->first(),
            'phone_code' => $this->verifyPhoneCodeLogin($request),
            'email_code' => $this->verifyEmailCodeLogin($request),
            default => null,
        };

        if ($client === null) {
            return $this->fail('账号或密码错误');
        }

        if (! $client->isActive()) {
            return $this->fail('账号已被停用，请联系管理员');
        }

        // Password modes verify the credential; code modes already did.
        if (in_array($mode, ['phone', 'email'], true)) {
            $password = PasswordHasher::acceptedPlain((string) $request->input('password', ''));

            if (! PasswordHasher::checkClient($password, (string) $client->password)) {
                return $this->fail('账号或密码错误');
            }
        }

        $client->lastlogin = time();
        $client->lastloginip = $request->ip();
        $client->save();

        return $this->ok([
            'jwt' => (new JwtService())->issue($client, $request->ip()),
            'user' => $this->clientPayload($client),
        ], '登录成功');
    }

    /**
     * POST /v1/login_api — credential login for downstream integrations.
     *
     * `account` is the client's phone or email, `password` their API key.
     */
    public function loginApi(Request $request)
    {
        $account = trim((string) $request->input('account', ''));
        $key = trim((string) $request->input('password', ''));

        if ($account === '' || $key === '') {
            return $this->fail('账号和API密钥不能为空');
        }

        $client = $this->findByPhone(86, $account)
            ?? Client::query()->where('email', $account)->first();

        if ($client === null) {
            return $this->fail('账号不存在');
        }

        if (! $client->isActive()) {
            return $this->fail('账号已被停用');
        }

        if ((int) $client->api_open !== 1) {
            return $this->fail('该账号未开启API');
        }

        if (trim((string) $client->api_password) === '' || ! hash_equals((string) $client->api_password, $key)) {
            return $this->fail('API密钥错误');
        }

        return $this->ok([
            'jwt' => (new JwtService())->issue($client, $request->ip()),
        ], '登录成功');
    }

    /**
     * GET /v1/register — which registration modes are enabled.
     */
    public function registerPage()
    {
        return $this->ok([
            'allow_register_phone' => (int) Configuration::value('allow_register_phone', 1),
            'allow_register_email' => (int) Configuration::value('allow_register_email', 1),
            'is_captcha' => (int) Configuration::value('allow_register_email_captcha', 0),
            'phone_code' => $this->phoneCodes(),
            'fields' => [],
        ]);
    }

    /**
     * POST /v1/register
     */
    public function register(Request $request)
    {
        $mode = $this->detectLoginMode($request, 'register');

        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));
        $phoneCode = (int) $request->input('phone_code', 86);
        $password = PasswordHasher::acceptedPlain((string) $request->input('password', ''));

        if ($password === '' || strlen($password) < 6) {
            return $this->fail('密码长度不能少于6位', ApiResponse::VALIDATION_FAILED);
        }

        if ($mode === 'email' && ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return $this->fail('邮箱格式不正确', ApiResponse::VALIDATION_FAILED);
        }

        if ($mode === 'phone' && $phone === '') {
            return $this->fail('手机号不能为空', ApiResponse::VALIDATION_FAILED);
        }

        if ($email !== '' && Client::query()->where('email', $email)->exists()) {
            return $this->fail('该邮箱已被注册');
        }

        if ($phone !== '' && Client::query()->where('phonenumber', $phone)->where('phone_code', $phoneCode)->exists()) {
            return $this->fail('该手机号已被注册');
        }

        $client = DB::transaction(function () use ($email, $phone, $phoneCode, $password, $request) {
            $client = Client::create([
                'username' => $email !== '' ? Str::before($email, '@') : $phone,
                'email' => $email,
                'phone_code' => $phoneCode,
                'phonenumber' => $phone,
                'password' => PasswordHasher::client($password),
                'status' => Client::STATUS_ACTIVE,
                'currency' => $this->currency()?->id ?? 0,
                'create_time' => time(),
                'update_time' => time(),
                'api_password' => PasswordHasher::apiPassword(),
                'api_open' => 1,
            ]);

            // Custom client fields posted as fields[<id>]
            foreach ((array) $request->input('fields', []) as $fieldId => $value) {
                \App\Models\CustomFieldValue::create([
                    'fieldid' => (int) $fieldId,
                    'relid' => $client->id,
                    'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }

            return $client;
        });

        return $this->ok([
            'jwt' => (new JwtService())->issue($client, $request->ip()),
            'user' => $this->clientPayload($client),
        ], '注册成功');
    }

    /**
     * GET /v1/pwreset — which reset modes are enabled.
     */
    public function pwresetPage()
    {
        return $this->ok([
            'allow_login_phone' => (int) Configuration::value('allow_login_phone', 1),
            'allow_login_email' => (int) Configuration::value('allow_login_email', 1),
            'phone_code' => $this->phoneCodes(),
        ]);
    }

    /**
     * POST /v1/pwreset
     */
    public function pwreset(Request $request)
    {
        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));
        $password = PasswordHasher::acceptedPlain((string) $request->input('password', ''));

        if ($password === '' || strlen($password) < 6) {
            return $this->fail('密码长度不能少于6位', ApiResponse::VALIDATION_FAILED);
        }

        $client = $email !== ''
            ? Client::query()->where('email', $email)->first()
            : $this->findByPhone((int) $request->input('phone_code', 86), $phone);

        if ($client === null) {
            return $this->fail('账号不存在');
        }

        // The original stores the reset token and an expiry on the client row.
        $client->password = PasswordHasher::client($password);
        $client->pwresetkey = '';
        $client->pwresetexpiry = 0;
        $client->save();

        return $this->ok(null, '密码重置成功');
    }

    /**
     * Detect which documented login/registration mode was used.
     */
    protected function detectLoginMode(Request $request, string $context = 'login'): string
    {
        if ($request->has('_phone_code') || $request->filled('code')) {
            return $request->filled('phone') ? 'phone_code' : 'email_code';
        }

        if ($request->has('_phone') || $request->filled('phone')) {
            return 'phone';
        }

        if ($request->filled('email')) {
            return 'email';
        }

        return $context === 'login' ? 'email' : 'phone';
    }

    protected function findByPhone(int|string $phoneCode, string $phone): ?Client
    {
        if ($phone === '') {
            return null;
        }

        return Client::query()
            ->where('phonenumber', $phone)
            ->when((int) $phoneCode > 0, fn ($q) => $q->where('phone_code', (int) $phoneCode))
            ->first();
    }

    protected function verifyPhoneCodeLogin(Request $request): ?Client
    {
        // Verification codes are issued by the PublicController and stored with
        // the same key shape the original uses.
        $client = $this->findByPhone((int) $request->input('phone_code', 86), (string) $request->input('phone'));

        if ($client === null) {
            return null;
        }

        return $this->consumeVerifyCode('login', (string) $request->input('phone'), (string) $request->input('code'))
            ? $client
            : null;
    }

    protected function verifyEmailCodeLogin(Request $request): ?Client
    {
        $client = Client::query()->where('email', (string) $request->input('email'))->first();

        if ($client === null) {
            return null;
        }

        return $this->consumeVerifyCode('login', (string) $request->input('email'), (string) $request->input('code'))
            ? $client
            : null;
    }

    /**
     * Check and clear a one-time verification code.
     */
    protected function consumeVerifyCode(string $scene, string $account, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $row = DB::table('option')
            ->where('name', 'verify_code')
            ->where('value', $scene . ':' . $account . ':' . $code)
            ->first();

        if ($row === null) {
            return false;
        }

        DB::table('option')->where('id', $row->id)->delete();

        return true;
    }

    /**
     * International dialling codes available for phone fields.
     */
    protected function phoneCodes(): array
    {
        return DB::table('sms_country')
            ->get(['phone_code', 'name_zh'])
            ->map(fn ($row) => ['phone_code' => '+' . (int) $row->phone_code, 'link' => (string) $row->name_zh])
            ->all();
    }

    /**
     * Client payload as returned by the API after login.
     */
    protected function clientPayload(Client $client): array
    {
        return [
            'id' => $client->id,
            'username' => $client->username,
            'email' => $client->email,
            'phone_code' => $client->phone_code,
            'phonenumber' => $client->phonenumber,
            'credit' => $this->money((float) $client->credit),
            'currency' => $client->currency,
            'status' => $client->status,
        ];
    }
}

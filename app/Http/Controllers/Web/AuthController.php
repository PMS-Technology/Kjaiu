<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Web\Concerns\SendsVerifyCodes;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Services\VerifyCodeService;
use App\Support\Captcha;
use App\Support\PasswordHasher;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Login, registration, password reset and third-party bind.
 *
 * The four login modes of the original are preserved (email+password,
 * phone+password, phone+code, email+code) along with the `?action=` selector on
 * each URL, the client-side AES password encryption and the 二次验证 challenge.
 */
class AuthController extends WebController
{
    use SendsVerifyCodes;

    /** Scene keys used for the cached verification codes. */
    protected const SCENE_REGISTER = 'register';

    protected const SCENE_FORGET = 'forget';

    protected const SCENE_LOGIN = 'login';

    protected const SCENE_BIND = 'bind';

    protected const SCENE_SECOND = 'second_verify';

    // -----------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------

    public function login(Request $request): View|RedirectResponse
    {
        if ($this->client() !== null) {
            return redirect()->to($this->safeRedirect($request) ?? '/clientarea');
        }

        return view('web.auth.login', array_merge($this->shared(), [
            'Title' => '登录',
            'TplName' => 'login',
            'Login' => $this->loginPayload(),
            'Verify' => $this->verifyPayload(),
            'SmsCountry' => $this->phoneCodes(),
            'Oauth' => [],
            'redirect_url' => $this->safeRedirect($request) ?? '',
        ]));
    }

    /**
     * POST /login?action=email|phone|phone_code
     */
    public function attemptLogin(Request $request): RedirectResponse|JsonResponse
    {
        $action = (string) $request->input('action', $request->query('action', 'email'));

        return match ($action) {
            'phone' => $this->loginWithPhonePassword($request),
            'phone_code' => $this->loginWithPhoneCode($request),
            default => $this->loginWithEmail($request),
        };
    }

    protected function loginWithEmail(Request $request): RedirectResponse|JsonResponse
    {
        if (! Settings::on('allow_login_email', true)) {
            return $this->loginFailure($request, '邮箱登录未开启');
        }

        if (! $this->checkCaptcha($request, 'allow_login_email_captcha')) {
            return $this->loginFailure($request, '图形验证码错误');
        }

        $email = trim((string) $request->input('email', ''));
        $client = $email === '' ? null : Client::query()->where('email', $email)->first();

        if ($client === null) {
            return $this->loginFailure($request, '账号或密码错误');
        }

        return $this->completePasswordLogin($request, $client, (string) $request->input('password', ''));
    }

    protected function loginWithPhonePassword(Request $request): RedirectResponse|JsonResponse
    {
        if (! Settings::on('allow_login_phone', true)) {
            return $this->loginFailure($request, '手机号登录未开启');
        }

        if (! $this->checkCaptcha($request, 'allow_login_phone_captcha')) {
            return $this->loginFailure($request, '图形验证码错误');
        }

        $client = $this->findByPhone(
            $request->input('phone_code', '86'),
            trim((string) $request->input('phone', ''))
        );

        if ($client === null) {
            return $this->loginFailure($request, '账号或密码错误');
        }

        return $this->completePasswordLogin($request, $client, (string) $request->input('password', ''));
    }

    /**
     * SMS-code login: no password, the code stands in for the credential.
     */
    protected function loginWithPhoneCode(Request $request): RedirectResponse|JsonResponse
    {
        if (! Settings::on('allow_login_phone', true)) {
            return $this->loginFailure($request, '手机号登录未开启');
        }

        if (! $this->checkCaptcha($request, 'allow_login_code_captcha')) {
            return $this->loginFailure($request, '图形验证码错误');
        }

        $phone = trim((string) $request->input('phone', ''));
        $client = $this->findByPhone($request->input('phone_code', '86'), $phone);

        if ($client === null) {
            return $this->loginFailure($request, '账号或密码错误');
        }

        if (! $this->consumeCode(self::SCENE_LOGIN, $phone, (string) $request->input('code', ''))) {
            return $this->loginFailure($request, '验证码错误或已失效');
        }

        return $this->establishSession($request, $client);
    }

    /**
     * Shared password-mode verification (email and phone differ only in lookup).
     */
    protected function completePasswordLogin(Request $request, Client $client, string $submitted): RedirectResponse|JsonResponse
    {
        // The browser encrypts the password with the documented AES key before
        // submit; acceptedPlain() also passes through plain-text API callers.
        $plain = PasswordHasher::acceptedPlain($submitted);

        if ($plain === '') {
            return $this->loginFailure($request, '请输入密码');
        }

        if (! PasswordHasher::checkClient($plain, (string) $client->password)) {
            return $this->loginFailure($request, '账号或密码错误');
        }

        return $this->establishSession($request, $client);
    }

    /**
     * Log the client in, honouring the second-verification gate.
     */
    protected function establishSession(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        if (! $client->isActive()) {
            return $this->loginFailure($request, '账号已被停用，请联系管理员');
        }

        // 二次验证: the code is appended to the form as `code` + `code_type`
        // only when the administrator enabled it for the `login` action.
        if (Settings::on('second_verify') && Settings::needsSecondVerify('login')) {
            $code = trim((string) $request->input('code', ''));

            if ($code === '') {
                return $this->loginFailure($request, '请输入二次验证码', 1002);
            }

            if (! $this->consumeCode(self::SCENE_SECOND . ':login', $this->secondVerifyAccount($client), $code)) {
                return $this->loginFailure($request, '二次验证码错误');
            }
        }

        Auth::guard('client')->login($client);
        $request->session()->regenerate();

        $client->lastlogin = time();
        $client->lastloginip = (string) $request->ip();
        $client->save();

        $this->recordLoginLog($client, (string) $request->ip());

        $redirect = $this->safeRedirect($request) ?? '/clientarea';

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok(['redirect' => $redirect], '登录成功');
        }

        return redirect()->to($redirect);
    }

    /**
     * Which second-verification channels the account may use.
     *
     * @return array<int, array{name:string, name_zh:string, account:string}>
     */
    protected function secondVerifyTypes(Client $client): array
    {
        $enabled = Settings::list('second_verify_action_type', ['email', 'phone']);
        $types = [];

        if (in_array('email', $enabled, true) && trim((string) $client->email) !== '') {
            $types[] = ['name' => 'email', 'name_zh' => '邮箱验证', 'account' => (string) $client->email];
        }

        if (in_array('phone', $enabled, true) && trim((string) $client->phonenumber) !== '') {
            $types[] = ['name' => 'phone', 'name_zh' => '手机验证', 'account' => (string) $client->phonenumber];
        }

        return $types;
    }

    protected function secondVerifyAccount(Client $client, string $type = ''): string
    {
        $types = $this->secondVerifyTypes($client);

        foreach ($types as $candidate) {
            if ($type === '' || $candidate['name'] === $type) {
                return $candidate['account'];
            }
        }

        return (string) ($client->email ?: $client->phonenumber);
    }

    /**
     * GET /login/second_verify_page — the modal body for the login challenge.
     */
    public function secondVerifyPage(Request $request): JsonResponse
    {
        $client = $this->locateForSecondVerify($request);

        if ($client === null) {
            return $this->fail('账号或密码错误');
        }

        return $this->ok(['allow_type' => $this->secondVerifyTypes($client)]);
    }

    /**
     * POST /login/second_verify_send — issue a code for the login challenge.
     */
    public function secondVerifySend(Request $request): JsonResponse
    {
        $client = $this->locateForSecondVerify($request);

        if ($client === null) {
            return $this->fail('账号或密码错误');
        }

        $type = (string) $request->input('type', '');
        $account = $this->secondVerifyAccount($client, $type);

        if ($account === '') {
            return $this->fail('该账号未绑定可用的验证方式');
        }

        [$sent, $msg] = $this->dispatchCode(self::SCENE_SECOND . ':login', $account);

        return $sent ? $this->ok(['expire' => VerifyCodeService::TTL], $msg) : $this->fail($msg);
    }

    /**
     * Resolve the account a login second-verification request refers to.
     */
    protected function locateForSecondVerify(Request $request): ?Client
    {
        $email = trim((string) $request->input('email', $request->input('username', '')));
        $phone = trim((string) $request->input('phone', ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $client = Client::query()->where('email', $email)->first();

            if ($client !== null) {
                return $client;
            }
        }

        if ($phone !== '') {
            $client = $this->findByPhone($request->input('phone_code', '86'), $phone);

            if ($client !== null) {
                return $client;
            }
        }

        // The original also passes the password along so an unknown account
        // cannot be probed; the value is ignored here beyond the lookup.
        return null;
    }

    /**
     * POST login_send — SMS code for the code-login tab.
     */
    public function loginSend(Request $request): JsonResponse
    {
        if (! Settings::on('allow_login_phone', true)) {
            return $this->fail('手机号登录未开启');
        }

        if (! $this->checkMk($request)) {
            return $this->fail('请求已失效，请刷新页面重试');
        }

        if (! $this->checkCaptcha($request, 'allow_login_code_captcha')) {
            return $this->fail('图形验证码错误');
        }

        [$sent, $msg] = $this->dispatchCode(self::SCENE_LOGIN, (string) $request->input('phone', ''));

        return $sent ? $this->ok(null, $msg) : $this->fail($msg);
    }

    // -----------------------------------------------------------------
    // Register
    // -----------------------------------------------------------------

    public function register(Request $request): View|RedirectResponse
    {
        if (! Settings::on('allow_login_register', true)) {
            return redirect()->to('/login');
        }

        if ($this->client() !== null) {
            return redirect()->to('/clientarea');
        }

        return view('web.auth.register', array_merge($this->shared(), [
            'Title' => '注册',
            'TplName' => 'register',
            'Register' => $this->registerPayload(),
            'Verify' => $this->verifyPayload(),
            'SmsCountry' => $this->phoneCodes(),
            'saleList' => $this->salesReps(),
        ]));
    }

    /**
     * POST /register?action=email|phone
     */
    public function attemptRegister(Request $request): RedirectResponse|JsonResponse
    {
        $action = (string) $request->input('action', $request->query('action', 'email'));

        return $action === 'phone'
            ? $this->registerWithPhone($request)
            : $this->registerWithEmail($request);
    }

    protected function registerWithEmail(Request $request): RedirectResponse|JsonResponse
    {
        if (! Settings::on('allow_register_email', true)) {
            return $this->registerFailure($request, '邮箱注册未开启');
        }

        if (! $this->checkCaptcha($request, 'allow_register_email_captcha')) {
            return $this->registerFailure($request, '图形验证码错误');
        }

        $email = trim((string) $request->input('email', ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->registerFailure($request, '邮箱格式不正确');
        }

        if (Client::query()->where('email', $email)->exists()) {
            return $this->registerFailure($request, '该邮箱已被注册');
        }

        // Email-code registration is gated separately in the original.
        if (Settings::on('allow_email_register_code')) {
            if (! $this->consumeCode(self::SCENE_REGISTER, $email, (string) $request->input('code', ''))) {
                return $this->registerFailure($request, '邮箱验证码错误或已失效');
            }
        }

        return $this->createClient($request, [
            'email' => $email,
            'phone_code' => '',
            'phonenumber' => '',
        ]);
    }

    protected function registerWithPhone(Request $request): RedirectResponse|JsonResponse
    {
        if (! Settings::on('allow_register_phone', true)) {
            return $this->registerFailure($request, '手机号注册未开启');
        }

        if (! $this->checkCaptcha($request, 'allow_register_phone_captcha')) {
            return $this->registerFailure($request, '图形验证码错误');
        }

        $phone = trim((string) $request->input('phone', ''));
        $phoneCode = (string) $request->input('phone_code', '86');

        if ($phone === '') {
            return $this->registerFailure($request, '手机号不能为空');
        }

        if ($this->findByPhone($phoneCode, $phone) !== null) {
            return $this->registerFailure($request, '该手机号已被注册');
        }

        if (! $this->consumeCode(self::SCENE_REGISTER, $phone, (string) $request->input('code', ''))) {
            return $this->registerFailure($request, '短信验证码错误或已失效');
        }

        return $this->createClient($request, [
            'email' => trim((string) $request->input('email', '')),
            'phone_code' => $phoneCode,
            'phonenumber' => $phone,
        ]);
    }

    /**
     * Persist the new client with its custom field values and sales rep.
     */
    protected function createClient(Request $request, array $identity): RedirectResponse|JsonResponse
    {
        $password = PasswordHasher::acceptedPlain((string) $request->input('password', ''));
        $confirm = PasswordHasher::acceptedPlain((string) $request->input('checkPassword', $request->input('repassword', '')));

        if ($password === '' || strlen($password) < 6) {
            return $this->registerFailure($request, '密码长度不能少于6位');
        }

        if ($confirm !== '' && $confirm !== $password) {
            return $this->registerFailure($request, '两次输入的密码不一致');
        }

        $required = $this->validateCustomFields($request, 'fields');

        if ($required !== null) {
            return $this->registerFailure($request, $required);
        }

        $client = DB::transaction(function () use ($request, $identity, $password) {
            $username = $identity['email'] !== ''
                ? Str::before($identity['email'], '@')
                : (string) $identity['phonenumber'];

            $client = Client::create([
                'username' => $username,
                'email' => (string) $identity['email'],
                'phone_code' => (string) $identity['phone_code'],
                'phonenumber' => (string) $identity['phonenumber'],
                'password' => PasswordHasher::client($password),
                'status' => Client::STATUS_ACTIVE,
                'currency' => $this->currencyPayload()['id'] ?? 1,
                'create_time' => time(),
                'update_time' => time(),
                'api_password' => PasswordHasher::apiPassword(),
                'api_open' => 0,
                'sale_id' => (int) $request->input('sale_id', 0),
                'lastlogin' => time(),
                'lastloginip' => (string) $request->ip(),
            ]);

            $this->storeCustomFields($client, $request, 'fields');

            return $client;
        });

        Auth::guard('client')->login($client);
        $request->session()->regenerate();

        // A pending cart survives registration so guest checkout completes.
        $redirect = $this->safeRedirect($request) ?? (Session::has('cart.pending') ? '/cart?action=viewcart' : '/clientarea');

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok(['redirect' => $redirect], '注册成功');
        }

        return redirect()->to($redirect)->with('success', '注册成功');
    }

    /**
     * POST register_email_send / register_phone_send
     */
    public function registerSend(Request $request): JsonResponse
    {
        if (! $this->checkMk($request)) {
            return $this->fail('请求已失效，请刷新页面重试');
        }

        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));

        if ($email !== '') {
            if (! $this->checkCaptcha($request, 'allow_register_email_captcha')) {
                return $this->fail('图形验证码错误');
            }

            if (Client::query()->where('email', $email)->exists()) {
                return $this->fail('该邮箱已被注册');
            }

            [$sent, $msg] = $this->dispatchCode(self::SCENE_REGISTER, $email);

            return $sent ? $this->ok(null, $msg) : $this->fail($msg);
        }

        if ($phone !== '') {
            if (! $this->checkCaptcha($request, 'allow_register_phone_captcha')) {
                return $this->fail('图形验证码错误');
            }

            if ($this->findByPhone($request->input('phone_code', '86'), $phone) !== null) {
                return $this->fail('该手机号已被注册');
            }

            [$sent, $msg] = $this->dispatchCode(self::SCENE_REGISTER, $phone);

            return $sent ? $this->ok(null, $msg) : $this->fail($msg);
        }

        return $this->fail('接收账号不能为空');
    }

    // -----------------------------------------------------------------
    // Password reset
    // -----------------------------------------------------------------

    public function pwreset(Request $request): View
    {
        return view('web.auth.pwreset', array_merge($this->shared(), [
            'Title' => '找回密码',
            'TplName' => 'pwreset',
            'Pwreset' => [
                'allow_login_phone' => Settings::on('allow_login_phone', true),
                'allow_login_email' => Settings::on('allow_login_email', true),
            ],
            'Verify' => $this->verifyPayload(),
            'SmsCountry' => $this->phoneCodes(),
        ]));
    }

    /**
     * POST /pwreset?action=email|phone
     */
    public function attemptPwreset(Request $request): RedirectResponse|JsonResponse
    {
        $action = (string) $request->input('action', $request->query('action', 'email'));

        if ($action === 'phone') {
            if (! $this->checkCaptcha($request, 'allow_phone_forgetpwd_captcha')) {
                return $this->resetFailure($request, '图形验证码错误');
            }

            $phone = trim((string) $request->input('phone', ''));
            $client = $this->findByPhone($request->input('phone_code', '86'), $phone);

            if ($client === null) {
                return $this->resetFailure($request, '账号不存在');
            }

            if (! $this->consumeCode(self::SCENE_FORGET, $phone, (string) $request->input('code', ''))) {
                return $this->resetFailure($request, '短信验证码错误或已失效');
            }

            return $this->applyNewPassword($request, $client);
        }

        if (! $this->checkCaptcha($request, 'allow_email_forgetpwd_captcha')) {
            return $this->resetFailure($request, '图形验证码错误');
        }

        $email = trim((string) $request->input('email', ''));
        $client = $email === '' ? null : Client::query()->where('email', $email)->first();

        if ($client === null) {
            return $this->resetFailure($request, '账号不存在');
        }

        if (! $this->consumeCode(self::SCENE_FORGET, $email, (string) $request->input('code', ''))) {
            return $this->resetFailure($request, '邮箱验证码错误或已失效');
        }

        return $this->applyNewPassword($request, $client);
    }

    protected function applyNewPassword(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $password = PasswordHasher::acceptedPlain((string) $request->input('password', ''));
        $confirm = PasswordHasher::acceptedPlain((string) $request->input('checkPassword', $request->input('repassword', '')));

        if ($password === '' || strlen($password) < 6) {
            return $this->resetFailure($request, '密码长度不能少于6位');
        }

        if ($confirm !== '' && $confirm !== $password) {
            return $this->resetFailure($request, '两次输入的密码不一致');
        }

        $client->password = PasswordHasher::client($password);
        $client->pwresetkey = '';
        $client->pwresetexpiry = 0;
        $client->update_time = time();
        $client->save();

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok(['redirect' => '/clientarea'], '密码重置成功');
        }

        return redirect()->to('/clientarea')->with('success', '密码重置成功');
    }

    /**
     * POST reset_email_send / reset_phone_send
     */
    public function resetSend(Request $request): JsonResponse
    {
        if (! $this->checkMk($request)) {
            return $this->fail('请求已失效，请刷新页面重试');
        }

        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));

        if ($email !== '') {
            if (! $this->checkCaptcha($request, 'allow_email_forgetpwd_captcha')) {
                return $this->fail('图形验证码错误');
            }

            if (! Client::query()->where('email', $email)->exists()) {
                return $this->fail('账号不存在');
            }

            [$sent, $msg] = $this->dispatchCode(self::SCENE_FORGET, $email);

            return $sent ? $this->ok(null, $msg) : $this->fail($msg);
        }

        if ($phone !== '') {
            if (! $this->checkCaptcha($request, 'allow_phone_forgetpwd_captcha')) {
                return $this->fail('图形验证码错误');
            }

            if ($this->findByPhone($request->input('phone_code', '86'), $phone) === null) {
                return $this->fail('账号不存在');
            }

            [$sent, $msg] = $this->dispatchCode(self::SCENE_FORGET, $phone);

            return $sent ? $this->ok(null, $msg) : $this->fail($msg);
        }

        return $this->fail('接收账号不能为空');
    }

    // -----------------------------------------------------------------
    // Bind (third-party callback)
    // -----------------------------------------------------------------

    public function bind(Request $request): View
    {
        return view('web.auth.bind', array_merge($this->shared(), [
            'Title' => '绑定账号',
            'TplName' => 'bind',
            'CallbackInfo' => (int) $request->input('type', 0),
            'SmsCountry' => $this->phoneCodes(),
        ]));
    }

    public function bindSubmit(Request $request): RedirectResponse|JsonResponse
    {
        $client = $this->client();

        if ($client === null) {
            return $this->fail('请先登录', 401);
        }

        $action = (string) $request->input('action', 'email');

        if ($action === 'phone') {
            $phone = trim((string) $request->input('phone', ''));

            if (! $this->consumeCode(self::SCENE_BIND, $phone, (string) $request->input('code', ''))) {
                return $this->back($request, false, '验证码错误或已失效');
            }

            $client->phonenumber = $phone;
            $client->phone_code = (string) $request->input('phone_code', '86');
            $client->save();

            return $this->back($request, true, '手机绑定成功', '/security');
        }

        $email = trim((string) $request->input('email', ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->back($request, false, '邮箱格式不正确');
        }

        if (! $this->consumeCode(self::SCENE_BIND, $email, (string) $request->input('code', ''))) {
            return $this->back($request, false, '验证码错误或已失效');
        }

        $client->email = $email;
        $client->save();

        return $this->back($request, true, '邮箱绑定成功', '/security');
    }

    public function bindSend(Request $request): JsonResponse
    {
        if (! $this->checkMk($request)) {
            return $this->fail('请求已失效，请刷新页面重试');
        }

        $account = trim((string) $request->input('email', $request->input('phone', '')));

        [$sent, $msg] = $this->dispatchCode(self::SCENE_BIND, $account);

        return $sent ? $this->ok(null, $msg) : $this->fail($msg);
    }

    // -----------------------------------------------------------------
    // Logout / captcha
    // -----------------------------------------------------------------

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to('/login');
    }

    /**
     * GET /verify?name=<flag> — PNG (or SVG) captcha bytes.
     */
    public function verify(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $name = (string) $request->input('name', 'default');
        $code = Captcha::code(Settings::int('captcha_length', 4) ?: 4);

        Session::put('captcha.' . $name, $code);

        $bytes = Captcha::png($code);

        return response($bytes, 200, [
            'Content-Type' => Captcha::contentType($bytes),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    protected function loginPayload(): array
    {
        $emailCaptcha = Settings::on('allow_login_email_captcha');
        $phoneCaptcha = Settings::on('allow_login_phone_captcha');
        $codeCaptcha = Settings::on('allow_login_code_captcha');

        return [
            'allow_login_email' => Settings::on('allow_login_email', true),
            'allow_login_phone' => Settings::on('allow_login_phone', true),
            'allow_login_id' => Settings::on('allow_login_id'),
            'allow_login_email_captcha' => $emailCaptcha,
            'allow_login_phone_captcha' => $phoneCaptcha,
            'allow_login_code_captcha' => $codeCaptcha,
            'allow_login_register_sms_global' => Settings::on('allow_login_register_sms_global'),
            'allow_second_verify' => Settings::on('second_verify'),
            'second_verify_action_home' => Settings::needsSecondVerify('login'),
            'is_captcha' => $emailCaptcha || $phoneCaptcha || $codeCaptcha,
            'allow_register' => Settings::on('allow_login_register', true),
        ];
    }

    protected function verifyPayload(): array
    {
        return [
            'allow_register_email_captcha' => Settings::on('allow_register_email_captcha'),
            'allow_register_phone_captcha' => Settings::on('allow_register_phone_captcha'),
            'allow_email_forgetpwd_captcha' => Settings::on('allow_email_forgetpwd_captcha'),
            'allow_phone_forgetpwd_captcha' => Settings::on('allow_phone_forgetpwd_captcha'),
            'allow_login_email_captcha' => Settings::on('allow_login_email_captcha'),
            'allow_login_phone_captcha' => Settings::on('allow_login_phone_captcha'),
            'allow_login_code_captcha' => Settings::on('allow_login_code_captcha'),
        ];
    }

    protected function registerPayload(): array
    {
        return [
            'allow_register_email' => Settings::on('allow_register_email', true),
            'allow_register_phone' => Settings::on('allow_register_phone', true),
            'allow_email_register_code' => Settings::on('allow_email_register_code'),
            'allow_register_email_captcha' => Settings::on('allow_register_email_captcha'),
            'allow_register_phone_captcha' => Settings::on('allow_register_phone_captcha'),
            'fields' => $this->registerCustomFields(),
            'login_register_custom_require' => $this->literalRegisterFields(),
            'login_register_custom_require_list' => array_column($this->literalRegisterFields(), 'label', 'name'),
        ];
    }

    /**
     * Client custom fields rendered on the register form.
     */
    protected function registerCustomFields(): array
    {
        return CustomField::query()
            ->where('type', 'client')
            ->orderBy('sortorder')
            ->get()
            ->map(fn (CustomField $field) => [
                'id' => (int) $field->id,
                'fieldname' => (string) $field->fieldname,
                'fieldtype' => (string) $field->fieldtype,
                'description' => (string) $field->description,
                'required' => (int) $field->required,
                'dropdown_option' => $field->options(),
            ])
            ->all();
    }

    /**
     * Validate required custom fields posted as `fields[<id>]`.
     */
    protected function validateCustomFields(Request $request, string $key): ?string
    {
        $submitted = (array) $request->input($key, []);
        $fields = CustomField::query()->where('type', 'client')->where('required', 1)->get();

        foreach ($fields as $field) {
            $value = $submitted[$field->id] ?? null;

            if (is_array($value)) {
                $value = array_filter($value, fn ($v) => $v !== '' && $v !== null);
            }

            if ($value === null || $value === '' || $value === []) {
                return '请填写' . $field->fieldname;
            }
        }

        return null;
    }

    /**
     * Persist `fields[<id>]` values onto the client.
     */
    protected function storeCustomFields(Client $client, Request $request, string $key, int $relid = 0): void
    {
        $submitted = (array) $request->input($key, []);

        foreach ($submitted as $fieldId => $value) {
            $field = CustomField::query()->find((int) $fieldId);

            if ($field === null) {
                continue;
            }

            $encoded = is_array($value) ? json_encode(array_values($value), JSON_UNESCAPED_UNICODE) : (string) $value;

            CustomFieldValue::query()->updateOrCreate(
                ['fieldid' => (int) $fieldId, 'relid' => $relid > 0 ? $relid : $client->id],
                ['value' => $encoded, 'create_time' => time(), 'update_time' => time()]
            );
        }
    }

    protected function findByPhone(int|string $phoneCode, string $phone): ?Client
    {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        $code = preg_replace('/\D/', '', (string) $phoneCode);

        return Client::query()
            ->where('phonenumber', $phone)
            ->when($code !== '', fn ($query) => $query->where('phone_code', (int) $code))
            ->first();
    }

    /**
     * Only same-origin relative targets are honoured, so `?redirect=` cannot be
     * used as an open redirect.
     */
    protected function safeRedirect(Request $request): ?string
    {
        $target = (string) $request->input('redirect', $request->input('redirect_url', ''));

        if ($target === '') {
            return null;
        }

        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        return $target;
    }

    protected function loginFailure(Request $request, string $msg, int $status = 400): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $this->fail($msg, $status);
        }

        return redirect()->back()->withInput($request->except('password', 'checkPassword'))->with('error', $msg);
    }

    protected function registerFailure(Request $request, string $msg): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $this->fail($msg, 406);
        }

        return redirect()->back()->withInput($request->except('password', 'checkPassword'))->with('error', $msg);
    }

    protected function resetFailure(Request $request, string $msg): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $this->fail($msg, 406);
        }

        return redirect()->back()->withInput($request->except('password', 'checkPassword'))->with('error', $msg);
    }

    /**
     * Record a successful sign-in in `shd_system_log`, which backs /loginlog.
     */
    protected function recordLoginLog(Client $client, string $ip): void
    {
        DB::table('system_log')->insert([
            'create_time' => time(),
            'description' => '登录成功',
            'user' => (string) ($client->username ?: $client->email),
            'uid' => (int) $client->id,
            // `shd_system_log.user_type` is a tinyint, not the actor's name:
            // writing 'client' here raises error 1366 under strict mode and
            // breaks the login response.
            'user_type' => 1,
            'ip' => $ip,
            'log_type' => 'login',
            'relid' => 0,
        ]);
    }
}

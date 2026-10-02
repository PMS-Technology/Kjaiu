<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use App\Support\ApiResponse;
use App\Support\PasswordHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administrator authentication and the SPA bootstrap payload.
 *
 * The SPA boots from `GET login_page`, authenticates with `POST login` and then
 * loads `GET common` for every dictionary it needs. Sessions are cookie based
 * (the request layer sets `withCredentials`), so these endpoints live in the
 * web middleware group.
 */
class AuthController extends AdminController
{
    /**
     * Data the login screen needs before the administrator is authenticated.
     */
    public function loginPage(Request $request)
    {
        $captcha = SettingService::bool('admin_login_captcha', false);

        return $this->ok([
            'second_verify_admin' => (string) (SettingService::value('second_verify_admin', '0') ?? '0'),
            'second_verify_action_admin' => $this->secondVerifyActions(),
            'captcha' => $captcha ? 1 : 0,
            'login_captcha' => $captcha ? 1 : 0,
        ], '请求成功');
    }

    /**
     * `POST login` — legacy md5 credential check against `shd_user`.
     */
    public function login(Request $request)
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return $this->validationFail('用户名和密码不能为空');
        }

        // Client-area forms AES-encrypt the password before submit; accept
        // either that or the plain value.
        $plain = PasswordHasher::acceptedPlain($password);

        $admin = User::query()->where('user_login', $username)->first();

        if ($admin === null || ! PasswordHasher::checkAdmin($plain, $admin->user_pass)) {
            return $this->fail('用户名或密码错误', 400);
        }

        if ((int) $admin->user_status !== User::STATUS_ENABLED) {
            return $this->fail('账号已被禁用', 400);
        }

        if (SettingService::bool('main_tenance_mode') && (int) $admin->id !== 1) {
            return $this->fail('系统维护中，请稍后再试', 400);
        }

        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        $admin->last_login_time = time();
        $admin->last_login_ip = (string) $request->ip();
        $admin->last_act_time = time();
        $admin->save();

        $this->recordAdminLog($admin);

        // The SPA reads the signed-in identity from `user` and caches the
        // permission tree from `rule`, plus `user_tastes` for per-account UI
        // preferences — the same payload the original returns on login.
        return $this->ok([
            'user' => [
                'id' => (int) $admin->id,
                'user_login' => (string) $admin->user_login,
                'user_nickname' => (string) $admin->user_nickname,
                'user_email' => (string) $admin->user_email,
                'is_sale' => (int) $admin->is_sale,
            ],
            'rule' => $this->ruleTree(),
            'user_tastes' => $this->userTastes($admin),
            'display_lang_config' => null,
            'token' => $request->session()->getId(),
        ], '登录成功');
    }

    /**
     * Legacy alias of `login` kept because the original exposes both paths.
     */
    public function adLogin(Request $request)
    {
        return $this->login($request);
    }

    /**
     * `GET ad_login` — the SPA uses this to probe an existing session.
     */
    public function adLoginPage(Request $request)
    {
        if (! Auth::guard('admin')->check()) {
            return $this->fail('请先登录', ApiResponse::UNAUTHORIZED);
        }

        return $this->me($request);
    }

    public function logout(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        if ($admin !== null) {
            DB::table('admin_log')
                ->where('admin_username', $admin->user_login)
                ->whereNull('logouttime')
                ->update(['logouttime' => time()]);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->ok(null, '退出成功');
    }

    /**
     * Current administrator identity, used by the SPA shell.
     */
    public function me(Request $request)
    {
        $admin = $this->admin();

        if ($admin === null) {
            return $this->fail('请先登录', ApiResponse::UNAUTHORIZED);
        }

        return $this->ok([
            'id' => (int) $admin->id,
            'user_login' => $admin->user_login,
            'user_nickname' => $admin->user_nickname,
            'user_email' => $admin->user_email,
            'avatar' => $admin->avatar,
            'language' => $admin->language ?: 'zh-cn',
            'is_sale' => (int) $admin->is_sale,
            'role' => $this->roleName((int) $admin->id),
        ], '请求成功');
    }

    /**
     * `GET common` — the dictionary payload every SPA page starts from.
     */
    public function common(Request $request)
    {
        $admin = $this->admin();

        return $this->ok([
            'company_name' => (string) (SettingService::value('company_name') ?: config('kjaiu.name')),
            'domain' => (string) (SettingService::value('domain') ?: url('/')),
            'system_url' => (string) (SettingService::value('system_url') ?: ''),
            'admin' => (string) ($admin->user_login ?? ''),
            'system_language' => (string) (SettingService::value('language') ?: 'chinese'),
            'system_title' => (string) (SettingService::value('system_title') ?: ''),
            'gateway' => AdminMeta::gateways(),
            'config' => $this->commonConfig(),
            'sale' => $this->saleList(),
            'is_aff' => (string) (SettingService::value('affiliate_open', '0') ?? '0'),
            'per_page_limit' => (int) (SettingService::value('per_page_limit') ?: 50),
            'second_verify_admin' => (string) (SettingService::value('second_verify_admin', '0') ?? '0'),
            'second_verify_action_admin' => $this->secondVerifyActions(),
            'seniorConfig' => true,
            'pro_support_features' => ['seniorConfig', 'marketingPush', 'InvoiceContract', 'Oauth'],
            'lang' => AdminMeta::LANGUAGES,
        ], '请求成功', ['rule' => $this->menuRules()]);
    }

    /**
     * `GET common/get_getways` — enabled payment gateways.
     */
    public function getGateways(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'gateway' => AdminMeta::gateways(),
        ]);
    }

    /**
     * `GET common/get_client_groups`
     */
    public function getClientGroups(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'client_groups' => AdminMeta::clientGroups(),
        ]);
    }

    /**
     * `GET common/get_sms_country`
     */
    public function getSmsCountry(Request $request)
    {
        $countries = DB::table('sms_country')->orderBy('id')->get()->toArray();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'sms_country' => $countries,
        ]);
    }

    /**
     * `GET common/get_email_tem {type}` — email templates for the pickers.
     */
    public function getEmailTemplates(Request $request)
    {
        $type = (string) $request->input('type', '');
        $query = DB::table('email_templates')->orderBy('id');

        if ($type !== '') {
            $query->where('type', $type);
        }

        $rows = $query->get(['id', 'name', 'type'])->map(function ($row) {
            return ['id' => (int) $row->id, 'name' => $row->name, 'type' => $row->type];
        })->all();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'email' => $rows,
        ]);
    }

    /**
     * `GET common/get_product_list {type,id}` — product picker.
     */
    public function getProductList(Request $request)
    {
        $type = $request->input('type');
        $id = $request->input('id');

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'data' => AdminMeta::productList(
                is_string($type) ? $type : null,
                $id ? (int) $id : null,
            ),
            'client_groups' => AdminMeta::clientGroups(),
        ]);
    }

    /**
     * `GET common/host_list {uid}` — a client's services for message pickers.
     */
    public function getHostList(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        // The service table stores only `productid`, so the product name comes
        // from a join rather than a (non-existent) `productname` column.
        $hosts = \App\Models\Host::query()
            ->leftJoin('products', 'products.id', '=', 'host.productid')
            ->when($uid > 0, fn ($q) => $q->where('host.uid', $uid))
            ->orderByDesc('host.id')
            ->limit(500)
            ->get([
                'host.id',
                'host.uid',
                'host.domain',
                'host.domainstatus',
                'host.productid',
                'products.name as productname',
            ])
            ->toArray();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'host_list' => $hosts,
        ]);
    }

    /**
     * `GET common/get_promo_code {type}`
     */
    public function getPromoCodes(Request $request)
    {
        $rows = DB::table('promo_code')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'code', 'type', 'value'])
            ->toArray();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'promo_code' => $rows,
        ]);
    }

    /**
     * `GET common/product_config_options`
     */
    public function getProductConfigOptions(Request $request)
    {
        $rows = DB::table('product_config_options')
            ->orderBy('id')
            ->get(['id', 'option_name', 'option_type', 'gid'])
            ->toArray();

        return $this->ok($rows);
    }

    /**
     * `GET common/sale_list` — the sales staff picker.
     */
    public function getSaleList(Request $request)
    {
        return $this->ok($this->saleList());
    }

    /**
     * `POST second_verify_send` — send the admin second-factor code.
     *
     * The panel reuses `VerifyCodeService` for the mail/SMS transport; without
     * a configured gateway the code is only written to the verification store,
     * which is what a development install does too.
     */
    public function secondVerifySend(Request $request)
    {
        $admin = $this->admin();

        if ($admin === null) {
            return $this->fail('请先登录', ApiResponse::UNAUTHORIZED);
        }

        $type = (string) $request->input('type', 'email');
        $code = (string) random_int(100000, 999999);

        $request->session()->put('admin_second_verify_code', $code);
        $request->session()->put('admin_second_verify_expire', time() + 600);

        try {
            $service = new \App\Services\VerifyCodeService();
            $scene = 'admin_second_verify';

            $service->issue($scene, (string) ($admin->user_email ?: $admin->mobile), $code);

            if ($type === 'phone' && $admin->mobile) {
                $service->send('sms', (string) $admin->mobile, $code, $scene);
            } elseif ($admin->user_email) {
                $service->send('email', (string) $admin->user_email, $code, $scene);
            }
        } catch (\Throwable) {
            // Delivery failures must not block the flow; the code stays in the
            // session for the verification step.
        }

        return $this->ok(['type' => $type], '验证码已发送');
    }

    /**
     * Verify a submitted second-factor code.
     */
    public function secondVerifyCheck(Request $request)
    {
        $code = (string) $request->input('code', '');
        $expected = (string) $request->session()->get('admin_second_verify_code', '');
        $expire = (int) $request->session()->get('admin_second_verify_expire', 0);

        if ($expected === '' || $expire < time() || ! hash_equals($expected, $code)) {
            return $this->validationFail('验证码错误或已过期');
        }

        $request->session()->put('admin_second_verify_passed', time());

        return $this->ok(null, '验证成功');
    }

    /**
     * `GET get_verify_code` — the image captcha used by the login screen.
     */
    public function getVerifyCode(Request $request)
    {
        $length = (int) (SettingService::value('captcha_length') ?: 4);
        $length = max(3, min(6, $length));

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXY3456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $request->session()->put('admin_captcha', Str::upper($code));

        return $this->ok(['code' => $code], '请求成功');
    }

    /**
     * `GET clear_cache` — the header's cache-reset button.
     */
    public function clearCache(Request $request)
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            SettingService::flush();
        } catch (\Throwable) {
            // The SPA only cares that the request completed.
        }

        $this->log('清空缓存');

        return $this->ok(null, '清除成功');
    }

    /**
     * The signed-in administrator's permission tree, nested by `pid`.
     *
     * The SPA drives both the sidebar and the 403 handling from this structure,
     * so it must be nested rather than flat.
     */
    protected function ruleTree(): array
    {
        try {
            $rows = DB::table('auth_rule')
                ->where('is_display', 1)
                ->where('status', 1)
                ->orderBy('order')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        } catch (\Throwable) {
            return [];
        }

        $byId = [];
        foreach ($rows as $row) {
            $row['list'] = [];
            $byId[(int) $row['id']] = $row;
        }

        $tree = [];
        foreach (array_keys($byId) as $id) {
            $pid = (int) $byId[$id]['pid'];

            // A child whose parent is hidden is promoted to the top level
            // rather than disappearing from the menu.
            if ($pid > 0 && isset($byId[$pid])) {
                $byId[$pid]['list'][] = &$byId[$id];
            } else {
                $tree[] = &$byId[$id];
            }
        }

        return $tree;
    }

    /**
     * Per-administrator UI preferences; none are stored yet, so the SPA gets a
     * stable empty shape instead of null.
     */
    protected function userTastes(User $admin): array
    {
        try {
            $row = DB::table('user_tastes')->where('user_id', $admin->id)->first();

            if ($row !== null) {
                $decoded = json_decode((string) ($row->tastes ?? ''), true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // The table is optional in a partial import.
        }

        return [];
    }

    /**
     * `GET menus/allLinks` / `menus/getCreateWebData` — the nav tree the SPA
     * caches in `localStorage.menuList`.
     */
    public function menuRules(): ?array
    {
        try {
            $rules = DB::table('auth_rule')
                ->where('is_display', 1)
                ->orderBy('order')
                ->get(['id', 'pid', 'title', 'name', 'url', 'type'])
                ->toArray();
        } catch (\Throwable) {
            return null;
        }

        return $rules === [] ? null : $rules;
    }

    /**
     * Dictionary block embedded in `common`.
     */
    private function commonConfig(): array
    {
        return [
            'client_status' => AdminMeta::CLIENT_STATUS,
            'client_certifi_status' => AdminMeta::CLIENT_CERTIFI_STATUS,
            'invoice_payment_status' => AdminMeta::INVOICE_STATUS,
            'order_status' => AdminMeta::ORDER_STATUS,
            'domainstatus' => AdminMeta::DOMAIN_STATUS,
            'sslDomainStatus' => [
                'Pending' => ['name' => '待付款', 'color' => '#EE6161'],
                'Active' => ['name' => '未使用', 'color' => '#67A4FF'],
                'Verifiy_Active' => ['name' => '待验证', 'color' => '#fca426'],
                'Issue_Active' => ['name' => '已签发', 'color' => '#3FBF70'],
                'Overdue_Active' => ['name' => '即将到期', 'color' => '#6064FF'],
                'Cancelled' => ['name' => '已取消', 'color' => '#959799'],
                'Deleted' => ['name' => '已过期', 'color' => '#2d2d2d'],
            ],
            'user_is_sale' => [['name' => '否'], ['name' => '是']],
            'user_sale_is_use' => [['name' => '否'], ['name' => '是']],
            'product_type' => AdminMeta::PRODUCT_TYPES,
            'billing_cycle' => AdminMeta::BILLING_CYCLES,
        ];
    }

    /**
     * Sales staff for the filters: every enabled administrator flagged as a
     * salesperson, plus a synthetic "全部" entry as in the original.
     */
    private function saleList(): array
    {
        return User::query()
            ->where('user_status', User::STATUS_ENABLED)
            ->where('is_sale', 1)
            ->orderBy('id')
            ->get(['id', 'user_login', 'user_nickname'])
            ->map(fn (User $u) => [
                'id' => (int) $u->id,
                'user_login' => $u->user_login,
                'user_nickname' => $u->user_nickname,
            ])
            ->all();
    }

    private function secondVerifyActions(): array
    {
        $raw = (string) (SettingService::value('second_verify_action_admin') ?? '');

        if ($raw === '') {
            return [''];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Role display name for the signed-in administrator (header dropdown).
     */
    private function roleName(int $userId): string
    {
        try {
            $roleId = DB::table('role_user')->where('user_id', $userId)->value('role_id');

            if (! $roleId) {
                return $userId === 1 ? '超级管理员' : '';
            }

            return (string) (DB::table('role')->where('id', $roleId)->value('name') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * `shd_admin_log` row opened at login and closed at logout.
     */
    private function recordAdminLog(User $admin): void
    {
        try {
            DB::table('admin_log')->insert([
                'admin_username' => $admin->user_login,
                'logintime' => time(),
                'logouttime' => null,
                'ipaddress' => (string) request()->ip(),
                'sessionid' => request()->session()->getId(),
                'lastvisit' => time(),
                'port' => (string) request()->getPort(),
            ]);
        } catch (\Throwable) {
            // Audit logging is best-effort.
        }
    }
}

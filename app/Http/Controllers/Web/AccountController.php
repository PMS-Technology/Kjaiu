<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Web\Concerns\SendsVerifyCodes;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\SystemMessage;
use App\Services\VerifyCodeService;
use App\Support\PasswordHasher;
use App\Support\Settings;
use App\Support\StatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Account management: 个人信息 (/details), 安全中心 (/security),
 * API 管理 (/apimanage), 消息中心 (/message) and the three log pages.
 */
class AccountController extends WebController
{
    use SendsVerifyCodes;

    // -----------------------------------------------------------------
    // 个人信息
    // -----------------------------------------------------------------

    public function details(Request $request): View|RedirectResponse
    {
        $client = $this->requireClient();

        if ($request->isMethod('post')) {
            return $this->updateDetails($request, $client);
        }

        return view('web.account.details', array_merge($this->shared(), [
            'Title' => '个人信息',
            'TplName' => 'details',
            'Details' => [
                'areas' => ['country' => $this->countries()],
                'provinces' => [],
                'cities' => [],
                'regions' => [],
            ],
        ]));
    }

    /**
     * PUT /user_info and the /details form post.
     */
    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $client = $this->requireClient();

        if ($request->isMethod('post') && ! $request->expectsJson() && ! $request->ajax()) {
            return $this->updateDetails($request, $client);
        }

        return $this->updateDetails($request, $client);
    }

    protected function updateDetails(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $fields = [
            'qq' => 50,
            'username' => 50,
            'companyname' => 80,
            'country' => 100,
            'province' => 100,
            'city' => 100,
            'region' => 100,
            'address1' => 100,
            'postcode' => 100,
        ];

        foreach ($fields as $field => $length) {
            if ($request->has($field)) {
                $client->{$field} = mb_substr(trim((string) $request->input($field)), 0, $length);
            }
        }

        $gateway = (string) $request->input('defaultgateway', '');

        if ($gateway !== '') {
            $client->defaultgateway = $gateway;
        }

        // Checkbox pairs only post when ticked, so an absent value means off.
        $client->marketing_emails_opt_in = (int) $request->input('marketing_emails_opt_in', 0) === 1 ? 1 : 0;
        $client->send_close = (int) $request->input('send_close', 0) === 1 ? 1 : 0;
        $client->update_time = time();
        $client->save();

        $this->saveClientCustomFields($client, $request);

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok($this->userInfoPayload($client), '保存成功');
        }

        return redirect()->to('/details')->with('success', '保存成功');
    }

    /**
     * GET user_info
     */
    public function userInfo(Request $request): JsonResponse
    {
        $client = $this->client();

        if ($client === null) {
            return $this->unauthorized();
        }

        return $this->ok($this->userInfoPayload($client));
    }

    // -----------------------------------------------------------------
    // 安全中心
    // -----------------------------------------------------------------

    public function security(Request $request): View
    {
        $client = $this->requireClient();
        $percent = $this->securityCompletion($client);

        return view('web.account.security', array_merge($this->shared(), [
            'Title' => '安全中心',
            'TplName' => 'security',
            'Security' => [
                'oauthBind' => [],
                'email' => (string) $client->email,
                'phonenumber' => (string) $client->phonenumber,
                'phone_code' => (string) $client->phone_code,
                'certifi_status' => 0,
                'percentage' => $percent,
            ],
            'BindPhoneChange' => (int) $client->phonenumber !== 0 ? 1 : 0,
            'BindEmailChange' => trim((string) $client->email) !== '' ? 1 : 0,
            'percentage' => $percent,
            'SmsCountry' => $this->phoneCodes(),
        ]));
    }

    /**
     * POST /modify_password
     *
     * `flag` 1 = change an existing password, 2 = set one for the first time.
     */
    public function modifyPassword(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $flag = (int) $request->input('flag', 1);

        $password = PasswordHasher::acceptedPlain((string) $request->input('password', ''));
        $confirm = PasswordHasher::acceptedPlain((string) $request->input('re_password', $request->input('checkPassword', '')));

        if ($password === '' || strlen($password) < 6) {
            return $this->fail('密码长度不能少于6位', 406);
        }

        if ($confirm !== '' && $confirm !== $password) {
            return $this->fail('两次输入的密码不一致', 406);
        }

        if ($flag === 1) {
            $old = PasswordHasher::acceptedPlain((string) $request->input('old_password', ''));

            if (! PasswordHasher::checkClient($old, (string) $client->password)) {
                return $this->fail('原密码错误');
            }

            if ($this->secondVerifyRequired('modify_password')) {
                $check = $this->verifySecondCode($request, $client, 'modify_password');

                if ($check !== null) {
                    return $check;
                }
            }
        }

        $client->password = PasswordHasher::client($password);
        $client->update_time = time();
        $client->save();

        // The original signs the client out so the new credential is required.
        Auth::guard('client')->logout();
        $request->session()->invalidate();

        return $this->ok(['url' => '/login'], '密码修改成功，请重新登录');
    }

    /**
     * POST /bind_phone_handle
     */
    public function bindPhone(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $phone = trim((string) $request->input('phone', ''));

        if ($phone === '') {
            return $this->fail('请输入手机号');
        }

        if (! $this->consumeCode('bind_phone', $phone, (string) $request->input('code', ''))) {
            return $this->fail('验证码错误或已失效');
        }

        if (Client::query()->where('phonenumber', $phone)->where('id', '!=', $client->id)->exists()) {
            return $this->fail('该手机号已被其他账号绑定');
        }

        $client->phonenumber = $phone;
        $client->phone_code = (string) $request->input('phone_code', '86');
        $client->save();

        return $this->ok(['phonenumber' => $phone], '绑定成功');
    }

    /**
     * POST /bind_phone_change — rebind, `type` 1 verifies the old number and
     * `type` 2 binds the new one.
     */
    public function bindPhoneChange(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $type = (int) $request->input('type', 1);

        if ($type === 1) {
            $tel = trim((string) $request->input('tel', ''));

            if ($tel !== (string) $client->phonenumber) {
                return $this->fail('手机号与当前绑定不一致');
            }

            if (! $this->consumeCode('bind_phone', $tel, (string) $request->input('code', ''))) {
                return $this->fail('验证码错误或已失效');
            }

            return $this->ok(['next' => 2], '原手机号验证通过');
        }

        $phone = trim((string) $request->input('phone', $request->input('tel', '')));

        if ($phone === '') {
            return $this->fail('请输入新手机号');
        }

        if (! $this->consumeCode('bind_phone', $phone, (string) $request->input('code', ''))) {
            return $this->fail('验证码错误或已失效');
        }

        if (Client::query()->where('phonenumber', $phone)->where('id', '!=', $client->id)->exists()) {
            return $this->fail('该手机号已被其他账号绑定');
        }

        $client->phonenumber = $phone;
        $client->save();

        return $this->ok(['phonenumber' => $phone], '换绑成功');
    }

    /**
     * POST /bind_email_handle
     */
    public function bindEmail(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $email = trim((string) $request->input('email', ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('邮箱格式不正确');
        }

        if (! $this->consumeCode('bind_email', $email, (string) $request->input('code', ''))) {
            return $this->fail('验证码错误或已失效');
        }

        if (Client::query()->where('email', $email)->where('id', '!=', $client->id)->exists()) {
            return $this->fail('该邮箱已被其他账号绑定');
        }

        $client->email = $email;
        $client->email_verified = 1;
        $client->save();

        return $this->ok(['email' => $email], '绑定成功');
    }

    /**
     * POST /change_email_handle
     */
    public function changeEmail(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $type = (int) $request->input('type', 1);
        $email = trim((string) $request->input('email', ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('邮箱格式不正确');
        }

        if (! $this->consumeCode('change_email', $email, (string) $request->input('code', ''))) {
            return $this->fail('验证码错误或已失效');
        }

        if ($type === 1 && $email !== (string) $client->email) {
            return $this->fail('邮箱与当前绑定不一致');
        }

        if (Client::query()->where('email', $email)->where('id', '!=', $client->id)->exists()) {
            return $this->fail('该邮箱已被其他账号绑定');
        }

        $client->email = $email;
        $client->email_verified = 1;
        $client->save();

        return $this->ok(['email' => $email], $type === 1 ? '原邮箱验证通过' : '换绑成功');
    }

    /**
     * POST /login_sms_reminder
     */
    public function loginSmsReminder(Request $request): JsonResponse
    {
        return $this->loginReminder($request, 'phone');
    }

    /**
     * POST /login_email_reminder
     */
    public function loginEmailReminder(Request $request): JsonResponse
    {
        return $this->loginReminder($request, 'email');
    }

    /**
     * POST /login_sms_reminder and /login_email_reminder.
     */
    public function loginReminder(Request $request, string $channel): JsonResponse
    {
        $client = $this->requireClient();
        $status = (int) $request->input('status', 0) === 1 ? 1 : 0;

        // Turning the reminder off is protected by a code; turning it on is not.
        if ($status === 0) {
            $account = $channel === 'email' ? (string) $client->email : (string) $client->phonenumber;
            $scene = $channel === 'email' ? 'remind_email_send' : 'remind_send';

            if (! $this->consumeCode($scene, $account, (string) $request->input('code', ''))) {
                return $this->fail('验证码错误或已失效');
            }
        }

        if ($channel === 'email') {
            $client->email_remind = $status;
        } else {
            $client->is_login_sms_reminder = $status;
        }

        $client->save();

        return $this->ok(['status' => $status], $status === 1 ? '已开启登录提醒' : '已关闭登录提醒');
    }

    /**
     * POST /toggle_second_verify
     */
    public function toggleSecondVerify(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        if (! $this->allowSecondVerify()) {
            return $this->fail('系统未开启二次验证');
        }

        $status = (int) $request->input('second_verify', 0) === 1 ? 1 : 0;

        if ($status === 0) {
            $check = $this->verifySecondCode($request, $client, (string) $request->input('type', ''));

            if ($check !== null) {
                return $check;
            }
        }

        $client->second_verify = $status;
        $client->save();

        return $this->ok(['second_verify' => $status], $status === 1 ? '二次验证已开启' : '二次验证已关闭');
    }

    /**
     * GET /second_verify_page — the modal body for a sensitive action.
     */
    public function secondVerifyPage(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        return $this->ok([
            'allow_type' => $this->secondVerifyTypeList($client),
            'action' => (string) $request->input('action', ''),
        ]);
    }

    /**
     * POST /second_verify_send
     */
    public function secondVerifySend(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $action = (string) $request->input('action', '');
        $type = (string) $request->input('type', '');

        if (! $this->secondVerifyRequired($action)) {
            return $this->fail('该操作无需二次验证');
        }

        $account = $this->accountForType($client, $type);

        if ($account === '') {
            return $this->fail('该账号未绑定可用的验证方式');
        }

        [$sent, $msg] = $this->dispatchCode('second_verify:' . $action, $account);

        return $sent ? $this->ok(['expire' => VerifyCodeService::TTL], $msg) : $this->fail($msg);
    }

    /**
     * POST /get_check_code — the shared code sender behind the security cards.
     */
    public function getCheckCode(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $type = (string) $request->input('type', 'phone');
        $action = (string) $request->input('action', '');

        $scene = match ($action) {
            'bind_phone', 'bind_phone_code' => 'bind_phone',
            'bind_email' => 'bind_email',
            'change_email' => 'change_email',
            'remind_send' => 'remind_send',
            'remind_email_send' => 'remind_email_send',
            'second_verify_send' => 'second_verify:' . (string) $request->input('second_action', 'modify_password'),
            default => $action === '' ? 'bind_phone' : $action,
        };

        $account = $type === 'email'
            ? trim((string) $request->input('email', $client->email))
            : trim((string) $request->input('phone', $client->phonenumber));

        if ($account === '') {
            return $this->fail('接收账号不能为空');
        }

        [$sent, $msg] = $this->dispatchCode($scene, $account);

        return $sent ? $this->ok(['expire' => VerifyCodeService::TTL], $msg) : $this->fail($msg);
    }

    // -----------------------------------------------------------------
    // API 管理
    // -----------------------------------------------------------------

    public function apiManage(Request $request): View
    {
        $client = $this->requireClient();

        // 0 = not enabled, 1 = enabled, 2 = enabled but locked by an admin.
        $apiOpen = (int) $client->api_open;

        if (trim((string) $client->lock_reason) !== '' && (int) $client->api_lock_time > 0) {
            $apiOpen = 2;
        }

        $totalAllowed = Settings::on('allow_resource_api');

        return view('web.account.apimanage', array_merge($this->shared(), [
            'Title' => 'API管理',
            'TplName' => 'apimanage',
            'api_open' => $apiOpen,
            'api_open_total' => $totalAllowed ? 1 : 0,
            'need_bind_phone' => Settings::on('allow_resource_api_phone') && trim((string) $client->phonenumber) === '' ? 1 : 0,
            'need_certify' => Settings::on('allow_resource_api_realname') ? 1 : 0,
            'server_clause_url' => (string) Settings::get('server_clause_url', ''),
            'API' => [
                'client' => $this->apiClientPayload($client),
                'form_api' => $this->apiRequestSeries($client),
                'free_products' => $this->apiFreeProducts(),
            ],
            'lock_reason' => (string) $client->lock_reason,
        ]));
    }

    /**
     * POST /zjmf_finance_api/open — enable or disable API access.
     */
    public function apiOpen(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $open = (int) $request->input('api_open', 0) === 1 ? 1 : 0;

        if ($open === 1 && ! Settings::on('allow_resource_api')) {
            return $this->fail('系统未开启API对接功能');
        }

        if ($open === 1 && Settings::on('allow_resource_api_phone') && trim((string) $client->phonenumber) === '') {
            return $this->fail('使用API功能需要先绑定手机');
        }

        $client->api_open = $open;

        if ($open === 1) {
            if (trim((string) $client->api_password) === '') {
                $client->api_password = PasswordHasher::apiPassword(16);
            }

            $client->api_create_time = $client->api_create_time ?: time();
            $client->lock_reason = '';
            $client->api_lock_time = 0;
        }

        $client->save();

        return $this->ok([
            'api_open' => $open,
            'api_password' => (string) $client->api_password,
        ], $open === 1 ? 'API已开启' : 'API已关闭');
    }

    /**
     * POST /zjmf_finance_api/reset — issue a new API key.
     */
    public function apiReset(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        if ((int) $client->api_open !== 1) {
            return $this->fail('请先开启API');
        }

        $client->api_password = PasswordHasher::apiPassword(16);
        $client->save();

        return $this->ok(['api_password' => (string) $client->api_password], 'API密钥已重置');
    }

    /**
     * GET /get_api_pwd
     */
    public function getApiPwd(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        if ($this->secondVerifyRequired('get_api_pwd')) {
            $check = $this->verifySecondCode($request, $client, 'get_api_pwd');

            if ($check !== null) {
                return $check;
            }
        }

        if (trim((string) $client->api_password) === '') {
            $client->api_password = PasswordHasher::apiPassword(16);
            $client->save();
        }

        return $this->ok(['api' => (string) $client->api_password]);
    }

    /**
     * GET /auto_api_pwd
     */
    public function autoApiPwd(Request $request): JsonResponse
    {
        $this->requireClient();

        return $this->ok(['api' => PasswordHasher::apiPassword(16)]);
    }

    /**
     * POST /modify_api_pwd
     */
    public function modifyApiPwd(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $key = trim((string) $request->input('api', $request->input('api_password', '')));

        if (strlen($key) < 8 || strlen($key) > 50) {
            return $this->fail('API密钥长度需为 8-50 位', 406);
        }

        $client->api_password = $key;
        $client->save();

        return $this->ok(['api' => $key], 'API密钥已保存');
    }

    // -----------------------------------------------------------------
    // 消息中心
    // -----------------------------------------------------------------

    public function message(Request $request): View
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);
        $type = (int) $request->input('type', 0);

        $messages = SystemMessage::query()
            ->where('uid', $client->id)
            ->where(function ($query) {
                $query->whereNull('delete_time')->orWhere('delete_time', 0);
            })
            ->when($type > 0, fn ($query) => $query->where('type', $type))
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return view('web.account.message', array_merge($this->shared(), [
            'Title' => '消息中心',
            'TplName' => 'message',
            'type' => $type,
            'messages' => $this->messageRows($messages->items()),
            'unread_nav' => $this->unreadNav($client->id),
            'Total' => $messages->total(),
            'Limit' => $messages->perPage(),
            'Page' => $messages->currentPage(),
            'Pages' => $messages->lastPage(),
        ]));
    }

    /**
     * GET sys_messgage — the message table fragment.
     */
    public function messageList(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $messages = SystemMessage::query()
            ->where('uid', $client->id)
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->ok([
            'list' => $this->messageRows($messages->items()),
            'total' => $messages->total(),
            'page' => $messages->currentPage(),
            'limit' => $messages->perPage(),
            'total_page' => $messages->lastPage(),
        ]);
    }

    /**
     * GET sys_messgage_unread
     */
    public function unreadList(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        $messages = SystemMessage::query()
            ->where('uid', $client->id)
            ->where('read_time', 0)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $this->ok([
            'list' => $this->messageRows($messages->all()),
            'count' => $messages->count(),
            'nav' => $this->unreadNav($client->id),
        ]);
    }

    /**
     * GET /read_messgage — note the double `g`, kept for compatibility.
     */
    public function readMessage(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        $query = SystemMessage::query()->where('uid', $client->id)->where('read_time', 0);
        $ids = $this->intList($request->input('ids', []));

        // No ids means "mark everything read", matching the 全部已读 button.
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $count = $query->update(['read_time' => time()]);

        return $this->ok(['count' => $count], '已标记为已读');
    }

    /**
     * GET /delete_messgage
     */
    public function deleteMessage(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        $query = SystemMessage::query()->where('uid', $client->id);
        $ids = $this->intList($request->input('ids', []));

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $count = $query->update(['delete_time' => time()]);

        return $this->ok(['count' => $count], '删除成功');
    }

    // -----------------------------------------------------------------
    // Logs
    // -----------------------------------------------------------------

    public function systemLog(Request $request): View
    {
        return $this->logPage($request, 'systemlog', '系统日志', ['login']);
    }

    public function loginLog(Request $request): View
    {
        return $this->logPage($request, 'loginlog', '登录日志', ['login']);
    }

    public function apiLog(Request $request): View
    {
        return $this->logPage($request, 'apilog', 'API 日志', ['api']);
    }

    /**
     * GET /user_logs — action log rows.
     */
    public function userLogs(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $rows = DB::table('system_log')
            ->where('uid', $client->id)
            ->orderByRaw($this->orderBy($request, ['id', 'create_time'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return $this->ok([
            'list' => array_map(fn ($row) => $this->logRow($row, 'Y-m-d H:i'), $rows->items()),
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'limit' => $rows->perPage(),
            'total_page' => $rows->lastPage(),
        ]);
    }

    /**
     * * /user_logdcims — action log rows for one service.
     */
    public function userLogDcims(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $hostId = (int) $request->input('hid', 0);

        $rows = DB::table('activity_log_home')
            ->where('uid', $client->id)
            ->when($hostId > 0, fn ($query) => $query->where('activeid', $hostId))
            ->orderByDesc('create_time')
            ->limit(50)
            ->get();

        return $this->ok([
            'log_list' => $rows->map(fn ($row) => [
                'create_time' => (int) $row->create_time,
                'description' => (string) $row->description,
                'user' => (string) $row->user,
                'ipaddr' => (string) $row->ipaddr,
            ])->all(),
        ]);
    }

    // -----------------------------------------------------------------
    // Geo data
    // -----------------------------------------------------------------

    /**
     * GET /get_areas, /areas, /country
     */
    public function getAreas(Request $request): JsonResponse
    {
        $pid = (int) $request->input('pid', 0);

        $rows = DB::table('areas')
            ->when($pid > 0, fn ($query) => $query->where('pid', $pid), fn ($query) => $query->where('type', 1))
            ->orderBy('sort')
            ->get();

        return $this->ok($rows->map(fn ($row) => [
            'id' => (int) $row->area_id,
            'name' => (string) $row->name,
            'pid' => (int) $row->pid,
        ])->all());
    }

    /**
     * GET /country
     */
    public function country(Request $request): JsonResponse
    {
        return $this->ok($this->countries());
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function logPage(Request $request, string $template, string $title, array $types): View
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $query = DB::table('system_log')->where('uid', $client->id);

        if ($template === 'loginlog') {
            $query->where('log_type', 'login');
        } elseif ($template === 'apilog') {
            $query->where('log_type', 'api');
        } else {
            $query->where(function ($sub) {
                $sub->where('log_type', '!=', 'login')->orWhereNull('log_type');
            });
        }

        if (trim((string) $request->input('keywords', '')) !== '') {
            $keywords = '%' . trim((string) $request->input('keywords')) . '%';
            $query->where(function ($sub) use ($keywords) {
                $sub->where('description', 'like', $keywords)->orWhere('ip', 'like', $keywords);
            });
        }

        $rows = $query->orderByRaw($this->orderBy($request, ['id', 'create_time'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        $format = $template === 'loginlog' ? 'Y-m-d H:i:s' : 'Y-m-d H:i';

        return view('web.account.log', array_merge($this->shared(), [
            'Title' => $title,
            'TplName' => $template,
            'logTemplate' => $template,
            'logs' => array_map(fn ($row) => $this->logRow($row, $format), $rows->items()),
            'Total' => $rows->total(),
            'Limit' => $rows->perPage(),
            'Page' => $rows->currentPage(),
            'Pages' => $rows->lastPage(),
        ]));
    }

    protected function logRow(object $row, string $format): array
    {
        return [
            'id' => (int) $row->id,
            'create_time' => (int) $row->create_time,
            'description' => (string) $row->description,
            'user' => (string) $row->user,
            'ipaddr' => (string) $row->ip,
            'log_type' => (string) ($row->log_type ?? ''),
            'format_time' => date($format, (int) $row->create_time),
        ];
    }

    /**
     * @param  array<int, SystemMessage>  $messages
     */
    protected function messageRows(array $messages): array
    {
        return array_map(fn (SystemMessage $message) => [
            'id' => (int) $message->id,
            'title' => (string) $message->title,
            'content' => (string) $message->content,
            'type' => (int) $message->type,
            'type_text' => StatusMap::messageType((int) $message->type),
            'is_market' => (int) $message->is_market,
            'read_time' => (int) $message->read_time,
            'create_time' => (int) $message->create_time,
            'attachment' => $this->messageAttachments((string) $message->attachment),
        ], $messages);
    }

    /**
     * Message attachments are stored as a JSON list of {name, path}.
     */
    protected function messageAttachments(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $list = [];

        foreach ($decoded as $item) {
            if (is_array($item)) {
                $list[] = [
                    'name' => (string) ($item['name'] ?? ''),
                    'path' => (string) ($item['path'] ?? ''),
                ];
            }
        }

        return $list;
    }

    /**
     * Per-type unread counts for the message centre tabs.
     */
    protected function unreadNav(int $clientId): array
    {
        $counts = SystemMessage::query()
            ->where('uid', $clientId)
            ->where('read_time', 0)
            ->where(function ($query) {
                $query->whereNull('delete_time')->orWhere('delete_time', 0);
            })
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $rows = [[
            'id' => 0,
            'name' => 'all',
            'unread_num' => (int) $counts->sum(),
        ]];

        foreach ([1, 2, 3, 4] as $type) {
            $rows[] = [
                'id' => $type,
                'name' => 'message_type_' . $type,
                'unread_num' => (int) ($counts[$type] ?? 0),
                'title' => StatusMap::messageType($type),
            ];
        }

        return $rows;
    }

    protected function countries(): array
    {
        return DB::table('areas')
            ->where('type', 1)
            ->where('show', 1)
            ->orderBy('sort')
            ->get(['area_id', 'name'])
            ->map(fn ($row) => ['id' => (int) $row->area_id, 'name' => (string) $row->name])
            ->all();
    }

    /**
     * `custom[<id>]` input from the details page.
     */
    protected function saveClientCustomFields(Client $client, Request $request): void
    {
        foreach ((array) $request->input('custom', []) as $fieldId => $value) {
            $field = CustomField::query()->find((int) $fieldId);

            if ($field === null) {
                continue;
            }

            CustomFieldValue::query()->updateOrCreate(
                ['fieldid' => (int) $fieldId, 'relid' => $client->id],
                [
                    'value' => is_array($value) ? json_encode(array_values($value), JSON_UNESCAPED_UNICODE) : (string) $value,
                    'create_time' => time(),
                    'update_time' => time(),
                ]
            );
        }
    }

    /**
     * Profile completeness score shown on the security page.
     *
     * @return array{0:string, 1:int}
     */
    protected function securityCompletion(Client $client): array
    {
        $checks = [
            trim((string) $client->password) !== '',
            trim((string) $client->email) !== '',
            trim((string) $client->phonenumber) !== '',
            (int) $client->second_verify === 1,
            trim((string) $client->username) !== '',
        ];

        $done = count(array_filter($checks));
        $percent = (int) round(($done / count($checks)) * 100);

        return ['安全等级', $percent];
    }

    /**
     * @return array<int, array{name:string, name_zh:string, account:string}>
     */
    protected function secondVerifyTypeList(Client $client): array
    {
        return array_values(array_filter([
            trim((string) $client->email) !== ''
                ? ['name' => 'email', 'name_zh' => '邮箱验证', 'account' => (string) $client->email]
                : null,
            trim((string) $client->phonenumber) !== ''
                ? ['name' => 'phone', 'name_zh' => '手机验证', 'account' => (string) $client->phonenumber]
                : null,
        ]));
    }

    protected function accountForType(Client $client, string $type): string
    {
        return match ($type) {
            'email' => (string) $client->email,
            'phone' => (string) $client->phonenumber,
            default => (string) ($client->email ?: $client->phonenumber),
        };
    }

    protected function allowSecondVerify(): bool
    {
        // The administrator may enable the feature globally, or per client at
        // registration time; either switch turns the cards on.
        return (bool) (Settings::on('second_verify_home') || Settings::on('second_verify'));
    }

    protected function secondVerifyRequired(string $action): bool
    {
        return $this->allowSecondVerify() && Settings::needsSecondVerify($action);
    }

    /**
     * Consume a posted `code` for a sensitive action.
     */
    protected function verifySecondCode(Request $request, Client $client, string $action): ?JsonResponse
    {
        $code = trim((string) $request->input('code', ''));

        if ($code === '') {
            return $this->fail('请输入二次验证码', 1002);
        }

        $type = (string) $request->input('type', $request->input('code_type', ''));
        $account = $this->accountForType($client, $type);

        if ($account === '') {
            return $this->fail('该账号未绑定可用的验证方式');
        }

        $consumed = (new VerifyCodeService())->consume('second_verify:' . $action, $account, $code)
            // The security centre issues codes without an action suffix.
            || (new VerifyCodeService())->consume('second_verify:', $account, $code);

        return $consumed ? null : $this->fail('二次验证码错误');
    }

    /**
     * Paid API statistics for the 管理 panel.
     */
    protected function apiClientPayload(Client $client): array
    {
        $hosts = DB::table('host')->where('uid', $client->id);

        $hostCount = (clone $hosts)->count();
        $activeCount = (clone $hosts)->where('domainstatus', 'Active')->count();

        $agentCount = DB::table('api_user_product')->where('uid', $client->id)->count();
        $apiCount = DB::table('api_resource_log')->where('uid', $client->id)->count();

        $todayStart = strtotime(date('Y-m-d 00:00:00')) ?: time();
        $today = DB::table('api_resource_log')->where('uid', $client->id)->where('create_time', '>=', $todayStart)->count();
        $yesterday = DB::table('api_resource_log')
            ->where('uid', $client->id)
            ->whereBetween('create_time', [$todayStart - 86400, $todayStart])
            ->count();

        return [
            'api_password' => (string) $client->api_password,
            'api_create_time' => (int) $client->api_create_time,
            'active_count' => $activeCount,
            'host_count' => $hostCount,
            'agent_count' => $agentCount,
            'api_count' => $today,
            'up' => $today >= $yesterday,
            'ratio' => $yesterday > 0 ? round((($today - $yesterday) / $yesterday) * 100, 2) : ($today > 0 ? 100 : 0),
            'total_api_count' => $apiCount,
        ];
    }

    /**
     * Seven-day API request series for the chart.
     *
     * @return array<int, int>
     */
    protected function apiRequestSeries(Client $client): array
    {
        $series = [];

        for ($i = 6; $i >= 0; $i--) {
            $start = strtotime(date('Y-m-d 00:00:00', time() - ($i * 86400))) ?: time();

            $series[] = DB::table('api_resource_log')
                ->where('uid', $client->id)
                ->where('create_time', '>=', $start)
                ->where('create_time', '<', $start + 86400)
                ->count();
        }

        return $series;
    }

    /**
     * Products exempt from API request metering.
     */
    protected function apiFreeProducts(): array
    {
        return DB::table('api_user_product')
            ->join('products', 'products.id', '=', 'api_user_product.pid')
            ->groupBy('products.id', 'products.name', 'api_user_product.ontrial', 'api_user_product.qty')
            ->get(['products.id', 'products.name', 'api_user_product.ontrial', 'api_user_product.qty'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'ontrial' => (int) $row->ontrial,
                'qty' => (int) $row->qty,
            ])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function intList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return array_values(array_filter(array_map('intval', (array) $raw), fn ($id) => $id > 0));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use App\Services\InvoiceService;
use App\Support\ApiResponse;
use App\Support\JwtService;
use App\Support\PasswordHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 客户管理 — the client list, profile, groups, levels, custom fields and the
 * real-name (实名认证) review queue.
 */
class ClientController extends AdminController
{
    /**
     * `POST client_list` — the main customer table.
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        [$orderBy, $sort] = $this->sortParams($request, [
            'id', 'username', 'companyname', 'email', 'phonenumber', 'credit',
            'create_time', 'lastlogin', 'status', 'groupid', 'amount_in', 'amount_out',
        ]);

        $query = Client::query();

        $this->applyClientFilters($query, $request);

        if ($orderBy === 'amount_in' || $orderBy === 'amount_out') {
            // 收入/支出 are aggregates over `shd_accounts`; sorting on them is
            // done in PHP below so the list stays a single query.
            $orderBy = 'id';
        }

        $total = (clone $query)->count();

        if ($request->boolean('sum')) {
            $total = (clone $query)->count();
        }

        $rows = $query->orderBy($orderBy, $sort)
            ->forPage($page, $limit)
            ->get();

        $list = $this->decorateClients($rows);

        if ($request->input('order') === 'amount_in' || $request->input('orderby') === 'amount_in') {
            $list = $this->sortByAggregate($list, 'amount_in', $sort);
        }

        return $this->paginated($list, $total, $page, $limit, [
            'sum' => $total,
            'search' => $this->searchOptions(),
            'seachData' => $this->searchData(),
            'allow_resource_api' => (int) (SettingService::value('allow_resource_api', '0') ?? 0),
            'api_status' => ['未开启', '已开启'],
            'level_search' => DB::table('clients_level_rule')->get(['id', 'level_name'])->toArray(),
        ]);
    }

    /**
     * `POST searchfornamelist` — the advanced-search endpoint shared by the
     * customer, service, order and ticket tables.
     */
    public function searchForNameList(Request $request)
    {
        $kind = (string) $request->input('type', 'client');

        return match ($kind) {
            'host', 'hosts' => app(ServiceController::class)->searchForNameList($request),
            'order', 'orders' => app(OrderController::class)->searchForNameList($request),
            'ticket', 'tickets' => app(TicketController::class)->searchForNameList($request),
            default => $this->list($request),
        };
    }

    /**
     * `GET searchlist?value=` / `GET namelist` — autocomplete for the
     * 客户 inputs on the order and ticket forms.
     */
    public function searchList(Request $request)
    {
        $value = trim((string) $request->input('value', $request->input('keywords', '')));

        $rows = Client::query()
            ->when($value !== '', function ($q) use ($value) {
                $q->where(function ($w) use ($value) {
                    $w->where('username', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%")
                        ->orWhere('phonenumber', 'like', "%{$value}%")
                        ->orWhere('companyname', 'like', "%{$value}%")
                        ->orWhere('id', is_numeric($value) ? (int) $value : -1);
                });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'username', 'email', 'phonenumber', 'companyname']);

        return $this->ok($rows->map(fn (Client $c) => [
            'id' => (int) $c->id,
            'username' => $c->username,
            'email' => $c->email,
            'phonenumber' => $c->phonenumber,
            'companyname' => $c->companyname,
            'value' => $c->username ?: $c->email,
        ])->all());
    }

    /**
     * `GET get_user {id}` — the customer-detail shell payload.
     */
    public function getUser(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->fail('ID错误');
        }

        $client = Client::query()->find($id);

        if ($client === null) {
            return $this->fail('客户不存在');
        }

        return $this->ok([
            'client' => $this->clientDetail($client),
            'customfields' => $this->customFieldValues($client),
            'plugins_oauth' => [],
        ]);
    }

    /**
     * `GET getClient` — legacy alias returning a plain list for the pickers.
     */
    public function getClientList(Request $request)
    {
        return $this->ok(Client::query()
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'username', 'email', 'phonenumber', 'companyname', 'status'])
            ->toArray());
    }

    /**
     * `GET summary?client_id=` — the 客户摘要 cards.
     */
    public function summary(Request $request)
    {
        $uid = (int) $request->input('client_id', $request->input('uid', 0));

        if ($uid <= 0) {
            return $this->notFound('用户不存在');
        }

        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $accounts = DB::table('accounts')->where('uid', $uid)->whereNull('delete_time');
        $amountIn = (float) (clone $accounts)->sum('amount_in');
        $amountOut = (float) (clone $accounts)->sum('amount_out');

        $hosts = Host::query()->where('uid', $uid);

        return $this->ok([
            'summary' => $this->clientDetail($client),
            'customfields' => $this->customFieldValues($client),
            'plugins_oauth' => [],
            'accounts_count' => (clone $accounts)->count(),
            'sms_countryOptions' => DB::table('sms_country')->get()->toArray(),
            'cwBackBg' => [
                'totalIn' => $this->money($amountIn),
                'totalOut' => $this->money($amountOut),
                'noPay' => $this->money((float) Invoice::query()->where('uid', $uid)->where('status', 'Unpaid')->sum('total')),
                'upcomingPro' => (clone $hosts)->whereIn('domainstatus', Host::LIVE_STATUSES)->count(),
                'alredyPro' => (clone $hosts)->count(),
                'balance' => $this->money((float) $client->credit),
                'Credit' => $this->money((float) $client->credit_limit),
            ],
            'sale_idOptions' => AdminMeta::admins(),
            'client_groupsOptions' => AdminMeta::clientGroups(),
            'client_statusOptions' => AdminMeta::CLIENT_STATUS,
            'sale_id' => (int) $client->sale_id,
            'groupid' => (int) $client->groupid,
            'status' => (int) $client->status,
            'certificationInfo' => $this->certificationInfo($client),
        ]);
    }

    /**
     * `GET profile/<id>` — the read-only profile payload.
     */
    public function profile(Request $request, $id)
    {
        $client = Client::query()->find((int) $id);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        return $this->ok($this->clientDetail($client));
    }

    /**
     * `GET profile/getclients/<id>` — the editable profile form.
     */
    public function profileForm(Request $request, $id)
    {
        $client = Client::query()->find((int) $id);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        return $this->ok([
            'client' => $client->toArray(),
            'country' => DB::table('areas')->where('pid', 0)->get(['area_id', 'name', 'key'])->toArray(),
            'language' => AdminMeta::LANGUAGES,
            'gateway' => AdminMeta::gateways(),
            'client_groups' => AdminMeta::clientGroups(),
            'sale' => AdminMeta::admins(),
            'sexOptions' => AdminMeta::SEX_OPTIONS,
            'sms_country' => DB::table('sms_country')->get()->toArray(),
            'customfields' => $this->customFieldValues($client),
        ]);
    }

    /**
     * `POST profile_post` — save the profile / summary edits.
     */
    public function profilePost(Request $request)
    {
        $uid = (int) $request->input('id', $request->input('uid', 0));

        if ($uid <= 0) {
            return $this->validationFail('客户ID不能为空');
        }

        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $fields = [
            'username', 'sex', 'companyname', 'country', 'province', 'city', 'region',
            'address1', 'postcode', 'phone_code', 'phonenumber', 'email', 'qq',
            'defaultgateway', 'language', 'sale_id', 'groupid', 'status', 'notes',
            'marketing_emails_opt_in', 'know_us', 'initiative_renew', 'currency',
            'taxexempt', 'emailoptout', 'is_login_sms_reminder',
        ];

        $data = [];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== '') {
            $duplicate = Client::query()
                ->where('email', $data['email'])
                ->whereKeyNot($uid)
                ->exists();

            if ($duplicate) {
                return $this->validationFail('邮箱已被使用');
            }
        }

        if (! empty($request->input('password'))) {
            $data['password'] = PasswordHasher::client(
                PasswordHasher::acceptedPlain((string) $request->input('password'))
            );
        }

        $data['update_time'] = time();

        $client->fill($data)->save();

        // Custom fields are posted as a nested `custom` object, keyed by field
        // id, and stored one row per field in `shd_customfieldsvalues`.
        $custom = $request->input('custom', []);

        if (is_array($custom)) {
            foreach ($custom as $fieldId => $value) {
                $this->saveCustomFieldValue($uid, (int) $fieldId, is_array($value) ? implode(',', $value) : (string) $value);
            }
        }

        $this->addTrackRecord($uid, '修改客户资料');
        $this->log('修改客户资料：'.$client->username, $uid);

        return $this->ok(['id' => $uid], '保存成功');
    }

    /**
     * `GET create_client` — the 新增客户 form metadata.
     */
    public function createPage(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'currencies' => AdminMeta::currencies(),
            'language' => AdminMeta::LANGUAGES,
            'sale' => AdminMeta::admins(),
            'client_status' => AdminMeta::CLIENT_STATUS,
            'country' => DB::table('areas')->where('pid', 0)->get(['area_id', 'name', 'key'])->toArray(),
            'customs' => DB::table('customfields')->where('type', 'client')->orderBy('sortorder')->get()->toArray(),
            'gateway' => AdminMeta::gateways(),
            'client_groups' => AdminMeta::clientGroups(),
            'sms_country' => DB::table('sms_country')->get()->toArray(),
            'sexOptions' => AdminMeta::SEX_OPTIONS,
        ]);
    }

    /**
     * `POST create_client_post`
     */
    public function createClient(Request $request)
    {
        $username = trim((string) $request->input('username', ''));
        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phonenumber', ''));

        if ($username === '') {
            return $this->validationFail('姓名不能为空');
        }

        if ($email === '' && $phone === '') {
            return $this->validationFail('邮箱和手机号至少填写一个');
        }

        if ($email !== '' && Client::query()->where('email', $email)->exists()) {
            return $this->validationFail('邮箱已存在');
        }

        $password = (string) $request->input('password', '');
        $password = $password !== '' ? PasswordHasher::acceptedPlain($password) : Str::random(10);

        $now = time();

        $client = Client::query()->create([
            'username' => $username,
            'sex' => (int) $request->input('sex', 0),
            'companyname' => (string) $request->input('companyname', ''),
            'country' => (string) $request->input('country', '中国'),
            'province' => (string) $request->input('province', ''),
            'address1' => (string) $request->input('address1', ''),
            'postcode' => (string) $request->input('postcode', ''),
            'know_us' => (string) $request->input('know_us', ''),
            'phone_code' => (int) $request->input('phone_code', 86),
            'phonenumber' => $phone,
            'email' => $email,
            'qq' => (string) $request->input('qq', ''),
            'password' => PasswordHasher::client($password),
            'defaultgateway' => (string) $request->input('defaultgateway', ''),
            'language' => (string) $request->input('language', 'zh-cn'),
            'sale_id' => (int) $request->input('sale_id', 0),
            'groupid' => (int) $request->input('groupid', 0),
            'status' => (int) $request->input('status', Client::STATUS_ACTIVE),
            'notes' => (string) $request->input('notes', ''),
            'marketing_emails_opt_in' => (int) $request->input('marketing_emails_opt_in', 1),
            'initiative_renew' => (int) $request->input('initiative_renew', 0),
            'credit' => 0,
            'currency' => (int) ($request->input('currency') ?: 1),
            'api_password' => PasswordHasher::apiPassword(),
            'create_time' => $now,
            'update_time' => $now,
            'uuid' => (string) Str::uuid(),
        ]);

        $custom = $request->input('custom', []);

        if (is_array($custom)) {
            foreach ($custom as $fieldId => $value) {
                $this->saveCustomFieldValue((int) $client->id, (int) $fieldId, is_array($value) ? implode(',', $value) : (string) $value);
            }
        }

        $this->addTrackRecord((int) $client->id, '后台创建客户');
        $this->log('创建客户：'.$username, (int) $client->id);

        return $this->ok(['id' => (int) $client->id], '添加成功');
    }

    /**
     * `GET delete_client/<uid>` — the original deletes outright (the schema has
     * no soft-delete column on `shd_clients`), but refuses while services or
     * unpaid invoices exist.
     */
    public function deleteClient(Request $request, $uid)
    {
        $client = Client::query()->find((int) $uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $liveHosts = Host::query()
            ->where('uid', $client->id)
            ->whereIn('domainstatus', Host::LIVE_STATUSES)
            ->count();

        if ($liveHosts > 0) {
            return $this->fail('该客户下还有产品/服务，不能删除');
        }

        $unpaid = Invoice::query()->where('uid', $client->id)->where('status', 'Unpaid')->count();

        if ($unpaid > 0) {
            return $this->fail('该客户下还有未支付账单，不能删除');
        }

        $name = $client->username;
        Client::query()->whereKey($client->id)->delete();

        $this->log('删除客户：'.$name, (int) $uid);

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET close_client/<uid> {status}` — enable / disable an account.
     */
    public function closeClient(Request $request, $uid)
    {
        $client = Client::query()->find((int) $uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        // The 客户摘要 card toggles with a bare GET, so flip the status when no
        // explicit target is supplied.
        $status = $request->has('status')
            ? (int) $request->input('status')
            : ((int) $client->status === Client::STATUS_ACTIVE ? Client::STATUS_INACTIVE : Client::STATUS_ACTIVE);

        if (! in_array($status, [Client::STATUS_INACTIVE, Client::STATUS_ACTIVE, Client::STATUS_CLOSED], true)) {
            return $this->validationFail('状态值错误');
        }

        $client->status = $status;
        $client->update_time = time();
        $client->save();

        $label = AdminMeta::clientStatusLabel($status);
        $this->addTrackRecord((int) $client->id, '账号状态变更为'.$label);
        $this->log('客户状态变更：'.$client->username.' → '.$label, (int) $uid);

        return $this->ok(['status' => $status], '操作成功');
    }

    /**
     * `GET login_by_user/<uid>` — issue a client-area session / JWT so the
     * administrator can act "as" the customer (客服代登录).
     */
    public function loginByUser(Request $request, $uid)
    {
        $client = Client::query()->find((int) $uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        if (! $client->isActive()) {
            return $this->fail('该客户已被禁用，无法登录');
        }

        $jwt = (new JwtService())->issue($client, (string) $request->ip());

        // Also sign the client guard in so the server-rendered client area
        // opens without a second login. `App\Models\Client` gains the
        // Authenticatable contract from the client-area workstream; guard the
        // call so 代登录 still returns a usable JWT if it is not in place yet.
        if ($client instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            Auth::guard('client')->login($client);
        }

        $client->lastlogin = time();
        $client->lastloginip = (string) $request->ip();
        $client->save();

        $this->log('代登录客户：'.$client->username, (int) $uid);

        return $this->ok([
            'id' => (int) $client->id,
            'token' => $jwt,
            'url' => url('/clientarea'),
        ], '登录成功');
    }

    /**
     * `GET forward_client` — legacy alias of 代登录 used by the customer list.
     */
    public function forwardClient(Request $request)
    {
        return $this->loginByUser($request, (int) $request->input('uid', $request->input('id', 0)));
    }

    /**
     * `GET user_invoice` — a client's invoices (客户详情 → 账单 tab).
     */
    public function userInvoices(Request $request)
    {
        $uid = (int) $request->input('uid', $request->input('client_id', 0));

        if ($uid <= 0) {
            return $this->validationFail('用户不能为空');
        }

        [$page, $limit] = $this->pageParams($request);

        $query = Invoice::query()->where('uid', $uid);

        if ($status = $request->input('status')) {
            $status === 'ALL' ?: $query->where('status', $status);
        }

        if ($request->filled('invoice_num')) {
            $query->where('invoice_num', 'like', '%'.$request->input('invoice_num').'%');
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->paginated(
            $rows->map(fn (Invoice $i) => $this->invoiceRow($i))->all(),
            $total,
            $page,
            $limit,
            ['invoice_status' => AdminMeta::INVOICE_STATUS],
        );
    }

    /**
     * `GET user_productinvoice` — invoices related to one service.
     */
    public function userProductInvoices(Request $request)
    {
        $hostId = (int) $request->input('hostid', $request->input('id', 0));
        $uid = (int) $request->input('uid', 0);

        if ($hostId <= 0 && $uid <= 0) {
            return $this->validationFail('参数错误');
        }

        [$page, $limit] = $this->pageParams($request);

        $itemInvoiceIds = InvoiceItem::query()
            ->when($hostId > 0, fn ($q) => $q->where('rel_id', $hostId))
            ->when($uid > 0, fn ($q) => $q->where('uid', $uid))
            ->pluck('invoice_id')
            ->unique()
            ->all();

        $query = Invoice::query()->whereIn('id', $itemInvoiceIds ?: [-1]);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->paginated($rows->map(fn (Invoice $i) => $this->invoiceRow($i))->all(), $total, $page, $limit);
    }

    /**
     * `GET user_productaccounts` — the 交易记录 tab of a service.
     */
    public function userProductAccounts(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $hostId = (int) $request->input('hostid', 0);

        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('accounts')->whereNull('delete_time');

        if ($uid > 0) {
            $query->where('uid', $uid);
        }

        if ($hostId > 0) {
            $query->where('description', 'like', "%#{$hostId}#%");
        }

        if ($request->filled('start_time')) {
            $query->where('create_time', '>=', (int) $this->timestamp($request->input('start_time')));
        }

        if ($request->filled('end_time')) {
            $query->where('create_time', '<=', (int) $this->timestamp($request->input('end_time')));
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get()->map(fn ($a) => (array) $a)->all();

        return $this->paginated($rows, $total, $page, $limit);
    }

    /**
     * `POST add_user_invoice` — create an ad-hoc invoice for a client.
     */
    public function addUserInvoice(Request $request)
    {
        $uid = (int) $request->input('uid', $request->input('id', 0));

        if ($uid <= 0) {
            return $this->validationFail('客户ID不能为空');
        }

        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $amount = $this->money((float) $request->input('amount', 0));
        $description = (string) ($request->input('description') ?: '后台创建账单');

        [$items, $amount] = $this->invoiceItemsFromRequest($request, $description, $amount);

        if ($amount <= 0) {
            return $this->validationFail('金额必须大于0');
        }

        $dueTime = $this->timestamp($request->input('due_time')) ?? time() + 7 * 86400;

        $invoice = app(InvoiceService::class)->create(
            $client,
            $items,
            $dueTime,
            (string) $request->input('type', 'hosting'),
            [
                'payment' => (string) $request->input('payment', ''),
                'notes' => (string) $request->input('notes', ''),
            ],
        );

        $this->log('为客户'.$client->username.'创建账单 #'.$invoice->id, $uid);

        return $this->ok([
            'id' => (int) $invoice->id,
            'invoice_num' => $invoice->invoice_num,
        ], '创建成功');
    }

    /**
     * `POST add_recharge_invoice/<uid>` — the 创建充值账单 dialog.
     */
    public function addRechargeInvoice(Request $request, $uid)
    {
        $client = Client::query()->find((int) $uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $amount = $this->money((float) $request->input('amount', $request->input('rechargeAmount', 0)));

        if ($amount <= 0) {
            return $this->validationFail('充值金额必须大于0');
        }

        $invoice = app(InvoiceService::class)->create(
            $client,
            [[
                'type' => 'recharge',
                'rel_id' => 0,
                'description' => '账户充值',
                'amount' => $amount,
            ]],
            $this->timestamp($request->input('due_time')) ?? time() + 7 * 86400,
            'recharge',
            ['payment' => (string) $request->input('payment', '')],
        );

        $this->log('为客户'.$client->username.'创建充值账单 #'.$invoice->id, (int) $uid);

        return $this->ok(['id' => (int) $invoice->id], '创建成功');
    }

    /**
     * `POST post_client_notes` — the 管理员备注 textarea on the summary card.
     */
    public function postClientNotes(Request $request)
    {
        $uid = (int) $request->input('id', $request->input('uid', 0));

        if ($uid <= 0) {
            return $this->validationFail('客户ID不能为空');
        }

        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $client->notes = (string) $request->input('notes', '');
        $client->update_time = time();
        $client->save();

        $this->log('修改客户备注：'.$client->username, $uid);

        return $this->ok(null, '保存成功');
    }

    /**
     * `GET get_client_notes`
     */
    public function getClientNotes(Request $request)
    {
        $uid = (int) $request->input('id', $request->input('uid', 0));
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        return $this->ok(['notes' => $client->notes]);
    }

    /**
     * `POST add_record_log` — 跟进记录.
     */
    public function addRecordLog(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $des = trim((string) $request->input('des', $request->input('description', '')));

        if ($uid <= 0 || $des === '') {
            return $this->validationFail('参数错误');
        }

        DB::table('clients_track_record')->insert([
            'uid' => $uid,
            'des' => $des,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return $this->ok(null, '添加成功');
    }

    /**
     * `POST add_remark_log` — a follow-up note attributed to an administrator.
     */
    public function addRemarkLog(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $des = trim((string) $request->input('des', ''));

        if ($uid <= 0 || $des === '') {
            return $this->validationFail('参数错误');
        }

        DB::table('clients_track_remark')->insert([
            'track_id' => $uid,
            'remark' => $this->adminName().'：'.$des,
            'create_time' => time(),
        ]);

        return $this->ok(null, '添加成功');
    }

    /**
     * `GET track_record` — 跟进记录 list.
     */
    public function trackRecord(Request $request)
    {
        $uid = (int) $request->input('uid', $request->input('id', 0));
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('clients_track_record')->when($uid > 0, fn ($q) => $q->where('uid', $uid));

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get()->map(fn ($r) => (array) $r)->all();

        return $this->paginated($rows, $total, $page, $limit);
    }

    /**
     * `GET getTrackRecord` / `GET clientTrackStatus` — follow-up status flag.
     */
    public function getTrackRecord(Request $request)
    {
        return $this->trackRecord($request);
    }

    public function clientTrackStatus(Request $request)
    {
        $uid = (int) $request->input('uid', $request->input('id', 0));
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        return $this->ok(['track_status' => (int) $client->track_status]);
    }

    /**
     * `POST client/<uid>/track_status`
     */
    public function clientTrackStatusPost(Request $request, $uid)
    {
        $client = Client::query()->find((int) $uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $client->track_status = (int) $request->input('track_status', $request->input('status', 0));
        $client->update_time = time();
        $client->save();

        return $this->ok(null, '操作成功');
    }

    /**
     * `POST user_remark` / `GET user_remark`
     */
    public function userRemark(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $remark = (string) $request->input('remark', '');

        if ($uid <= 0) {
            return $this->validationFail('客户ID不能为空');
        }

        DB::table('clients_track_remark')->insert([
            'track_id' => $uid,
            'remark' => $this->adminName().'：'.$remark,
            'create_time' => time(),
        ]);

        return $this->ok(null, '保存成功');
    }

    public function getUserRemark(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        return $this->ok(DB::table('clients_track_remark')
            ->when($uid > 0, fn ($q) => $q->where('track_id', $uid))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->toArray());
    }

    /**
     * `GET hostbyuid {uid}` — a client's services for the ticket/product tabs.
     */
    public function hostByUid(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        if ($uid <= 0) {
            return $this->validationFail('客户编号未找到');
        }

        $hosts = Host::query()
            ->where('uid', $uid)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Host $h) => $this->hostRow($h))
            ->all();

        return $this->okFlat('请求成功', [
            'hosts' => $hosts,
            'total' => count($hosts),
        ]);
    }

    /**
     * `GET client_ticket {uid}` — a client's tickets.
     */
    public function clientTickets(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        if ($uid <= 0) {
            return $this->validationFail('用户不能为空');
        }

        [$page, $limit] = $this->pageParams($request);

        $query = Ticket::query()->where('uid', $uid);
        $total = (clone $query)->count();

        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->paginated(
            $rows->map(fn (Ticket $t) => AdminMeta::ticketRow($t))->all(),
            $total,
            $page,
            $limit,
        );
    }

    /**
     * `GET clients_services {uid, hostselect}` — the 产品/服务 tab of the
     * customer detail shell.
     */
    public function clientsServices(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        if ($uid <= 0) {
            return $this->fail('客户编号未找到');
        }

        [$page, $limit] = $this->pageParams($request);

        $hostselect = (string) $request->input('hostselect', '');

        $query = Host::query()->where('uid', $uid);

        if ($hostselect === 'host') {
            $query->whereIn('domainstatus', Host::LIVE_STATUSES);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->paginated(
            $rows->map(fn (Host $h) => $this->hostRow($h))->all(),
            $total,
            $page,
            $limit,
            ['client' => (array) Client::query()->find($uid)?->toArray()],
        );
    }

    /**
     * `GET get_combine_invoices {uid}` — the invoice-combining picker.
     */
    public function getCombineInvoices(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        if ($uid <= 0) {
            return $this->fail('用户不存在');
        }

        $rows = Invoice::query()
            ->where('uid', $uid)
            ->whereIn('status', ['Unpaid', 'Overdue'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return $this->ok($rows->map(fn (Invoice $i) => $this->invoiceRow($i))->all());
    }

    /**
     * `POST combine_invoices` — 合并账单: fold several unpaid invoices into the
     * newest one and cancel the rest.
     */
    public function combineInvoices(Request $request)
    {
        $ids = $request->input('ids', $request->input('id', []));
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [(int) $ids];

        if (count($ids) < 2) {
            return $this->validationFail('请至少选择两个账单');
        }

        $invoices = Invoice::query()->whereIn('id', $ids)->where('status', 'Unpaid')->get();

        if ($invoices->count() < 2) {
            return $this->validationFail('账单不存在或已支付');
        }

        $target = $invoices->sortByDesc('id')->first();

        $others = $invoices->reject(fn (Invoice $i) => $i->id === $target->id);

        DB::transaction(function () use ($target, $others) {
            foreach ($others as $invoice) {
                InvoiceItem::query()->where('invoice_id', $invoice->id)->update([
                    'invoice_id' => $target->id,
                ]);

                $invoice->status = 'Cancelled';
                $invoice->update_time = time();
                $invoice->save();
            }

            $subtotal = (float) InvoiceItem::query()
                ->where('invoice_id', $target->id)
                ->whereNull('delete_time')
                ->sum('amount');

            $target->subtotal = $this->money($subtotal);
            $target->total = $this->money($subtotal + (float) $target->tax + (float) $target->tax2);
            $target->update_time = time();
            $target->save();
        });

        $this->log('合并账单：'.$ids[0].' → '.$target->id, (int) $target->uid);

        return $this->ok(['id' => (int) $target->id], '合并成功');
    }

    // -----------------------------------------------------------------
    // 客户分组
    // -----------------------------------------------------------------

    /**
     * `GET client_group` — 客户分组 list.
     */
    public function groupList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = ClientGroup::query();

        if ($keywords = trim((string) $request->input('keywords', ''))) {
            $query->where('group_name', 'like', "%{$keywords}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('id')->forPage($page, $limit)->get();

        $list = $rows->map(function (ClientGroup $g) {
            $row = $g->toArray();
            $row['client_count'] = Client::query()->where('groupid', $g->id)->count();

            return $row;
        })->all();

        // 客户分组 page also renders the product-group and discount tabs.
        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
            'page' => $page,
            'limit' => $limit,
            'product_groups' => DB::table('product_groups')->orderBy('order')->get()->toArray(),
            'discounts' => DB::table('user_product_groups')->get()->toArray(),
        ]);
    }

    /**
     * `POST client_group/create` — create or update a client group.
     */
    public function groupSave(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $name = trim((string) $request->input('group_name', $request->input('name', '')));

        if ($name === '') {
            return $this->validationFail('客户组名称不能为空');
        }

        $data = [
            'group_name' => $name,
            'group_colour' => (string) $request->input('group_colour', $request->input('color', '')),
            'discount_percent' => (int) $request->input('discount_percent', 0),
            'susptermexempt' => (int) $request->input('susptermexempt', 0),
            'separateinvoices' => (int) $request->input('separateinvoices', 0),
        ];

        if ($id > 0) {
            $group = ClientGroup::query()->find($id);

            if ($group === null) {
                return $this->notFound('客户分组不存在');
            }

            $group->fill($data)->save();
            $this->log('编辑客户分组：'.$name, $id);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $group = ClientGroup::query()->create($data);
        $this->log('添加客户分组：'.$name, (int) $group->id);

        return $this->ok(['id' => (int) $group->id], '添加成功');
    }

    public function groupDetail(Request $request, $id)
    {
        $group = ClientGroup::query()->find((int) $id);

        return $group === null
            ? $this->notFound('客户分组不存在')
            : $this->ok($group->toArray());
    }

    /**
     * `DELETE client_group/<id>` — refuse while the group still has members.
     */
    public function groupDelete(Request $request, $id)
    {
        $group = ClientGroup::query()->find((int) $id);

        if ($group === null) {
            return $this->notFound('客户分组不存在');
        }

        if (Client::query()->where('groupid', $group->id)->exists()) {
            return $this->fail('该分组下还有客户，不能删除');
        }

        $name = $group->group_name;
        $group->delete();

        $this->log('删除客户分组：'.$name);

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 客户等级
    // -----------------------------------------------------------------

    /**
     * `GET clients_level_rule`
     */
    public function levelRuleList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('clients_level_rule');
        $total = (clone $query)->count();

        $rows = $query->orderBy('id')->forPage($page, $limit)->get()->map(fn ($r) => (array) $r)->all();

        return $this->paginated($rows, $total, $page, $limit, [
            'data' => $rows,
            'count' => $total,
        ]);
    }

    /**
     * `POST clients_level_rule` / `PUT clients_level_rule/<id>`
     */
    public function levelRuleSave(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $name = trim((string) $request->input('level_name', ''));

        if ($name === '') {
            return $this->validationFail('等级名称不能为空');
        }

        $data = [
            'level_name' => $name,
            'expense' => $this->rangeValue($request, 'expense'),
            'buy_num' => $this->rangeValue($request, 'buy_num'),
            'login_times' => $this->rangeValue($request, 'login_times'),
            'last_login_times' => $this->rangeValue($request, 'last_login_times'),
            'renew_times' => $this->rangeValue($request, 'renew_times'),
            'last_renew_times' => $this->rangeValue($request, 'last_renew_times'),
            'update_time' => time(),
        ];

        if ($id > 0) {
            DB::table('clients_level_rule')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();
        $newId = DB::table('clients_level_rule')->insertGetId($data);

        return $this->ok(['id' => $newId], '添加成功');
    }

    public function levelRuleDetail(Request $request, $id)
    {
        $row = DB::table('clients_level_rule')->where('id', (int) $id)->first();

        return $row === null ? $this->notFound('等级不存在') : $this->ok((array) $row);
    }

    public function levelRuleDelete(Request $request, $id)
    {
        DB::table('clients_level_rule')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 自定义客户字段
    // -----------------------------------------------------------------

    /**
     * `GET custom_fields` — the 自定义客户字段 page.
     */
    public function customFieldList(Request $request)
    {
        $type = (string) $request->input('type', 'client');

        $rows = DB::table('customfields')
            ->where('type', $type)
            ->orderBy('sortorder')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return $this->ok([
            'type_list' => AdminMeta::CUSTOM_FIELD_TYPES,
            'customfields' => $rows,
            'list' => $rows,
        ]);
    }

    /**
     * `POST custom_fields/create`
     */
    public function customFieldCreate(Request $request)
    {
        return $this->writeCustomField($request, 0);
    }

    /**
     * `POST custom_fields/update`
     */
    public function customFieldUpdate(Request $request)
    {
        return $this->writeCustomField($request, (int) $request->input('id', 0));
    }

    /**
     * `DELETE custom_fields/<id>`
     */
    public function customFieldDelete(Request $request, $id)
    {
        $id = (int) $id;

        DB::transaction(function () use ($id) {
            DB::table('customfields')->where('id', $id)->delete();
            DB::table('customfieldsvalues')->where('fieldid', $id)->delete();
        });

        return $this->ok(null, '删除成功');
    }

    /**
     * `POST custom_fields/sort` — drag-sort of the field list.
     */
    public function customFieldSort(Request $request)
    {
        $ids = $request->input('ids', []);

        if (! is_array($ids)) {
            return $this->validationFail('参数错误');
        }

        foreach (array_values($ids) as $index => $id) {
            DB::table('customfields')->where('id', (int) $id)->update(['sortorder' => $index]);
        }

        return $this->ok(null, '保存成功');
    }

    // -----------------------------------------------------------------
    // 实名认证
    // -----------------------------------------------------------------

    /**
     * `GET cerify_list` — the 实名认证 review queue.
     */
    public function certifyList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $status = $request->input('status');
        $keywords = trim((string) $request->input('keywords', ''));

        $person = DB::table('certifi_person')
            ->leftJoin('clients as c', 'c.id', '=', 'certifi_person.auth_user_id')
            ->select('certifi_person.id', 'certifi_person.auth_user_id', 'certifi_person.auth_real_name', 'certifi_person.auth_card_type', 'certifi_person.auth_card_number', 'certifi_person.status', 'certifi_person.img_one', 'certifi_person.img_two', 'certifi_person.img_three', 'certifi_person.certify_id', 'certifi_person.auth_fail', 'certifi_person.create_time', 'certifi_person.update_time', 'certifi_person.phone', 'certifi_person.bank', 'c.username', 'c.email', 'c.companyname');

        if ($status !== null && $status !== '' && $status !== 'ALL') {
            $person->where('certifi_person.status', (int) $status);
        }

        if ($keywords !== '') {
            $person->where(function ($q) use ($keywords) {
                $q->where('certifi_person.auth_real_name', 'like', "%{$keywords}%")
                    ->orWhere('certifi_person.auth_card_number', 'like', "%{$keywords}%")
                    ->orWhere('c.username', 'like', "%{$keywords}%");
            });
        }

        $rows = $person->orderByDesc('certifi_person.id')->get()->map(fn ($r) => (array) $r)->all();

        // `shd_certifi_person` is the individual table; companies live in
        // `shd_certifi_company` and the review page shows both.
        $companies = DB::table('certifi_company')
            ->leftJoin('clients as c', 'c.id', '=', 'certifi_company.auth_user_id')
            ->select('certifi_company.id', 'certifi_company.auth_user_id', 'certifi_company.auth_real_name', 'certifi_company.auth_card_type', 'certifi_company.auth_card_number', 'certifi_company.company_name', 'certifi_company.company_organ_code', 'certifi_company.status', 'certifi_company.img_one', 'certifi_company.img_two', 'certifi_company.img_three', 'certifi_company.certify_id', 'certifi_company.auth_fail', 'certifi_company.create_time', 'certifi_company.update_time', 'certifi_company.phone', 'certifi_company.bank', 'c.username', 'c.email', 'c.companyname')
            ->when($status !== null && $status !== '' && $status !== 'ALL', fn ($q) => $q->where('certifi_company.status', (int) $status))
            ->orderByDesc('certifi_company.id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        foreach ($companies as $index => $row) {
            $companies[$index]['type'] = 'company';
        }

        $merged = array_merge($rows, $companies);

        $total = count($merged);
        $slice = array_slice($merged, ($page - 1) * $limit, $limit);

        return $this->paginated($slice, $total, $page, $limit, [
            'data' => $slice,
            'count' => $total,
            'certifi_status' => [
                ['name' => '未审核', 'value' => 0],
                ['name' => '已认证', 'value' => 1],
                ['name' => '未通过', 'value' => 2],
                ['name' => '待审核', 'value' => 3],
                ['name' => '提交资料', 'value' => 4],
            ],
        ]);
    }

    /**
     * `GET certifi_type` / `GET certifi_types` — the review-type dictionary.
     */
    public function certifyTypes(Request $request)
    {
        return $this->ok([
            'person' => '个人认证',
            'company' => '企业认证',
            'types' => [
                ['value' => 'person', 'name' => '个人认证'],
                ['value' => 'company', 'name' => '企业认证'],
            ],
            'card_type' => [['value' => 1, 'name' => '身份证'], ['value' => 2, 'name' => '护照'], ['value' => 3, 'name' => '港澳通行证']],
        ]);
    }

    /**
     * `GET certifi_person_detail/<id>` — 实名 detail dialog.
     */
    public function certifyPersonDetail(Request $request, $id)
    {
        $id = (int) $id;

        $row = DB::table('certifi_person')->where('auth_user_id', $id)->orderByDesc('id')->first()
            ?? DB::table('certifi_person')->where('id', $id)->first();

        if ($row === null) {
            $row = DB::table('certifi_company')->where('auth_user_id', $id)->orderByDesc('id')->first()
                ?? DB::table('certifi_company')->where('id', $id)->first();
        }

        if ($row === null) {
            return $this->fail('实名信息不存在');
        }

        $data = (array) $row;
        $client = Client::query()->find((int) $row->auth_user_id);
        $data['username'] = $client?->username;
        $data['email'] = $client?->email;
        $data['companyname'] = $client?->companyname;

        return $this->ok($data);
    }

    /**
     * `POST certifi_status {uid,status}` — approve / reject 实名.
     */
    public function certifyStatus(Request $request)
    {
        $uid = (int) $request->input('uid', $request->input('auth_user_id', 0));
        $status = (int) $request->input('status', 0);
        $reason = (string) $request->input('reason', $request->input('auth_fail', ''));

        if ($uid <= 0) {
            return $this->validationFail('客户ID不能为空');
        }

        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $now = time();

        $updated = DB::table('certifi_person')->where('auth_user_id', $uid)->update([
            'status' => $status,
            'auth_fail' => $reason,
            'update_time' => $now,
        ]);

        DB::table('certifi_company')->where('auth_user_id', $uid)->update([
            'status' => $status,
            'auth_fail' => $reason,
            'update_time' => $now,
        ]);

        // Mirror the outcome onto the client row's `certifi` flag.
        $client->certifi = $status === 1 ? 1 : ($status === 2 ? 2 : (int) $client->certifi);
        $client->update_time = $now;
        $client->save();

        DB::table('certifi_log')->insert([
            'uid' => $uid,
            'status' => $status,
            'error' => $reason,
            'notes' => '审核人：'.$this->adminName(),
            'create_time' => $now,
        ]);

        $label = [0 => '未审核', 1 => '已认证', 2 => '未通过', 3 => '待审核'][$status] ?? '未知';
        $this->log('实名认证审核：'.$client->username.' → '.$label, $uid);

        return $this->ok(['id' => $uid, 'affect' => $updated], '操作成功');
    }

    /**
     * `GET certifi/download` / `GET certifi_person/download`
     */
    public function certifyDownload(Request $request)
    {
        $path = (string) $request->input('path', $request->input('img', ''));

        if ($path === '') {
            return $this->fail('文件不存在');
        }

        $absolute = public_path(ltrim($path, '/'));

        if (! is_file($absolute)) {
            return $this->fail('文件不存在');
        }

        return response()->download($absolute);
    }

    /**
     * `GET cerify_log_list` / `GET cerify_history_log`
     */
    public function certifyLogList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        $uid = (int) $request->input('uid', 0);

        $query = DB::table('certifi_log')->when($uid > 0, fn ($q) => $q->where('uid', $uid));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get()->map(fn ($r) => (array) $r)->all();

        return $this->paginated($rows, $total, $page, $limit, ['data' => $rows]);
    }

    public function certifyHistoryLog(Request $request)
    {
        return $this->certifyLogList($request);
    }

    /**
     * `GET client_list/resource` — the resource-pool customer list.
     */
    public function resourceList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = Client::query()->where('api_open', 1);

        if ($keywords = trim((string) $request->input('keywords', ''))) {
            $query->where(function ($q) use ($keywords) {
                $q->where('username', 'like', "%{$keywords}%")
                    ->orWhere('email', 'like', "%{$keywords}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->paginated($this->decorateClients($rows), $total, $page, $limit, [
            'credit_limit_invoice_status' => AdminMeta::INVOICE_STATUS,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Apply the customer list's advanced-search fields.
     */
    private function applyClientFilters($query, Request $request): void
    {
        foreach ([
            'username' => 'like',
            'companyname' => 'like',
            'email' => 'like',
            'phonenumber' => 'like',
            'qq' => 'like',
        ] as $field => $mode) {
            $value = trim((string) $request->input($field, ''));

            if ($value !== '') {
                $query->where($field, 'like', "%{$value}%");
            }
        }

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('status', (int) $status);
        }

        if ($group = $request->input('client_groups')) {
            $query->where('groupid', (int) $group);
        }

        if ($sale = $request->input('sale')) {
            $sale === 'ALL' ?: $query->where('sale_id', (int) $sale);
        }

        if (($certifi = $request->input('certifi')) !== null && $certifi !== '' && $certifi !== 'ALL') {
            $query->where('certifi', (int) $certifi);
        }

        if (($apiStatus = $request->input('api_status')) !== null && $apiStatus !== '' && $apiStatus !== 'ALL') {
            $query->where('api_open', (int) $apiStatus);
        }

        if ($level = $request->input('level')) {
            // Levels are computed rules, not a client column; the admin list
            // only supports the synthetic "全部" entry plus exact-name match.
            $level = (int) $level;
        }

        $custom = trim((string) $request->input('custom', ''));

        if ($custom !== '') {
            $fieldIds = DB::table('customfields')->where('type', 'client')->pluck('id')->all();

            $query->whereIn('id', function ($sub) use ($custom, $fieldIds) {
                $sub->select('relid')
                    ->from('customfieldsvalues')
                    ->whereIn('fieldid', $fieldIds ?: [-1])
                    ->where('value', 'like', "%{$custom}%");
            });
        }
    }

    /**
     * The `search` / `seachData` option maps the advanced-search form builds
     * itself from.
     */
    private function searchOptions(): array
    {
        return [
            'username' => '姓名',
            'companyname' => '公司名',
            'email' => '邮箱',
            'phonenumber' => '手机号',
            'status' => '状态',
            'qq' => 'QQ',
            'custom' => '自定义字段',
            'level' => '客户等级',
            'api_status' => 'API状态',
            'sale' => '销售',
            'client_groups' => '客户分组',
            'certifi' => '实名状态',
        ];
    }

    private function searchData(): array
    {
        return [
            'sale' => array_map(fn (array $a) => [
                'label' => $a['user_nickname'] ?: $a['user_login'],
                'value' => (int) $a['id'],
            ], AdminMeta::admins()),
            'client_groups' => array_map(fn (array $g) => [
                'label' => $g['group_name'],
                'value' => (int) $g['id'],
            ], AdminMeta::clientGroups()),
        ];
    }

    /**
     * Add the aggregate columns the customer table renders.
     */
    private function decorateClients($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->all();

        $income = DB::table('accounts')
            ->selectRaw('uid, SUM(amount_in) as amount_in, SUM(amount_out) as amount_out')
            ->whereIn('uid', $ids)
            ->whereNull('delete_time')
            ->groupBy('uid')
            ->get()
            ->keyBy('uid');

        $hostCounts = Host::query()
            ->selectRaw('uid, COUNT(*) as total')
            ->whereIn('uid', $ids)
            ->groupBy('uid')
            ->pluck('total', 'uid');

        $groupNames = ClientGroup::query()->pluck('group_name', 'id');
        $staff = User::query()->pluck('user_nickname', 'id');

        return $rows->map(function (Client $client) use ($income, $hostCounts, $groupNames, $staff) {
            $row = $client->toArray();
            $account = $income->get($client->id);

            $row['amount_in'] = $this->money((float) ($account->amount_in ?? 0));
            $row['amount_out'] = $this->money((float) ($account->amount_out ?? 0));
            $row['host_total'] = (int) ($hostCounts[$client->id] ?? 0);
            $row['group_name'] = (string) ($groupNames[$client->groupid] ?? '');
            $row['user_nickname'] = (string) ($staff[$client->sale_id] ?? '');
            $row['credit'] = $this->money((float) $client->credit);
            $row['credit_limit'] = $this->money((float) $client->credit_limit);
            $row['credit_limit_balance'] = $this->money((float) $client->credit_limit_balance);
            $row['status_zh'] = AdminMeta::clientStatusLabel((int) $client->status);
            $row['certifi_zh'] = AdminMeta::CLIENT_CERTIFI_STATUS[(int) $client->certifi]['name'] ?? '未认证';

            return $row;
        })->all();
    }

    /**
     * Sort a decorated list by a PHP-computed aggregate.
     */
    private function sortByAggregate(array $list, string $key, string $direction): array
    {
        usort($list, fn ($a, $b) => $direction === 'ASC'
            ? ($a[$key] ?? 0) <=> ($b[$key] ?? 0)
            : ($b[$key] ?? 0) <=> ($a[$key] ?? 0));

        return $list;
    }

    /**
     * The base client payload shared by the profile and summary endpoints.
     */
    private function clientDetail(Client $client): array
    {
        $row = $client->toArray();
        $row['group_name'] = (string) (optional($client->group)->group_name ?? '');
        $row['user_nickname'] = (string) (User::query()->whereKey($client->sale_id)->value('user_nickname') ?? '');
        $row['saler'] = $row['user_nickname'];
        $row['register_time'] = (int) $client->create_time;
        $row['last_login_ip'] = $client->lastloginip;
        $row['status_zh'] = AdminMeta::clientStatusLabel((int) $client->status);
        $row['certifi_zh'] = AdminMeta::CLIENT_CERTIFI_STATUS[(int) $client->certifi]['name'] ?? '未认证';
        $row['host_total'] = Host::query()->where('uid', $client->id)->count();
        $row['credit'] = $this->money((float) $client->credit);
        $row['credit_limit'] = $this->money((float) $client->credit_limit);

        return $row;
    }

    /**
     * 实名信息 block for the summary card.
     */
    private function certificationInfo(Client $client): array
    {
        $person = DB::table('certifi_person')->where('auth_user_id', $client->id)->orderByDesc('id')->first();
        $company = DB::table('certifi_company')->where('auth_user_id', $client->id)->orderByDesc('id')->first();

        if ($company !== null) {
            $row = (array) $company;
            $row['type'] = 'company';

            return $row;
        }

        if ($person !== null) {
            $row = (array) $person;
            $row['type'] = 'person';
            $row['name'] = $person->auth_real_name;
            $row['idcard'] = $person->auth_card_number;

            return $row;
        }

        return ['type' => '', 'name' => '', 'idcard' => '', 'company_name' => '', 'company_organ_code' => ''];
    }

    /**
     * Custom-field values keyed by field id, as the profile form expects.
     */
    private function customFieldValues(Client $client): array
    {
        $fields = DB::table('customfields')->where('type', 'client')->orderBy('sortorder')->get();

        $values = DB::table('customfieldsvalues')
            ->where('relid', $client->id)
            ->pluck('value', 'fieldid');

        return $fields->map(function ($field) use ($values) {
            $row = (array) $field;
            $row['value'] = (string) ($values[$field->id] ?? '');

            return $row;
        })->all();
    }

    private function saveCustomFieldValue(int $uid, int $fieldId, string $value): void
    {
        if ($fieldId <= 0) {
            return;
        }

        $exists = DB::table('customfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $uid)
            ->exists();

        if ($exists) {
            DB::table('customfieldsvalues')
                ->where('fieldid', $fieldId)
                ->where('relid', $uid)
                ->update(['value' => $value]);
        } else {
            DB::table('customfieldsvalues')->insert([
                'fieldid' => $fieldId,
                'relid' => $uid,
                'type' => 'client',
                'value' => $value,
            ]);
        }
    }

    /**
     * Build the invoice-item list from the two shapes the SPA posts: a single
     * description/amount pair or an `items[]` array.
     */
    private function invoiceItemsFromRequest(Request $request, string $defaultDescription, float $defaultAmount): array
    {
        $items = $request->input('items');

        if (is_array($items) && $items !== []) {
            $out = [];
            $sum = 0.0;

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $amount = $this->money((float) ($item['amount'] ?? 0));

                if ($amount <= 0) {
                    continue;
                }

                $out[] = [
                    'type' => (string) ($item['type'] ?? ''),
                    'rel_id' => (int) ($item['rel_id'] ?? 0),
                    'description' => (string) ($item['description'] ?? $defaultDescription),
                    'amount' => $amount,
                ];

                $sum += $amount;
            }

            return [$out, $this->money($sum)];
        }

        if ($defaultAmount <= 0) {
            return [[], 0.0];
        }

        return [[[
            'type' => (string) $request->input('type', ''),
            'rel_id' => 0,
            'description' => $defaultDescription,
            'amount' => $defaultAmount,
        ]], $defaultAmount];
    }

    /**
     * Invoice row with the joined labels the list renders.
     */
    private function invoiceRow(Invoice $invoice): array
    {
        $row = $invoice->toArray();
        $row['username'] = (string) (Client::query()->whereKey($invoice->uid)->value('username') ?? '');
        $row['status_zh'] = AdminMeta::invoiceStatusLabel((string) $invoice->status);
        $row['type_zh'] = (string) $invoice->type;
        $row['total'] = $this->money((float) $invoice->total);
        $row['subtotal'] = $this->money((float) $invoice->subtotal);

        return $row;
    }

    /**
     * Host row decorated with the labels the 产品/服务 tables show.
     */
    private function hostRow(Host $host): array
    {
        $row = $host->toArray();
        $product = $host->product;
        $client = Client::query()->find($host->uid);

        $row['productname'] = $product?->name;
        $row['type'] = $product?->type;
        $row['type_zh'] = AdminMeta::productTypeLabel($product?->type);
        $row['username'] = $client?->username;
        $row['companyname'] = $client?->companyname;
        $row['domainstatus_zh'] = AdminMeta::domainStatusLabel((string) $host->domainstatus);
        $row['billingcycle_zh'] = AdminMeta::BILLING_CYCLES[(string) $host->billingcycle] ?? (string) $host->billingcycle;
        $row['amount'] = $this->money((float) $host->amount);
        $row['firstpaymentamount'] = $this->money((float) $host->firstpaymentamount);
        $row['regdate'] = (int) $host->regdate;
        $row['nextduedate'] = (int) $host->nextduedate;
        $row['user_nickname'] = (string) (User::query()->whereKey($client?->sale_id)->value('user_nickname') ?? '');

        return $row;
    }

    private function addTrackRecord(int $uid, string $description): void
    {
        try {
            DB::table('clients_track_record')->insert([
                'uid' => $uid,
                'des' => $description,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        } catch (\Throwable) {
            // Tracking is best-effort.
        }
    }

    /**
     * Render a min/max pair into the `expense`-style column the schema keeps
     * as a varchar range.
     */
    private function rangeValue(Request $request, string $prefix): string
    {
        $min = $request->input($prefix.'_min');
        $max = $request->input($prefix.'_max');

        if ($min === null && $max === null) {
            $single = $request->input($prefix);

            return $single === null ? '' : (string) $single;
        }

        return (string) ($min ?? '').'-'.(string) ($max ?? '');
    }

    /**
     * Create or update one `shd_customfields` row.
     */
    private function writeCustomField(Request $request, int $id)
    {
        $name = trim((string) $request->input('fieldname', $request->input('name', '')));

        if ($name === '') {
            return $this->validationFail('字段名称不能为空');
        }

        $type = (string) $request->input('fieldtype', $request->input('type', 'text'));

        $data = [
            'type' => (string) $request->input('reltype', 'client'),
            'fieldname' => $name,
            'fieldtype' => $type,
            'description' => (string) $request->input('description', ''),
            'regexpr' => (string) $request->input('regexpr', ''),
            'fieldoptions' => (string) $request->input('fieldoptions', ''),
            'adminonly' => (int) $request->input('adminonly', 0),
            'required' => (int) $request->input('required', 0),
            'showorder' => (int) $request->input('showorder', 0),
            'showinvoice' => (int) $request->input('showinvoice', 0),
            'showdetail' => (int) $request->input('showdetail', 0),
            'sortorder' => (int) $request->input('sortorder', 0),
            'update_time' => time(),
        ];

        if ($id > 0) {
            DB::table('customfields')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();
        $newId = DB::table('customfields')->insertGetId($data);

        return $this->ok(['id' => $newId], '添加成功');
    }
}

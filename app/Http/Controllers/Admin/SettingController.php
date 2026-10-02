<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\PromoCode;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 设置 — 常规设置 (`config_general/*`), the automatic-task (定时任务) settings,
 * email/SMS templates, promo codes and currencies.
 *
 * Every settings tab is a getter/setter pair over a group of
 * `shd_configuration` keys; the groups are declared in
 * `App\Services\Admin\SettingService::GROUPS` so the getter and the setter can
 * never drift apart. The original exposes some tabs as `*_page` (GET) plus a
 * differently named POST target — both names are wired to the same action.
 */
class SettingController extends AdminController
{
    /**
     * `GET|POST config_general/general` — 常规设置.
     */
    public function general(Request $request)
    {
        return $this->tab($request, 'general');
    }

    /**
     * `GET|POST config_general/local` — 语言设置.
     */
    public function local(Request $request)
    {
        return $this->tab($request, 'local');
    }

    /**
     * `GET|POST config_general/support` — 联系方式.
     */
    public function support(Request $request)
    {
        return $this->tab($request, 'support');
    }

    /**
     * `GET|POST config_general/invoice` — 发票/合同设置.
     */
    public function invoice(Request $request)
    {
        return $this->tab($request, 'invoice');
    }

    /**
     * `GET|POST config_general/recharge` — 充值与财务设置.
     */
    public function recharge(Request $request)
    {
        return $this->tab($request, 'recharge');
    }

    /**
     * `GET|POST config_general/affiliate` — 推介计划设置.
     */
    public function affiliate(Request $request)
    {
        return $this->tab($request, 'affiliate');
    }

    /**
     * `GET|POST config_general/safe` — 安全设置.
     */
    public function safe(Request $request)
    {
        return $this->tab($request, 'safe');
    }

    /**
     * `GET|POST config_general/other` — 其他设置.
     */
    public function other(Request $request)
    {
        return $this->tab($request, 'other');
    }

    /**
     * `GET|POST config_general/apiconfig` — 是否开启资源API (下游管理).
     */
    public function apiConfig(Request $request)
    {
        return $this->tab($request, 'apiconfig');
    }

    /**
     * `GET config_general/register_login_page` / `POST config_general/register_login`
     */
    public function registerLogin(Request $request)
    {
        $data = SettingService::group('register_login');
        $data['clients_profoptional_list'] = AdminMeta::PROFILE_OPTIONAL_FIELDS;
        $data['login_register_custom_require_list'] = AdminMeta::PROFILE_OPTIONAL_FIELDS;

        if ($request->isMethod('post')) {
            SettingService::saveGroup('register_login', $request->all());

            foreach (['clients_profoptional_checked', 'login_register_custom_require'] as $listKey) {
                if ($request->has($listKey)) {
                    SettingService::putMany([$listKey => $request->input($listKey)]);
                }
            }

            $this->log('保存注册登录设置');

            return $this->ok(null, '保存成功');
        }

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'data' => $this->decodeLists($data, ['clients_profoptional_checked', 'login_register_custom_require']),
        ] + $this->envelope());
    }

    /**
     * `GET config_general/invoice_page`
     */
    public function invoicePage(Request $request)
    {
        return $this->ok(SettingService::group('invoice'));
    }

    /**
     * `POST config_general/invoice_post`
     */
    public function invoicePost(Request $request)
    {
        SettingService::saveGroup('invoice', $request->all());

        return $this->ok(null, '保存成功');
    }

    /**
     * `GET config_general/productgroup_page` — 商品订购设置.
     */
    public function productGroupPage(Request $request)
    {
        $groups = DB::table('nav_group')->orderBy('order')->get();

        return $this->ok($groups->map(fn ($g) => [
            'id' => (int) $g->id,
            'groupname' => $g->groupname,
            'fa_icon' => $g->fa_icon,
            'order' => (int) $g->order,
            'nav' => DB::table('nav')->where('nav_type', 2)->get()->map(function ($n) use ($g) {
                $row = (array) $n;
                $row['is_active'] = (int) $n->menuid === (int) $g->id ? 1 : 0;

                return $row;
            })->all(),
        ])->all());
    }

    /**
     * `POST config_general/productgroup` — save the 商品订购设置 order.
     */
    public function productGroup(Request $request)
    {
        $groups = $request->input('data', $request->input('groups', []));

        if (is_array($groups)) {
            foreach ($groups as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                DB::table('nav_group')->where('id', (int) $row['id'])->update([
                    'order' => (int) ($row['order'] ?? 0),
                ]);
            }
        }

        foreach ((array) $request->input('nav', []) as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            DB::table('nav')->where('id', (int) $row['id'])->update([
                'menuid' => (int) ($row['menuid'] ?? 0),
                'order' => (int) ($row['order'] ?? 0),
            ]);
        }

        if ($request->filled('order')) {
            SettingService::putMany(['productgroup_order' => $request->input('order')]);
        }

        $this->log('保存商品订购设置');

        return $this->ok(null, '保存成功');
    }

    /**
     * `GET|POST config_general/secondverify` — 二次验证.
     */
    public function secondVerify(Request $request)
    {
        if ($request->isMethod('post')) {
            SettingService::saveGroup('secondverify', $request->all());

            foreach (['second_verify_action', 'second_verify_action_home_type', 'second_verify_action_home', 'second_verify_action_admin'] as $key) {
                if ($request->has($key)) {
                    SettingService::putMany([$key => $request->input($key)]);
                }
            }

            $this->log('保存二次验证设置');

            return $this->ok(null, '保存成功');
        }

        $group = SettingService::group('secondverify');

        return $this->ok($this->decodeLists($group, [
            'second_verify_action', 'second_verify_action_home_type',
            'second_verify_action_home', 'second_verify_action_admin',
        ]) + [
            'actions' => $this->secondVerifyActions(),
            'types' => [
                ['value' => 'email', 'name' => '邮件'],
                ['value' => 'phone', 'name' => '手机'],
            ],
        ]);
    }

    /**
     * `GET config_general/captcha_page` — 验证码设置.
     */
    public function captchaPage(Request $request)
    {
        return $this->ok(SettingService::group('captcha'));
    }

    /**
     * `POST config_general/register_login_captcha` / `POST config_general/captcha`
     */
    public function captchaPost(Request $request)
    {
        SettingService::saveGroup('captcha', $request->all());

        $this->log('保存验证码设置');

        return $this->ok(null, '保存成功');
    }

    /**
     * `GET config_general/buy_product_page` — 订购页面设置.
     */
    public function buyProductPage(Request $request)
    {
        return $this->ok(SettingService::group('buy_product') + [
            'cart_themes' => AdminMeta::CART_THEMES,
        ]);
    }

    /**
     * `POST config_general/buy_product`
     */
    public function buyProduct(Request $request)
    {
        SettingService::saveGroup('buy_product', $request->all());

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST config_general/navgrouporder` — reorder the 会员中心导航分组.
     */
    public function navGroupOrder(Request $request)
    {
        $rows = $request->input('data', $request->input('list', []));

        if (is_array($rows)) {
            foreach (array_values($rows) as $index => $row) {
                $id = is_array($row) ? (int) ($row['id'] ?? 0) : (int) $row;
                $order = is_array($row) ? (int) ($row['order'] ?? $index) : $index;

                if ($id > 0) {
                    DB::table('nav_group')->where('id', $id)->update(['order' => $order]);
                }
            }
        }

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST config_general/newGeneral` — the generic setter the second-verify
     * dialog uses when it posts a raw key/value pair.
     */
    public function newGeneral(Request $request)
    {
        $key = (string) $request->input('key', $request->input('setting', ''));

        if ($key === '') {
            return $this->validationFail('缺少配置项');
        }

        SettingService::putMany([$key => $request->input('value', $request->input($key))]);

        $this->log('修改配置项：'.$key);

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST config_general/getConfig` — read one or more raw config keys.
     */
    public function getConfig(Request $request)
    {
        $keys = $request->input('key', $request->input('keys', []));

        if (is_string($keys)) {
            $keys = [$keys];
        }

        if (! is_array($keys) || $keys === []) {
            return $this->ok(SettingService::all());
        }

        $out = [];

        foreach ($keys as $key) {
            $out[(string) $key] = SettingService::value((string) $key);
        }

        return $this->ok($out);
    }

    /**
     * `POST config_general/getConfigOption` — read a whole settings tab by name.
     */
    public function getConfigOption(Request $request)
    {
        $group = (string) $request->input('group', $request->input('option', 'general'));

        if (! isset(SettingService::GROUPS[$group])) {
            return $this->fail('配置分组不存在');
        }

        return $this->ok(SettingService::group($group));
    }

    /**
     * `GET config_general/lang/list` — installed languages.
     */
    public function languageList(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'lang' => AdminMeta::LANGUAGES,
        ] + $this->envelope());
    }

    /**
     * `GET|POST config_general/email/index` — SMTP transport settings.
     */
    public function emailConfig(Request $request)
    {
        if ($request->isMethod('post')) {
            SettingService::putMany([
                'shd_allow_email_send' => $request->input('shd_allow_email_send', $request->input('allow_email_send', '0')),
                'smtp_host' => $request->input('host', $request->input('smtp_host', '')),
                'smtp_port' => $request->input('port', $request->input('smtp_port', '')),
                'smtp_username' => $request->input('username', $request->input('smtp_username', '')),
                'smtp_password' => $request->input('password', $request->input('smtp_password', '')),
                'smtp_secure' => $request->input('smtpsecure', $request->input('smtp_secure', '')),
                'smtp_fromname' => $request->input('fromname', $request->input('smtp_fromname', '')),
                'systememail' => $request->input('systememail', ''),
                'smtp_charset' => $request->input('charset', $request->input('smtp_charset', 'utf-8')),
            ]);

            $this->log('保存邮件发送设置');

            return $this->ok(null, '保存成功');
        }

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'charsets' => ['utf-8' => 'UTF-8', 'gbk' => 'GBK', 'iso-8859-1' => 'ISO-8859-1'],
            'shd_allow_email_send' => SettingService::value('shd_allow_email_send', '0'),
            'type' => 'smtp',
            'charset' => SettingService::value('smtp_charset', 'utf-8'),
            'port' => SettingService::value('smtp_port', '465'),
            'host' => SettingService::value('smtp_host', ''),
            'username' => SettingService::value('smtp_username', ''),
            'password' => SettingService::value('smtp_password', ''),
            'smtpsecure' => SettingService::value('smtp_secure', 'ssl'),
            'fromname' => SettingService::value('smtp_fromname', ''),
            'systememail' => SettingService::value('systememail', ''),
            'subject' => '',
            'body' => '',
        ] + $this->envelope());
    }

    /**
     * `GET|POST config_general/mobile/index` — SMS gateway settings.
     */
    public function mobileConfig(Request $request)
    {
        if ($request->isMethod('post')) {
            SettingService::putMany([
                'allow_mobile_send' => $request->input('allow_mobile_send', '0'),
                'sms_appkey' => $request->input('appkey', $request->input('sms_appkey', '')),
                'sms_secret' => $request->input('secret', $request->input('sms_secret', '')),
                'sms_sign' => $request->input('sign', $request->input('sms_sign', '')),
                'sms_operator' => $request->input('sms_operator', ''),
            ]);

            $this->log('保存短信发送设置');

            return $this->ok(null, '保存成功');
        }

        return $this->ok([
            'allow_mobile_send' => SettingService::value('allow_mobile_send', '0'),
            'appkey' => SettingService::value('sms_appkey', ''),
            'secret' => SettingService::value('sms_secret', ''),
            'sign' => SettingService::value('sms_sign', ''),
            'sms_operator' => SettingService::value('sms_operator', ''),
            'operators' => [
                ['value' => 'aliyun', 'name' => '阿里云'],
                ['value' => 'tencent', 'name' => '腾讯云'],
                ['value' => 'huawei', 'name' => '华为云'],
            ],
        ]);
    }

    /**
     * `GET|POST config_general/certifi/index` — 实名认证接口设置.
     */
    public function certifyConfig(Request $request)
    {
        $keys = ['certifi_open', 'certifi_isrealname', 'certifi_is_stop', 'certifi_stop_day', 'certifi_author', 'certifi_appcode', 'certifi_three_type'];

        if ($request->isMethod('post')) {
            $data = array_intersect_key($request->all(), array_flip($keys));
            SettingService::putMany($data);

            $this->log('保存实名认证设置');

            return $this->ok(null, '保存成功');
        }

        $out = [];

        foreach ($keys as $key) {
            $out[$key] = SettingService::value($key, '');
        }

        return $this->ok($out);
    }

    /**
     * `GET|POST config_general/header` — the 官网头部 settings.
     */
    public function headerConfig(Request $request)
    {
        $keys = ['header_notice', 'header_service_qq', 'header_service_phone', 'header_custom_code', 'www_logo', 'www_favicon'];

        if ($request->isMethod('post')) {
            SettingService::putMany(array_intersect_key($request->all(), array_flip($keys)));

            return $this->ok(null, '保存成功');
        }

        $out = [];

        foreach ($keys as $key) {
            $out[$key] = SettingService::value($key, '');
        }

        return $this->ok($out);
    }

    /**
     * `GET config_general/new_login` — 登录页面设置.
     */
    public function loginSetting(Request $request)
    {
        $keys = ['login_page_style', 'login_page_notice', 'login_page_custom_code', 'allow_wechat_login'];

        $out = [];

        foreach ($keys as $key) {
            $out[$key] = SettingService::value($key, '');
        }

        return $this->ok($out + ['background' => SettingService::value('login_page_bg', '')]);
    }

    // -----------------------------------------------------------------
    // 定时任务
    // -----------------------------------------------------------------

    /**
     * `GET cron_page` — 定时任务 settings.
     */
    public function cronPage(Request $request)
    {
        $out = [];

        foreach (SettingService::CRON_KEYS as $key) {
            $out[$key] = SettingService::value($key, '');
        }

        $out['product_type'] = AdminMeta::PRODUCT_TYPES;
        $out['last_run'] = DB::table('cron_log')->orderByDesc('id')->value('create_time');

        return $this->ok($out);
    }

    /**
     * `POST save_cron`
     */
    public function cronSave(Request $request)
    {
        $data = [];

        foreach (SettingService::CRON_KEYS as $key) {
            if ($request->has($key)) {
                $data[$key] = $request->input($key);
            }
        }

        SettingService::putMany($data);

        $this->log('保存定时任务设置');

        return $this->ok(null, '保存成功');
    }

    /**
     * `GET run_cron/list` — the 定时任务执行记录.
     */
    public function cronRunList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('cron_log');

        if ($name = trim((string) $request->input('name', $request->input('keywords', '')))) {
            $query->where('name', 'like', "%{$name}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get()->map(fn ($r) => (array) $r)->all();

        return $this->paginated($rows, $total, $page, $limit);
    }

    /**
     * `GET run_cron/trend` — execution counts per day.
     */
    public function cronRunTrend(Request $request)
    {
        $days = max(1, (int) $request->input('days', 30));
        $from = strtotime('-'.$days.' days');

        $rows = DB::table('cron_log')
            ->selectRaw('FROM_UNIXTIME(create_time, "%Y-%m-%d") as date, COUNT(*) as total')
            ->where('create_time', '>=', $from)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $this->ok([
            'list' => $rows->map(fn ($r) => ['date' => $r->date, 'total' => (int) $r->total])->all(),
        ]);
    }

    /**
     * `GET cron/exec` — manually run the scheduled tasks.
     */
    public function cronExec(Request $request)
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('schedule:run');
            $output = \Illuminate\Support\Facades\Artisan::output();
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '执行失败');
        }

        $this->log('手动执行定时任务');

        return $this->ok(['output' => $output], '执行完成');
    }

    // -----------------------------------------------------------------
    // 邮件 / 短信模板
    // -----------------------------------------------------------------

    /**
     * `GET email_template/email_list`
     */
    public function emailTemplateList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('email_templates');

        if ($name = trim((string) $request->input('name', $request->input('keywords', '')))) {
            $query->where(function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%")->orWhere('type', 'like', "%{$name}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('id')->forPage($page, $limit)->get();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'total' => $total,
            'email_list' => $rows->map(fn ($r) => (array) $r)->all(),
            'email_operator' => [
                ['value' => 'eq', 'name' => '等于'],
                ['value' => 'like', 'name' => '包含'],
            ],
            'mail' => [
                'host' => SettingService::value('smtp_host', ''),
                'port' => SettingService::value('smtp_port', ''),
                'username' => SettingService::value('smtp_username', ''),
            ],
        ] + $this->envelope());
    }

    /**
     * `GET email_template/create_template` — the type picker.
     */
    public function emailTemplateCreatePage(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'type' => $this->emailTypes(),
            'lang' => AdminMeta::LANGUAGES,
        ] + $this->envelope());
    }

    /**
     * `POST email_template/create_template_post`
     */
    public function emailTemplateCreate(Request $request)
    {
        $type = (string) $request->input('type', '');
        $name = (string) $request->input('name', '');

        if ($type === '') {
            return $this->validationFail('请选择邮件类型');
        }

        $id = (int) DB::table('email_templates')->insertGetId([
            'type' => $type,
            'name' => $name !== '' ? $name : $type,
            'name_en' => (string) $request->input('name_en', ''),
            'subject' => (string) $request->input('subject', ''),
            'message' => (string) $request->input('message', ''),
            'language' => (string) $request->input('language', 'zh-cn'),
            'disabled' => 0,
            'custom' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $this->log('新增邮件模板：'.$name, $id);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `GET email_template/edit_template/<id>`
     */
    public function emailTemplateEdit(Request $request, $id = null)
    {
        $id = (int) ($id ?? $request->input('id', 0));
        $row = DB::table('email_templates')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('邮件模板不存在');
        }

        return $this->ok([
            'email' => (array) $row,
            'params' => $this->emailParams((string) $row->type),
            'lang' => AdminMeta::LANGUAGES,
        ]);
    }

    /**
     * `POST email_template/edit_template_post`
     */
    public function emailTemplateUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('email_templates')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('邮件模板不存在');
        }

        $data = [];

        foreach (['subject', 'message', 'fromname', 'fromemail', 'copyto', 'blind_copy_to', 'language', 'name', 'name_en'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        if ($request->has('attachments')) {
            $data['attachments'] = is_array($request->input('attachments'))
                ? implode(',', $request->input('attachments'))
                : (string) $request->input('attachments');
        }

        if ($request->has('disabled')) {
            $data['disabled'] = (int) $request->input('disabled');
        }

        $data['update_time'] = time();

        DB::table('email_templates')->where('id', $id)->update($data);

        $this->log('编辑邮件模板 #'.$id, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `POST email_template/disabled_template` / `operator_switch`
     */
    public function emailTemplateDisable(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        $disabled = $request->has('disabled')
            ? (int) $request->input('disabled')
            : 1;

        DB::table('email_templates')->whereIn('id', $ids)->update([
            'disabled' => $disabled,
            'update_time' => time(),
        ]);

        return $this->ok(['ids' => array_values($ids)], '操作成功');
    }

    /**
     * `GET email_template/delete_template/<id>`
     */
    public function emailTemplateDelete(Request $request, $id)
    {
        DB::table('email_templates')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET email_template/manage_language`
     */
    public function emailTemplateLanguages(Request $request)
    {
        $used = DB::table('email_templates')->select('language')->distinct()->pluck('language')->filter()->all();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'langs' => AdminMeta::LANGUAGES,
            'lang_used' => $used,
        ] + $this->envelope());
    }

    /**
     * `POST email_template/manage_language_post`
     */
    public function emailTemplateLanguageSave(Request $request)
    {
        SettingService::putMany(['email_lang_list' => $request->input('langs', $request->input('language', []))]);

        return $this->ok(null, '保存成功');
    }

    /**
     * `GET email_template/params` — the replaceable placeholders for a type.
     */
    public function emailTemplateParams(Request $request)
    {
        return $this->ok($this->emailParams((string) $request->input('type', '')));
    }

    /**
     * `POST email_template/send_email` / `POST config_message/send_email` —
     * send a test message.
     */
    public function sendTestEmail(Request $request)
    {
        $to = trim((string) $request->input('email', $request->input('to', '')));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->validationFail('请填写有效的邮箱地址');
        }

        $subject = (string) ($request->input('subject') ?: '测试邮件');
        $body = (string) ($request->input('body') ?: '这是一封来自管理后台的测试邮件。');

        try {
            \Illuminate\Support\Facades\Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            return $this->fail('发送失败：'.$e->getMessage());
        }

        $this->log('发送测试邮件至 '.$to);

        return $this->ok(null, '发送成功');
    }

    /**
     * `GET config_message/template_list` — SMS templates.
     */
    public function messageTemplateList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('message_template');
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'total' => $total,
            'templates' => $rows->map(fn ($r) => (array) $r)->all(),
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
        ] + $this->envelope());
    }

    /**
     * `GET config_message/mobiletemplate/list`
     */
    public function messageTemplateMobile(Request $request)
    {
        $rows = DB::table('message_template')->orderByDesc('id')->get();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'templates' => $rows->map(fn ($r) => (array) $r)->all(),
            'default' => '',
        ] + $this->envelope());
    }

    /**
     * `GET config_message/update_tem_status`
     */
    public function messageTemplateStatus(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('message_template')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('短信模板不存在');
        }

        $status = $request->has('status')
            ? (int) $request->input('status')
            : ((int) $row->status === 1 ? 0 : 1);

        DB::table('message_template')->where('id', $id)->update([
            'status' => $status,
            'update_time' => time(),
        ]);

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '更新成功',
        ] + $this->envelope());
    }

    /**
     * `GET config_message/config_mobile`
     */
    public function messageMobileConfig(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'msg_config' => [
                'allow_mobile_send' => SettingService::value('allow_mobile_send', '0'),
                'sms_operator' => SettingService::value('sms_operator', ''),
                'sms_sign' => SettingService::value('sms_sign', ''),
            ],
        ] + $this->envelope());
    }

    /**
     * `GET config_message/delete_template {ids,type}`
     */
    public function messageTemplateDelete(Request $request)
    {
        $ids = $request->input('ids', $request->input('id', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter(array_map('intval', explode(',', (string) $ids)));

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::table('message_template')->whereIn('id', $ids)->delete();

        return $this->ok(['ids' => array_values($ids)], '删除成功');
    }

    /**
     * `POST config_message/check_post {ids,type}` — submit templates to the
     * SMS provider for approval.
     */
    public function messageTemplateCheck(Request $request)
    {
        $ids = $request->input('ids', []);

        if (! is_array($ids) || $ids === []) {
            return $this->validationFail('请选择要提交的模板');
        }

        // Without a configured provider the submission can only be recorded.
        DB::table('message_template')->whereIn('id', array_map('intval', $ids))->update([
            'status' => 1,
            'update_time' => time(),
        ]);

        $this->log('提交短信模板审核：'.implode(',', array_map('intval', $ids)));

        return $this->ok(null, '提交成功');
    }

    /**
     * `GET config_message/test_message_template_page`
     */
    public function messageTemplateTestPage(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'sms' => [
                'templates' => DB::table('message_template')->get()->map(fn ($r) => (array) $r)->all(),
                'phone_code' => DB::table('sms_country')->get()->toArray(),
            ],
        ] + $this->envelope());
    }

    /**
     * `POST config_message/update_template_post`
     */
    public function messageTemplateUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('message_template')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('短信模板不存在');
        }

        $data = [];

        foreach (['title', 'content', 'remark', 'template_id', 'sms_operator'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        if ($request->has('range_type')) {
            $data['range_type'] = (int) $request->input('range_type');
        }

        $data['update_time'] = time();

        DB::table('message_template')->where('id', $id)->update($data);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `GET config_message/create_template_page`
     */
    public function messageTemplateCreatePage(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'base_args' => $this->smsArgs(),
            'combine' => [],
            'data' => [],
            'allowedSmsOperator' => ['aliyun', 'tencent', 'huawei'],
        ] + $this->envelope());
    }

    /**
     * `POST config_message/test_message_template`
     */
    public function messageTemplateTest(Request $request)
    {
        $phone = trim((string) $request->input('phone', ''));

        if ($phone === '') {
            return $this->validationFail('请填写手机号');
        }

        $template = DB::table('message_template')->where('id', (int) $request->input('id', 0))->first();

        if ($template === null) {
            return $this->notFound('短信模板不存在');
        }

        $content = (string) $template->content;

        $result = (new \App\Services\VerifyCodeService())->send('sms', $phone, $content, 'admin_test');

        if (! $result) {
            return $this->fail('发送失败，请检查短信接口配置');
        }

        $this->log('发送测试短信至 '.$phone);

        return $this->ok(null, '发送成功');
    }

    /**
     * `POST config_message/set_sms` — the SMS send-rules page.
     */
    public function messageSetSms(Request $request)
    {
        if ($request->isMethod('post')) {
            SettingService::putMany([
                'sms_range_type' => $request->input('range_type', ''),
                'sms_select_temp' => json_encode($request->input('select_temp', []), JSON_UNESCAPED_UNICODE),
            ]);

            return $this->ok(null, '保存成功');
        }

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'message_template_lists' => DB::table('message_template')->get()->map(fn ($r) => (array) $r)->all(),
            'range_type' => SettingService::value('sms_range_type', ''),
            'templates' => DB::table('message_template')->get()->map(fn ($r) => (array) $r)->all(),
            'select_temp' => [],
            'sms_setting' => [],
        ] + $this->envelope());
    }

    /**
     * `POST config_message/sendmessage_post` — batch SMS/email push.
     */
    public function sendMessage(Request $request)
    {
        $type = (string) $request->input('type', 'email');
        $uids = $request->input('uid', $request->input('uids', []));
        $uids = is_array($uids) ? array_filter(array_map('intval', $uids)) : array_filter(array_map('intval', explode(',', (string) $uids)));

        $content = (string) $request->input('content', $request->input('message', ''));
        $subject = (string) $request->input('subject', '系统通知');

        if ($content === '') {
            return $this->validationFail('消息内容不能为空');
        }

        $clients = $uids === []
            ? Client::query()->where('status', 1)->limit(500)->get()
            : Client::query()->whereIn('id', $uids)->get();

        $sent = 0;
        $failed = 0;

        foreach ($clients as $client) {
            try {
                if ($type === 'sms') {
                    if (trim((string) $client->phonenumber) === '') {
                        continue;
                    }

                    (new \App\Services\VerifyCodeService())->send('sms', (string) $client->phonenumber, $content, 'admin_push');
                } else {
                    if (trim((string) $client->email) === '') {
                        continue;
                    }

                    \Illuminate\Support\Facades\Mail::raw($content, function ($message) use ($client, $subject) {
                        $message->to((string) $client->email)->subject($subject);
                    });
                }

                $sent++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $this->log('批量推送'.($type === 'sms' ? '短信' : '邮件').'：成功 '.$sent.' 失败 '.$failed);

        return $this->ok(['sent' => $sent, 'failed' => $failed], '发送完成');
    }

    // -----------------------------------------------------------------
    // 优惠码
    // -----------------------------------------------------------------

    /**
     * `GET list_promo_code`
     */
    public function promoList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = PromoCode::query();

        if (($type = $request->input('type')) !== null && $type !== '' && $type !== 'all') {
            if ($type === 'active') {
                $query->where(function ($q) {
                    $q->where('expiration_time', 0)
                        ->orWhere('expiration_time', '>', time());
                });
            } else {
                $query->where('type', $type);
            }
        }

        if ($code = trim((string) $request->input('code', $request->input('keywords', '')))) {
            $query->where('code', 'like', "%{$code}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(function (PromoCode $promo) {
            $row = $promo->toArray();
            $row['type_zh'] = AdminMeta::PROMO_TYPES[(string) $promo->type] ?? (string) $promo->type;
            $row['appliesto'] = $this->csvToArray($promo->appliesto);
            $row['requires'] = $this->csvToArray($promo->requires);
            $row['cycles'] = $this->csvToArray($promo->cycles);

            return $row;
        })->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
            'type' => AdminMeta::PROMO_TYPES,
        ]);
    }

    /**
     * `GET add_promo_code/page`
     */
    public function promoAddPage(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'type' => AdminMeta::PROMO_TYPES,
            'products' => AdminMeta::productList(),
            'cycles' => AdminMeta::BILLING_CYCLES,
            'config_options' => DB::table('product_config_options')->get(['id', 'option_name as name'])->toArray(),
            'type_upgrade' => [['value' => 1, 'name' => '是'], ['value' => 0, 'name' => '否']],
        ] + $this->envelope());
    }

    /**
     * `GET save_promo_code/page {id}`
     */
    public function promoEditPage(Request $request)
    {
        $promo = PromoCode::query()->find((int) $request->input('id', 0));

        if ($promo === null) {
            return $this->notFound('优惠码不存在');
        }

        $row = $promo->toArray();
        $row['appliesto'] = $this->csvToArray($promo->appliesto);
        $row['requires'] = $this->csvToArray($promo->requires);
        $row['cycles'] = $this->csvToArray($promo->cycles);

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'data' => $row,
            'type' => AdminMeta::PROMO_TYPES,
            'products' => AdminMeta::productList(),
            'cycles' => AdminMeta::BILLING_CYCLES,
            'config_options' => DB::table('product_config_options')->get(['id', 'option_name as name'])->toArray(),
            'type_upgrade' => [['value' => 1, 'name' => '是'], ['value' => 0, 'name' => '否']],
        ] + $this->envelope());
    }

    /**
     * `POST add_promo_code`
     */
    public function promoCreate(Request $request)
    {
        return $this->writePromo($request, 0);
    }

    /**
     * `POST save_promo_code`
     */
    public function promoUpdate(Request $request)
    {
        return $this->writePromo($request, (int) $request->input('id', 0));
    }

    /**
     * `POST expired_promo_code {id}` — disable a code by back-dating it.
     */
    public function promoExpire(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        PromoCode::query()->whereIn('id', $ids)->update(['expiration_time' => time()]);

        return $this->ok(['ids' => array_values($ids)], '操作成功');
    }

    /**
     * `POST delete_promo_code {id}`
     */
    public function promoDelete(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        PromoCode::query()->whereIn('id', $ids)->delete();

        return $this->ok(['ids' => array_values($ids)], '删除成功');
    }

    // -----------------------------------------------------------------
    // 货币
    // -----------------------------------------------------------------

    /**
     * `GET currency/currency_list`
     */
    public function currencyList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('currencies');
        $total = (clone $query)->count();
        $rows = $query->orderBy('id')->forPage($page, $limit)->get();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'total' => $total,
            'totalPage' => (int) ceil($total / max(1, $limit)),
            'currencies' => $rows->map(fn ($r) => (array) $r)->all(),
        ] + $this->envelope());
    }

    /**
     * `POST currency/create`
     */
    public function currencyCreate(Request $request)
    {
        $code = strtoupper(trim((string) $request->input('code', '')));

        if ($code === '') {
            return $this->validationFail('货币代码不能为空');
        }

        if (DB::table('currencies')->where('code', $code)->exists()) {
            return $this->fail('该货币代码已存在');
        }

        $id = (int) DB::table('currencies')->insertGetId([
            'code' => $code,
            'prefix' => (string) $request->input('prefix', ''),
            'suffix' => (string) $request->input('suffix', ''),
            'format' => (string) $request->input('format', '1'),
            'rate' => (float) $request->input('rate', 1),
            'default' => 0,
        ]);

        $this->log('添加货币：'.$code, $id);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `POST currency/update`
     */
    public function currencyUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('currencies')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('货币不存在');
        }

        $data = [];

        foreach (['code', 'prefix', 'suffix', 'format'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        if ($request->has('rate')) {
            $data['rate'] = (float) $request->input('rate');
        }

        if ($data !== []) {
            DB::table('currencies')->where('id', $id)->update($data);
        }

        $this->log('编辑货币 #'.$id, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `POST currency/update_rate` — set one currency's rate and rescale prices.
     */
    public function currencyUpdateRate(Request $request)
    {
        $rates = $request->input('data', $request->input('rates', []));

        if (! is_array($rates) || $rates === []) {
            // A single-currency update posted flat.
            $id = (int) $request->input('id', 0);

            if ($id > 0 && $request->has('rate')) {
                $rates = [['id' => $id, 'rate' => $request->input('rate')]];
            }
        }

        foreach ($rates as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            DB::table('currencies')->where('id', (int) $row['id'])->update([
                'rate' => (float) ($row['rate'] ?? 1),
            ]);
        }

        $this->log('更新货币汇率');

        return $this->respond([
            'status' => ApiResponse::OK,
            'data' => DB::table('currencies')->orderBy('id')->get()->map(fn ($c) => ['id' => (int) $c->id, 'rate' => (float) $c->rate])->all(),
            'msg' => '更新成功',
        ] + $this->envelope());
    }

    /**
     * `POST currency/update_price` — re-scale every price for a currency.
     */
    public function currencyUpdatePrice(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->validationFail('请选择货币');
        }

        $rate = (float) (DB::table('currencies')->where('id', $id)->value('rate') ?? 1);

        if ($rate <= 0) {
            return $this->validationFail('汇率必须大于0');
        }

        // Re-derive this currency's pricing rows from the default currency.
        $defaultId = (int) (DB::table('currencies')->orderBy('id')->value('id') ?? 1);
        $defaultRate = (float) (DB::table('currencies')->where('id', $defaultId)->value('rate') ?? 1);
        $factor = $defaultRate > 0 ? $rate / $defaultRate : $rate;

        $updated = 0;

        foreach (DB::table('pricing')->where('currency', $defaultId)->get() as $row) {
            $columns = array_merge(
                AdminMeta::CYCLE_COLUMNS,
                array_values(AdminMeta::SETUP_COLUMNS),
            );

            $data = [];

            foreach ($columns as $column) {
                $value = (float) ($row->{$column} ?? -1);

                if ($value < 0) {
                    $data[$column] = -1;

                    continue;
                }

                $data[$column] = $this->money($value * $factor);
            }

            $exists = DB::table('pricing')
                ->where('type', $row->type)
                ->where('relid', $row->relid)
                ->where('currency', $id)
                ->exists();

            if ($exists) {
                DB::table('pricing')
                    ->where('type', $row->type)
                    ->where('relid', $row->relid)
                    ->where('currency', $id)
                    ->update($data);
            } else {
                DB::table('pricing')->insert($data + [
                    'type' => $row->type,
                    'relid' => $row->relid,
                    'currency' => $id,
                ]);
            }

            $updated++;
        }

        $this->log('按汇率 '.$factor.' 更新货币价格');

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '更新价格成功',
            'count' => $updated,
        ] + $this->envelope());
    }

    /**
     * `POST currency/default` — switch the default currency.
     */
    public function currencyDefault(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->validationFail('ID错误');
        }

        DB::table('currencies')->update(['default' => 0]);
        DB::table('currencies')->where('id', $id)->update(['default' => 1]);

        SettingService::putMany(['default_currency' => $id]);

        return $this->ok(['id' => $id], '操作成功');
    }

    /**
     * `DELETE currency/<id>`
     */
    public function currencyDelete(Request $request, $id)
    {
        $id = (int) $id;

        if (DB::table('currencies')->count() <= 1) {
            return $this->fail('至少需要保留一种货币');
        }

        DB::transaction(function () use ($id) {
            DB::table('currencies')->where('id', $id)->delete();
            DB::table('pricing')->where('currency', $id)->delete();
        });

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Generic getter/setter for a `SettingService::GROUPS` tab.
     */
    private function tab(Request $request, string $group)
    {
        if ($request->isMethod('post')) {
            SettingService::saveGroup($group, $request->all());

            // JSON-shaped values are posted as arrays.
            foreach ($request->all() as $key => $value) {
                if (is_array($value)) {
                    SettingService::putMany([$key => $value]);
                }
            }

            $this->log('保存设置：'.$group);

            return $this->ok(null, '保存成功');
        }

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'data' => SettingService::group($group),
        ] + $this->envelope());
    }

    /**
     * JSON-decode the list-shaped keys of a settings group.
     */
    private function decodeLists(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (! isset($data[$key])) {
                continue;
            }

            $value = $data[$key];

            if (is_array($value)) {
                continue;
            }

            $decoded = json_decode((string) $value, true);

            if (is_array($decoded)) {
                $data[$key] = $decoded;
            } elseif ((string) $value !== '') {
                $data[$key] = array_values(array_filter(array_map('trim', explode(',', (string) $value))));
            } else {
                $data[$key] = [];
            }
        }

        return $data;
    }

    /**
     * Create/update a promo code.
     */
    private function writePromo(Request $request, int $id)
    {
        $code = strtoupper(trim((string) $request->input('code', '')));

        if ($code === '') {
            return $this->validationFail('优惠码不能为空');
        }

        $exists = PromoCode::query()->where('code', $code)->when($id > 0, fn ($q) => $q->whereKeyNot($id))->exists();

        if ($exists) {
            return $this->fail('该优惠码已存在');
        }

        $data = [
            'code' => $code,
            'type' => (string) $request->input('type', 'percent'),
            'recurring' => (int) $request->input('recurring', 0),
            'value' => $this->money((float) $request->input('value', 0)),
            'cycles' => $this->csvValue($request->input('cycles')),
            'appliesto' => $this->csvValue($request->input('appliesto')),
            'requires' => $this->csvValue($request->input('requires')),
            'requires_exist' => (int) $request->input('requires_exist', 0),
            'start_time' => $this->timestamp($request->input('start_time')) ?? 0,
            'expiration_time' => $this->timestamp($request->input('expiration_time')) ?? 0,
            'max_times' => (int) $request->input('max_times', 0),
            'lifelong' => (int) $request->input('lifelong', 0),
            'one_time' => (int) $request->input('one_time', 0),
            'only_new_client' => (int) $request->input('only_new_client', 0),
            'only_old_client' => (int) $request->input('only_old_client', 0),
            'once_per_client' => (int) $request->input('once_per_client', 0),
            'upgrades' => (int) $request->input('upgrades', 0),
            'upgrade_config' => $this->csvValue($request->input('upgrade_config')),
            'is_discount' => (int) $request->input('is_discount', 0),
            'notes' => (string) $request->input('notes', ''),
        ];

        if ($data['type'] === 'percent' && $data['value'] > 100) {
            return $this->validationFail('折扣百分比不能大于100');
        }

        if ($id > 0) {
            PromoCode::query()->whereKey($id)->update($data);
            $this->log('编辑优惠码：'.$code, $id);

            return $this->ok(['id' => $id], '保存成功');
        }

        $data['used'] = 0;
        $promo = PromoCode::query()->create($data);

        $this->log('添加优惠码：'.$code, (int) $promo->id);

        return $this->ok(['id' => (int) $promo->id], '添加成功');
    }

    /**
     * Normalise a multi-select into the comma-padded CSV the schema stores.
     */
    private function csvValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);
        $items = array_values(array_filter(array_map('trim', $items), fn ($v) => $v !== ''));

        return $items === [] ? '' : ','.implode(',', $items).',';
    }

    /**
     * The email template types the panel can create.
     */
    private function emailTypes(): array
    {
        return [
            ['value' => 'client_created', 'name' => '客户注册成功'],
            ['value' => 'welcome', 'name' => '产品开通通知'],
            ['value' => 'invoice_paid', 'name' => '账单支付通知'],
            ['value' => 'invoice_unpaid', 'name' => '账单未支付提醒'],
            ['value' => 'invoice_overdue', 'name' => '账单逾期提醒'],
            ['value' => 'host_suspended', 'name' => '产品暂停通知'],
            ['value' => 'host_unsuspended', 'name' => '产品解除暂停通知'],
            ['value' => 'host_terminated', 'name' => '产品删除通知'],
            ['value' => 'ticket_reply', 'name' => '工单回复通知'],
            ['value' => 'password_reset', 'name' => '密码重置'],
            ['value' => 'recharge_success', 'name' => '充值成功'],
            ['value' => 'withdraw_audit', 'name' => '提现审核结果'],
        ];
    }

    /**
     * The replaceable placeholders available in a template type.
     */
    private function emailParams(string $type): array
    {
        $common = [
            ['name' => '{company_name}', 'description' => '公司名称'],
            ['name' => '{system_url}', 'description' => '系统地址'],
        ];

        $byType = [
            'client_created' => [['name' => '{username}', 'description' => '客户姓名']],
            'invoice_paid' => [
                ['name' => '{invoice_num}', 'description' => '账单编号'],
                ['name' => '{total}', 'description' => '账单金额'],
            ],
            'invoice_unpaid' => [
                ['name' => '{invoice_num}', 'description' => '账单编号'],
                ['name' => '{due_time}', 'description' => '到期时间'],
            ],
            'host_suspended' => [['name' => '{domain}', 'description' => '主机名']],
            'ticket_reply' => [['name' => '{title}', 'description' => '工单标题']],
        ];

        return array_merge($common, $byType[$type] ?? []);
    }

    /**
     * Placeholder arguments offered by the SMS template editor.
     */
    private function smsArgs(): array
    {
        return [
            ['name' => 'code', 'description' => '验证码'],
            ['name' => 'time', 'description' => '有效时间'],
            ['name' => 'company', 'description' => '公司名称'],
        ];
    }

    /**
     * The actions a second-verify code can gate.
     */
    private function secondVerifyActions(): array
    {
        return [
            ['value' => 'on', 'name' => '开机'],
            ['value' => 'off', 'name' => '关机'],
            ['value' => 'reboot', 'name' => '重启'],
            ['value' => 'hardOff', 'name' => '强制关机'],
            ['value' => 'hardReboot', 'name' => '强制重启'],
            ['value' => 'crackPass', 'name' => '重置密码'],
            ['value' => 'rescue', 'name' => '救援模式'],
            ['value' => 'vnc', 'name' => 'VNC控制台'],
        ];
    }
}

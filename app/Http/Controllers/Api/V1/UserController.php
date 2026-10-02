<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\PaymentGateway;
use App\Support\PasswordHasher;
use App\Services\VerifyCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Member profile, security centre and real-name (实名认证) verification.
 *
 * Verification submissions are recorded as pending: the original delegates the
 * actual identity check to a third-party provider (Aliyun SMART_FACE by
 * default) and this installation has none bundled, so a submission is stored
 * in `shd_certifi_person` / `shd_certifi_company` with `status = 3`
 * (待审核) for an administrator to clear, and the result is mirrored into
 * `shd_certification`.
 */
class UserController extends ApiController
{
    /** Verification statuses shared by the certification tables. */
    public const CERT_UNVERIFIED = 0;
    public const CERT_VERIFIED = 1;
    public const CERT_FAILED = 2;
    public const CERT_PENDING = 3;
    public const CERT_SUBMITTED = 4;

    /** Scenes the verification-code service uses for profile flows. */
    protected const SCENE_BIND_PHONE = 'bind_phone';
    protected const SCENE_BIND_EMAIL = 'bind_email';
    protected const SCENE_LOGIN_NOTICE = 'login_notice';

    public function __construct(
        protected VerifyCodeService $codes = new VerifyCodeService(),
    ) {
    }

    /* ---------------------------------------------------------------------
     | Profile
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/user — profile plus the custom-field form definition.
     */
    public function show(Request $request)
    {
        $client = $this->requireClient($request);

        return $this->ok(array_merge([
            'id' => (int) $client->id,
            'email' => (string) $client->email,
            'phone_code' => (string) ($client->phone_code ?: ''),
            'phone' => (string) $client->phonenumber,
            'phonenumber' => (string) $client->phonenumber,
            'qq' => (string) $client->qq,
            'username' => (string) $client->username,
            'companyname' => (string) $client->companyname,
            'country' => (string) $client->country,
            'province' => (string) $client->province,
            'city' => (string) $client->city,
            'region' => (string) $client->region,
            'address' => (string) $client->address1,
            'address1' => (string) $client->address1,
            'postcode' => (string) $client->postcode,
            'defaultgateway' => (string) $client->defaultgateway,
            'marketing_emails_opt_in' => (int) $client->marketing_emails_opt_in,
            'send_close' => (int) $client->send_close,
            'credit' => $this->money((float) $client->credit),
            'group_name' => (string) ($client->group?->group_name ?? '默认分组'),
            'create_time' => (int) $client->create_time,
            'avatar' => (string) $client->avatar,
            'certifi' => ['status' => $this->certificationStatus($client)],
        ], [
            'country' => $this->countries(),
            'customs' => $this->customFieldValues($client),
            'gateways' => $this->gateways(),
        ]));
    }

    /**
     * POST /v1/user — update the profile.
     */
    public function update(Request $request)
    {
        $client = $this->requireClient($request);

        $fields = [
            'qq' => 50,
            'username' => 50,
            'companyname' => 80,
            'country' => 100,
            'province' => 100,
            'city' => 100,
            'region' => 100,
            'defaultgateway' => 200,
        ];

        $updates = [];

        foreach ($fields as $field => $maxLength) {
            if (! $request->has($field)) {
                continue;
            }

            $value = trim((string) $request->input($field));

            if (mb_strlen($value) > $maxLength) {
                return $this->fail('字段 ' . $field . ' 长度超出限制', 406);
            }

            $updates[$field] = $value;
        }

        // The address field is spelled `address` in the API and `address1` in
        // the schema.
        if ($request->has('address')) {
            $updates['address1'] = trim((string) $request->input('address'));
        } elseif ($request->has('address1')) {
            $updates['address1'] = trim((string) $request->input('address1'));
        }

        if ($request->has('defaultgateway') && $updates['defaultgateway'] !== '') {
            $exists = PaymentGateway::query()->where('gateway', $updates['defaultgateway'])->exists();

            if (! $exists) {
                return $this->fail('默认支付方式不存在', 406);
            }
        }

        foreach (['marketing_emails_opt_in', 'send_close'] as $flag) {
            if ($request->has($flag)) {
                $updates[$flag] = (int) $request->input($flag) === 1 ? 1 : 0;
            }
        }

        // Custom profile fields posted as `custom[<fieldid>]`.
        $custom = (array) $request->input('custom', $request->input('customfield', []));

        DB::transaction(function () use ($client, $updates, $custom) {
            foreach ($updates as $field => $value) {
                $client->{$field} = $value;
            }

            $client->update_time = time();
            $client->save();

            $this->storeCustomValues($client, $custom);
        });

        return $this->ok(array_merge([
            'id' => (int) $client->id,
        ], $updates), '修改成功');
    }

    /**
     * GET /v1/security_info — security centre data.
     */
    public function securityInfo(Request $request)
    {
        $client = $this->requireClient($request);

        return $this->ok([
            'username' => (string) $client->username,
            'email' => (string) $client->email,
            'phonenumber' => (string) $client->phonenumber,
            'phone_code' => (string) ($client->phone_code ?: ''),
            'email_bound' => (string) $client->email !== '' ? 1 : 0,
            'phone_bound' => (string) $client->phonenumber !== '' ? 1 : 0,
            'login_sms_alert' => (int) $client->is_login_sms_reminder,
            'login_email_alert' => (int) $client->email_remind,
            'second_verify' => (int) $client->second_verify,
            'allow_second_verify' => (int) Configuration::value('second_verify_home', 0) === 1 ? 1 : 0,
            'api_open' => (int) $client->api_open,
            'api_password' => (string) $client->api_password,
            'is_open_credit_limit' => (int) $client->is_open_credit_limit,
            'credit_limit' => $this->money((float) $client->credit_limit),
            'credit_limit_balance' => $this->money((float) $client->credit_limit_balance),
            'credit' => $this->money((float) $client->credit),
            'create_time' => (int) $client->create_time,
            'certifi_status' => $this->certificationStatus($client),
            'certifi_open' => (int) Configuration::value('certifi_open', 0),
            'is_password' => (string) $client->password !== '' ? 1 : 0,
            'sms_country' => $this->phoneCodes(),
            'oauth' => [],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Credentials
     | ------------------------------------------------------------------ */

    /**
     * PUT /v1/password — change the login password.
     */
    public function password(Request $request)
    {
        $client = $this->requireClient($request);

        $old = PasswordHasher::acceptedPlain((string) $request->input('old_password', ''));
        $new = PasswordHasher::acceptedPlain((string) $request->input('new_password', $request->input('password', '')));

        if ($old === '' || $new === '') {
            return $this->fail('原密码和新密码不能为空', 406);
        }

        // Accounts without a password set one instead of changing it.
        if (trim((string) $client->password) !== '' && ! PasswordHasher::checkClient($old, (string) $client->password)) {
            return $this->fail('原密码错误');
        }

        if (! $this->checkCaptcha($request)) {
            return $this->fail('图形验证码错误');
        }

        if (strlen($new) < 6) {
            return $this->fail('密码长度不能少于 6 位', 406);
        }

        if (PasswordHasher::checkClient($new, (string) $client->password)) {
            return $this->fail('新密码不能与原密码相同');
        }

        $client->password = PasswordHasher::client($new);
        $client->update_time = time();
        $client->save();

        return $this->ok(null, '密码修改成功，请重新登录');
    }

    /**
     * PUT /v1/phone_bind — bind or change the phone number.
     */
    public function phoneBind(Request $request)
    {
        $client = $this->requireClient($request);

        $phoneCode = trim((string) $request->input('phone_code', '+86'));
        $phone = trim((string) $request->input('phone', ''));
        $code = trim((string) $request->input('code', ''));

        if ($phone === '' || $code === '') {
            return $this->fail('手机号和验证码不能为空', 406);
        }

        if (! preg_match('/^[0-9]{5,20}$/', $phone)) {
            return $this->fail('手机号格式不正确', 406);
        }

        $taken = Client::query()
            ->where('phonenumber', $phone)
            ->where('id', '<>', $client->id)
            ->exists();

        if ($taken) {
            return $this->fail('该手机号已被其他账号绑定');
        }

        // Verifying the current number is what authorises a change.
        $scene = (string) $client->phonenumber !== '' ? 'change_phone' : self::SCENE_BIND_PHONE;

        if (! $this->codes->consume($scene, $phone, $code)
            && ! $this->codes->consume($scene, $client->phonenumber, $code)) {
            return $this->fail('验证码错误或已过期');
        }

        $client->phonenumber = $phone;
        $client->phone_code = (int) ltrim($phoneCode, '+');
        $client->update_time = time();
        $client->save();

        return $this->ok([
            'phonenumber' => $phone,
            'phone_code' => $phoneCode,
        ], '手机绑定成功');
    }

    /**
     * PUT /v1/email_bind — bind or change the email address.
     */
    public function emailBind(Request $request)
    {
        $client = $this->requireClient($request);

        $email = trim((string) $request->input('email', ''));
        $code = trim((string) $request->input('code', ''));

        if ($email === '' || $code === '') {
            return $this->fail('邮箱和验证码不能为空', 406);
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('邮箱格式不正确', 406);
        }

        $taken = Client::query()
            ->where('email', $email)
            ->where('id', '<>', $client->id)
            ->exists();

        if ($taken) {
            return $this->fail('该邮箱已被其他账号绑定');
        }

        $scene = (string) $client->email !== '' ? 'change_email' : self::SCENE_BIND_EMAIL;

        if (! $this->codes->consume($scene, $email, $code)) {
            return $this->fail('验证码错误或已过期');
        }

        $client->email = $email;
        $client->email_verified = 1;
        $client->update_time = time();
        $client->save();

        return $this->ok(['email' => $email], '邮箱绑定成功');
    }

    /**
     * PUT /v1/login_notice — login SMS / email alerts.
     */
    public function loginNotice(Request $request)
    {
        $client = $this->requireClient($request);

        $type = strtolower((string) $request->input('type', ''));
        $status = (int) $request->input('status', 1) === 1 ? 1 : 0;
        $code = trim((string) $request->input('code', ''));

        if (! in_array($type, ['sms', 'email'], true)) {
            return $this->fail('类型只能是 sms 或 email', 406);
        }

        // Turning an alert off requires proving ownership of the account.
        if ($status === 0) {
            $account = $type === 'sms' ? (string) $client->phonenumber : (string) $client->email;

            if ($code === '' || ! $this->codes->consume(self::SCENE_LOGIN_NOTICE, $account, $code)) {
                return $this->fail('验证码错误或已过期');
            }
        }

        if ($type === 'sms') {
            if ((string) $client->phonenumber === '') {
                return $this->fail('请先绑定手机号');
            }

            $client->is_login_sms_reminder = $status;
        } else {
            if ((string) $client->email === '') {
                return $this->fail('请先绑定邮箱');
            }

            $client->email_remind = $status;
        }

        $client->update_time = time();
        $client->save();

        return $this->ok([
            'type' => $type,
            'status' => $status,
        ], '设置成功');
    }

    /* ---------------------------------------------------------------------
     | Real-name verification
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/real_name_auth — current verification state and form fields.
     */
    public function realNameAuth(Request $request)
    {
        $client = $this->requireClient($request);

        $type = (string) $request->input('type', 'certifi_person');

        if ($type === 'certifi_company') {
            $row = DB::table('certifi_company')->where('auth_user_id', $client->id)->first();
        } else {
            $type = 'certifi_person';
            $row = DB::table('certifi_person')->where('auth_user_id', $client->id)->first();
        }

        $status = $row === null ? self::CERT_UNVERIFIED : (int) $row->status;

        $payload = [
            'type' => $type,
            'status' => $status,
            'certifi_open' => (int) Configuration::value('certifi_open', 0),
            'upload' => (int) Configuration::value('certifi_is_upload', 2),
            'message' => $row === null ? null : $this->certificationPayload($row, $type),
            'method' => [
                'name' => (string) Configuration::value('certifi_type', 'ali'),
                'value' => (string) Configuration::value('certifi_select', 'artificial'),
                'default' => (string) Configuration::value('certifi_select', 'artificial'),
            ],
            'custom_fields' => $this->certificationCustomFields(),
        ];

        return $this->ok($payload);
    }

    /**
     * POST /v1/real_name_auth/person — personal verification.
     */
    public function personRealNameAuth(Request $request)
    {
        $client = $this->requireClient($request);

        $realName = trim((string) $request->input('real_name', ''));
        $idcard = trim((string) $request->input('idcard', ''));

        if ($realName === '') {
            return $this->fail('请填写真实姓名', 406);
        }

        if ($idcard === '') {
            return $this->fail('请填写证件号码', 406);
        }

        $existing = DB::table('certifi_person')->where('auth_user_id', $client->id)->first();

        if ($existing !== null && (int) $existing->status === self::CERT_VERIFIED) {
            return $this->fail('该账号已完成实名认证');
        }

        $attributes = [
            'auth_user_id' => (int) $client->id,
            'auth_real_name' => $realName,
            'auth_card_type' => (int) $request->input('card_type', 1),
            'auth_card_number' => $idcard,
            'status' => self::CERT_PENDING,
            'img_one' => (string) $request->input('img_one', ''),
            'img_two' => (string) $request->input('img_two', ''),
            'img_three' => (string) $request->input('img_three', ''),
            'certify_id' => '',
            'auth_fail' => '',
            'update_time' => time(),
            'phone' => (string) $request->input('phone', $client->phonenumber),
            'bank' => (string) $request->input('bank', ''),
        ];

        foreach (range(1, 10) as $index) {
            $attributes['custom_fields' . $index] = (string) $request->input('custom_fields' . $index, '');
        }

        DB::transaction(function () use ($attributes, $client, $existing) {
            if ($existing === null) {
                $attributes['create_time'] = time();
                DB::table('certifi_person')->insert($attributes);
            } else {
                DB::table('certifi_person')
                    ->where('auth_user_id', $client->id)
                    ->update($attributes);
            }

            $this->mirrorCertification($client, 'person', $attributes);
            $this->logCertification($client, $attributes);
        });

        return $this->ok([
            'invoice_id' => 0,
            'status' => self::CERT_PENDING,
        ], '实名认证资料已提交，请等待审核');
    }

    /**
     * POST /v1/real_name_auth/company — company verification.
     */
    public function companyRealNameAuth(Request $request)
    {
        $client = $this->requireClient($request);

        $companyName = trim((string) $request->input('company_name', ''));
        $organCode = trim((string) $request->input('company_organ_code', ''));
        $realName = trim((string) $request->input('real_name', ''));
        $idcard = trim((string) $request->input('idcard', ''));

        if ($companyName === '') {
            return $this->fail('请填写企业名称', 406);
        }

        if ($organCode === '') {
            return $this->fail('请填写统一社会信用代码', 406);
        }

        if ($realName === '' || $idcard === '') {
            return $this->fail('请填写法人姓名与证件号码', 406);
        }

        $existing = DB::table('certifi_company')->where('auth_user_id', $client->id)->first();

        if ($existing !== null && (int) $existing->status === self::CERT_VERIFIED) {
            return $this->fail('该账号已完成企业认证');
        }

        $attributes = [
            'auth_user_id' => (int) $client->id,
            'auth_real_name' => $realName,
            'auth_card_type' => (int) $request->input('card_type', 1),
            'auth_card_number' => $idcard,
            'company_name' => $companyName,
            'company_organ_code' => $organCode,
            'img_one' => (string) $request->input('img_one', ''),
            'img_two' => (string) $request->input('img_two', ''),
            'img_three' => (string) $request->input('img_three', ''),
            'img_four' => (string) $request->input('img_four', ''),
            'status' => self::CERT_PENDING,
            'certify_id' => '',
            'auth_fail' => '',
            'update_time' => time(),
            'bank' => (string) $request->input('bank', ''),
            'phone' => (string) $request->input('phone', $client->phonenumber),
        ];

        foreach (range(1, 10) as $index) {
            $attributes['custom_fields' . $index] = (string) $request->input('custom_fields' . $index, '');
        }

        DB::transaction(function () use ($attributes, $client, $existing, $companyName) {
            if ($existing === null) {
                $attributes['create_time'] = time();
                DB::table('certifi_company')->insert($attributes);
            } else {
                DB::table('certifi_company')
                    ->where('auth_user_id', $client->id)
                    ->update($attributes);
            }

            $this->mirrorCertification($client, 'company', $attributes);
            $this->logCertification($client, $attributes);
            $this->certificationResult($client, '企业认证资料已提交，等待审核');
        });

        return $this->ok([
            'invoice_id' => 0,
            'status' => self::CERT_PENDING,
        ], '企业认证资料已提交，请等待审核');
    }

    /**
     * GET /v1/real_name_auth/status — poll the verification result.
     */
    public function realNameAuthStatus(Request $request)
    {
        $client = $this->requireClient($request);
        $type = (string) $request->input('type', 'certifi_person');
        $table = $type === 'certifi_company' ? 'certifi_company' : 'certifi_person';

        $row = DB::table($table)->where('auth_user_id', $client->id)->first();

        if ($row === null) {
            return $this->ok([
                'status' => self::CERT_UNVERIFIED,
                'type' => $type,
                'auth_fail' => '',
            ]);
        }

        $result = DB::table('certifi_result')
            ->where('auth_user_id', $client->id)
            ->orderByDesc('id')
            ->first();

        return $this->ok([
            'status' => (int) $row->status,
            'type' => $type,
            'auth_fail' => (string) $row->auth_fail,
            'certify_id' => (string) $row->certify_id,
            'description' => (string) ($result->description ?? ''),
            'update_time' => (int) $row->update_time,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    /**
     * Verification status surfaced on the profile / security payloads.
     */
    protected function certificationStatus(Client $client): int
    {
        $row = DB::table('certification')->where('client_id', $client->id)->first();

        if ($row !== null) {
            return (int) $row->status;
        }

        $person = DB::table('certifi_person')->where('auth_user_id', $client->id)->first();

        if ($person !== null) {
            return (int) $person->status;
        }

        $company = DB::table('certifi_company')->where('auth_user_id', $client->id)->first();

        return $company === null ? self::CERT_UNVERIFIED : (int) $company->status;
    }

    /**
     * Verification row in the API's field names.
     */
    protected function certificationPayload(object $row, string $type): array
    {
        $payload = [
            'type' => $type,
            'status' => (int) $row->status,
            'auth_real_name' => (string) $row->auth_real_name,
            'auth_card_type' => (int) $row->auth_card_type,
            'auth_card_number' => (string) $row->auth_card_number,
            'img_one' => (string) $row->img_one,
            'img_two' => (string) $row->img_two,
            'img_three' => (string) $row->img_three,
            'img_four' => (string) ($row->img_four ?? ''),
            'certify_id' => (string) $row->certify_id,
            'auth_fail' => (string) $row->auth_fail,
            'create_time' => (int) $row->create_time,
            'update_time' => (int) $row->update_time,
            'phone' => (string) $row->phone,
            'bank' => (string) $row->bank,
        ];

        for ($index = 1; $index <= 10; $index++) {
            $payload['custom_fields' . $index] = (string) ($row->{'custom_fields' . $index} ?? '');
        }

        if ($type === 'certifi_company') {
            $payload['company_name'] = (string) $row->company_name;
            $payload['company_organ_code'] = (string) $row->company_organ_code;
        }

        return $payload;
    }

    /**
     * Mirror a submission into `shd_certification`, the legacy summary row the
     * client-area security page reads.
     */
    protected function mirrorCertification(Client $client, string $kind, array $attributes): void
    {
        $values = [
            'client_id' => (int) $client->id,
            'idcard' => (string) ($attributes['auth_card_number'] ?? ''),
            'name' => (string) ($attributes['auth_real_name'] ?? ''),
            'id_image_address' => json_encode(array_values(array_filter([
                $attributes['img_one'] ?? '',
                $attributes['img_two'] ?? '',
                $attributes['img_three'] ?? '',
                $attributes['img_four'] ?? '',
            ])), JSON_UNESCAPED_UNICODE),
            'type' => $kind === 'company' ? 2 : 1,
            'companyname' => (string) ($attributes['company_name'] ?? ''),
            'businesslicense' => (string) ($attributes['img_three'] ?? ''),
            'status' => self::CERT_PENDING,
        ];

        $existing = DB::table('certification')->where('client_id', $client->id)->first();

        if ($existing === null) {
            DB::table('certification')->insert($values);

            return;
        }

        DB::table('certification')->where('client_id', $client->id)->update($values);
    }

    /**
     * Audit trail entry for a submission.
     */
    protected function logCertification(Client $client, array $attributes): void
    {
        DB::table('certifi_log')->insert([
            'uid' => (int) $client->id,
            'certifi_name' => (string) ($attributes['auth_real_name'] ?? ''),
            'company_name' => (string) ($attributes['company_name'] ?? ''),
            'card_type' => (int) ($attributes['auth_card_type'] ?? 1),
            'idcard' => (string) ($attributes['auth_card_number'] ?? ''),
            'company_organ_code' => (string) ($attributes['company_organ_code'] ?? ''),
            'error' => '',
            'pic' => '',
            'create_time' => time(),
            'status' => self::CERT_PENDING,
            'type' => 1,
            'bank' => (string) ($attributes['bank'] ?? ''),
            'phone' => (string) ($attributes['phone'] ?? ''),
            'certifi_type' => (string) Configuration::value('certifi_type', 'ali'),
            'custom_fields_log' => '',
            'notes' => '客户端提交实名认证资料',
        ]);
    }

    /**
     * Human-readable result row for the polling endpoint.
     */
    protected function certificationResult(Client $client, string $description): void
    {
        DB::table('certifi_result')->insert([
            'auth_user_id' => (int) $client->id,
            'description' => $description,
            'create_time' => time(),
        ]);
    }

    /**
     * Custom fields offered on the verification form.
     */
    protected function certificationCustomFields(): array
    {
        $raw = Configuration::json('certifi_select', []);

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $raw */
        return array_map(fn (array $field) => [
            'title' => (string) ($field['title'] ?? ''),
            'field' => (string) ($field['field'] ?? ''),
            'type' => (string) ($field['type'] ?? 'text'),
            'value' => (string) ($field['value'] ?? ''),
            'tip' => (string) ($field['tip'] ?? ''),
            'required' => (bool) ($field['required'] ?? false),
        ], array_values(array_filter($raw, 'is_array')));
    }

    /**
     * Client custom fields with their stored values.
     */
    protected function customFieldValues(Client $client): array
    {
        $fields = CustomField::query()
            ->where('type', 'client')
            ->where('adminonly', 0)
            ->orderBy('sortorder')
            ->get();

        if ($fields->isEmpty()) {
            return [];
        }

        $values = CustomFieldValue::query()
            ->where('relid', $client->id)
            ->whereIn('fieldid', $fields->pluck('id')->all())
            ->pluck('value', 'fieldid');

        return $fields->map(fn (CustomField $field) => [
            'id' => (int) $field->id,
            'fieldname' => (string) $field->fieldname,
            'fieldtype' => (string) $field->fieldtype,
            'description' => (string) $field->description,
            'fieldoptions' => $field->options(),
            'options' => $field->options(),
            'required' => (int) $field->required,
            'value' => (string) ($values[$field->id] ?? ''),
        ])->values()->all();
    }

    /**
     * Persist `custom[<fieldid>]` input.
     *
     * @param  array<int|string, mixed>  $values
     */
    protected function storeCustomValues(Client $client, array $values): void
    {
        if ($values === []) {
            return;
        }

        $fields = CustomField::query()
            ->where('type', 'client')
            ->whereIn('id', array_map('intval', array_keys($values)))
            ->get()
            ->keyBy('id');

        foreach ($values as $fieldId => $value) {
            $field = $fields->get((int) $fieldId);

            if ($field === null) {
                continue;
            }

            $stored = is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;

            if ($field->fieldtype === CustomField::TYPE_PASSWORD && $stored !== '') {
                $stored = md5($stored);
            }

            $existing = CustomFieldValue::query()
                ->where('fieldid', (int) $field->id)
                ->where('relid', $client->id)
                ->first();

            if ($existing === null) {
                CustomFieldValue::create([
                    'fieldid' => (int) $field->id,
                    'relid' => (int) $client->id,
                    'value' => $stored,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                continue;
            }

            $existing->value = $stored;
            $existing->update_time = time();
            $existing->save();
        }
    }

    /**
     * Countries offered by the billing-address form.
     */
    protected function countries(): array
    {
        return DB::table('sms_country')
            ->orderBy('name')
            ->get(['iso', 'name', 'name_zh', 'phone_code'])
            ->map(fn ($row) => [
                'iso' => (string) $row->iso,
                'name' => (string) $row->name,
                'name_zh' => (string) $row->name_zh,
                'phone_code' => '+' . (int) $row->phone_code,
            ])
            ->values()
            ->all();
    }

    protected function phoneCodes(): array
    {
        return DB::table('sms_country')
            ->where('phone_code', '<', 1000)
            ->orderBy('phone_code')
            ->limit(60)
            ->get(['name_zh', 'phone_code'])
            ->map(fn ($row) => [
                'link' => (string) $row->name_zh,
                'phone_code' => '+' . (int) $row->phone_code,
            ])
            ->values()
            ->all();
    }

    protected function gateways(): array
    {
        return PaymentGateway::query()
            ->orderBy('order')
            ->get()
            ->map(fn (PaymentGateway $gateway) => [
                'name' => (string) $gateway->gateway,
                'title' => $gateway->displayName(),
            ])
            ->values()
            ->all();
    }

    /**
     * Graphic captcha check, skipped when the caller sent neither field.
     */
    protected function checkCaptcha(Request $request): bool
    {
        $captcha = (string) $request->input('captcha', '');
        $idtoken = (string) $request->input('idtoken', '');

        if ($captcha === '' && $idtoken === '') {
            return true;
        }

        if ($captcha === '' || $idtoken === '') {
            return false;
        }

        $expected = Cache::pull('captcha:' . $idtoken);

        return $expected !== null && strtoupper($captcha) === strtoupper((string) $expected);
    }
}

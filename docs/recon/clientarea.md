# 智简魔方财务 (ZJMF / IDCSmart Finance) v3.7.6 — Client Area Spec

Source of truth: live templates at `/www/wwwroot/mfcw.782778.xyz/public/themes/clientarea/default/`
and `/www/wwwroot/mfcw.782778.xyz/public/themes/cart/default/`, route table `docs/recon/routes.tsv`,
language pack `/www/wwwroot/mfcw.782778.xyz/public/language/chinese.php`.

Original stack: ThinkPHP 5.1, ionCube-encrypted `app/`. Controllers relevant to the client area live in
namespace `home\` (501 routes in `routes.tsv`; also `openapi\` = the REST API, `admin\` = 后台).

## 0. Conventions that apply to every page

### 0.1 Routing
Two shapes coexist in `routes.tsv`:

1. **Explicit routes** — `get|post <url> home/<Controller>/<action>`. E.g. `get  details  home/viewClients/details`.
2. **`?action=` multiplexing** — one URL dispatched inside the controller by the `action` query string.
   The classic client-area pages use this: `/clientarea`, `/service`, `/servicedetail`, `/billing`,
   `/transaction`, `/message`, `/affiliates`, `/invoicelist`, `/supporttickets`, `/submitticket`,
   `/viewticket`, `/downloads`, `/cart`, `/pay`, `/verified`, `/news`, `/knowledgebase`.
   Templates read it as `{$Think.get.action}` and branch with `{if $Think.get.action=='…'}`.

   Note `/servicedetail?id=N&action=renew` is **not** in `routes.tsv` as a distinct URL: it is the same
   `get servicedetail home/viewClients/servicedetail` route, with `action` handled inside the controller
   (hence the `{if $Think.get.action=='…'}` pattern in templates is the only reliable documentation of
   the sub-actions).

   `viewbilling` is the one exception with different casing: `get viewbilling  home/viewClients/ViewBilling`.

3. **Catch-all generic CRUD** (`routes.tsv` lines ~1149-1153) which is how `/host/remark`, `/host/cancel`,
   `/host/autorenew`, `/host/trafficusage`, `/host/dedicatedserver`, `/host/hostrecharge`,
   `/host/batchrenewpage`, `/voucher/issuevoucher`, `/news/…` are served:
   ```
   get    host/<action>  get<action>
   post   host/<action>  post<action>
   put    host/<action>  put<action>
   delete host/<action>  delete<action>
   patch  host/<action>  patch<action>
   ```
   The generic controller name `get<action>`/`post<action>` is what the route dump reports, i.e. the
   actual handler is resolved dynamically. Also `/news/<action>`, `/voucher/<action>` follow the same shape.

### 0.2 Response envelope
Almost every AJAX endpoint returns JSON with:
```json
{ "status": 200, "msg": "…", "data": { … } }
```
- Success test seen in templates: `data.status == 200` (also `res.status == '200'` — the value is sometimes
  serialized as a string).
- `status 400` / `406` are used for "pre-check failed" (`addfunds`), with the message in `msg`.
- `status 1001` is a soft success (e.g. `/dcim/buy_flow_packet`, `/dcim/buy_reinstall_times` — "already free,
  nothing to pay"), template does `toastr.success(res.msg)`.
- `status 1000` is the pay-status poll result in `/check_order` (`pay.tpl`), see §7.5.
- Pagination is server-rendered (not JSON) for list pages: `{$Total}`, `{$Pages}`, `{$Limit}`, `{$Page}`
  and query params `keywords`, `sort`, `orderby`, `page`, `limit`.

### 0.3 Auth / CSRF-ish tokens
- Every POSTed form includes a hidden anti-tamper `token` (`{$Token}`). Examples: `login.tpl`,
  `register` (no), `includes/modal.tpl`, `security.tpl`, `includes/cancelrequire.tpl`, `viewticket.tpl`.
- `{$Setting.msfntk}` (`var mk = '…'` on login/register/pwreset/bind/cart pages) is a separate
  send-SMS/email-code signature sent as `mk` in code-sending requests.
- Login passwords are AES-encrypted client side before submit (see §1.1).
- 二次验证 (second verify): a modal + endpoint family, see §9.6.

---

## 1. Login / Register / Password reset / Bind

### 1.1 Login
- **URLs**: `get|post login` → `home/viewClients/login`.
- **Template**: `themes/clientarea/default/login.tpl`
  (backup copy `login - 副本.tpl`, differs only by missing `id="tab-email"/"tab-phone"` attributes).
- **Tab variants** controlled by `$Login.*`:
  - `allow_login_phone`, `allow_login_email`, `allow_id` (login by numeric ID) → which tabs render.
  - `allow_login_phone_captcha`, `allow_login_code_captcha`, `allow_login_email_captcha` combined with
    `$Login.is_captcha` → whether `includes/verify` (图形验证码) renders for each tab.
  - `allow_login_register_sms_global == 1` → renders the international `phone_code` country `<select>`
    populated from `$SmsCountry` (`{foreach $SmsCountry as $list}<option value="{$list.phone_code}">{$list.link}</option>`).
  - `second_verify_action_home_login == 1` → the submit button switches to `onclick="loginBefore('email'|'phone')"`.
- **Forms**:
  - Email tab: `POST /login?action=email`, fields `email`, `password`, optional `captcha`,
    `onsubmit="return encryptPass('emailPwdInp')"`.
  - Phone tab: `POST /login?action=phone` (password mode) or `/login?action=phone_code` (SMS-code mode,
    switched client side by `phoneCheck()`); fields `phone_code`, `phone`, `password` or `code`, `captcha`.
  - On success with second-verify enabled, the code is appended client side to the form as hidden inputs
    `code` and `code_type` and the form is submitted (`public.js` `#secondVerifySubmit` handler).
- **Encryption**: `assets/js/public.js`
  ```js
  CryptoJS.AES.encrypt(str, CryptoJS.enc.Utf8.parse("idcsmart.finance"),
    { mode: CBC, padding: Pkcs7, iv: CryptoJS.enc.Utf8.parse("9311019310287172") })
  ```
  i.e. AES-128-CBC, key `idcsmart.finance`, IV `9311019310287172`, output Base64. Applied to `password`
  on login, register and pwreset.
- **Endpoints used on this page**:
  | Method | URL | Params |
  |---|---|---|
  | POST | `login_send` | `mk`, `phone`, `phone_code`, `captcha`, optional `captcha_randstr`, `captcha_token` |
  | GET | `/login/second_verify_page` | `username`, `password`, `captcha` |
  | POST | `/login/second_verify_send` | `action: "login"`, `type`, `username`, `password` |
  | GET | `/verify?name=<type>` | returns PNG bytes (fetched as arraybuffer, turned into a data: URL) |
- **OAuth buttons**: `{foreach $Oauth as $list}<a href="{$list.url}" target="blank">{$list.img}</a>`.
  Related routes: `get oauth/url/<dirName?>  home/oauth/url`, `get oauth/callback/<dirName?>`, `get oauthBind`
  → `home/oauthBind/listing`, `post oauthBind/bind/<dirName?>` (unbind: `post oauthBind/untie/<dirName?>`).
- **WeChat login**: `get wechat_login` → `home/wechat/index`; `get get_wechat_config` → `home/wechat/get_wechat_config`;
  `get wechat_login_handle` → `home/wechat/login_handle`.
- `loginaccesstoken.tpl` — same UI, but the form action is `/loginAccessToken?action=email|phone|phone_code`
  and it always includes `<input type="hidden" name="redirect_url" value="{$redirect_url}">`.
  Route: `* loginAccessToken  home/ViewClients/loginAccessToken`.
- `app.js` has a special case: `logout` at top right is `{$Setting …}`-driven; route `get|post logout → home/viewClients/logout`,
  also `get logOut home/user/logOut`.

### 1.2 Register
- **URLs**: `get|post register` → `home/viewClients/register`; dedicated JSON senders under `home/register/`.
- **Template**: `register.tpl`.
- Variants: `$Register.allow_register_phone` / `allow_register_email`; `allow_email_register_code == 1`
  gates the email verification-code box; `$Verify.allow_register_email_captcha` / `allow_register_phone_captcha`.
- **Forms**: `POST /register?action=email` (`email`, `code`, `password`, `checkPassword`, custom fields)
  and `POST /register?action=phone` (`phone_code`, `phone`, `code`, `password`, `checkPassword`).
  Both call `encryptPass` on the password inputs.
- **Custom client fields**: `{foreach $Register.fields as $k => $list}` rendered as `fields[{$list.id}]`
  with `fieldtype` in `dropdown | password | text | link | tickbox | textarea`. Labels come from
  `$list.fieldname`; dropdown options from `$list.dropdown_option`.
- **Required-by-config fields**: `{foreach $Register.login_register_custom_require as $custom}` uses literal
  input names `{$custom.name}` with labels from `$Register[login_register_custom_require_list][$custom.name]`.
- **Sales rep select** (only when `$setsaler == '2'`): `<select name="sale_id">` options `{$list.id}` / `{$list.user_nickname}`,
  `0` = 无.
- **TOS gate**: `#agreePrivacy` checkbox + `beforeSubmit()`; copy from `{$Lang.have_read_agree}`,
  links `{$Setting.web_tos_url}` / `{$Setting.web_privacy_url}`.
- **Endpoints** (all POST, all from `assets/js/public.js getCode()`):
  `register_email_send` (`mk`,`email`,`captcha`), `register_phone_send` (`mk`,`phone`,`phone_code`,`captcha`).
  These map to `home/register/registerEmailSend`, `home/register/registerPhoneSend`.
  There are also direct POST variants `register_email` / `register_phone` (`home/register/registerEmail|registerPhone`).

### 1.3 Password reset
- **URLs**: `get|post pwreset` → `home/viewClients/pwreset`.
- **Template**: `pwreset.tpl`. Tabs `{$Pwreset.allow_login_phone}` / `{$Pwreset.allow_login_email}`.
- Forms `POST /pwreset?action=email` (`email`, `code`, `password`, `checkPassword`, `captcha`) and
  `POST /pwreset?action=phone` (`phone_code`, `phone`, `code`, `password`, `checkPassword`, `captcha`).
- Captcha flags: `$Verify.allow_email_forgetpwd_captcha`, `$Verify.allow_phone_forgetpwd_captcha`
  (`type="allow_email_forgetpwd_captcha"` in `includes/verify`).
- Code senders: `reset_email_send`, `reset_phone_send` → `home/register/resetEmailSend`,
  `home/register/resetPhoneSend`; reset submit routes `reset_email` / `reset_phone` →
  `home/register/passEmailReset` / `home/register/passPhoneReset`.
- Success redirects to `/clientarea` via `{include file="error/notifications" url="/clientarea"}`.

### 1.4 Third-party bind (callback) page
- **URL**: `get|post bind` → `home/viewClients/bind`. Template `bind.tpl`.
- Variant selector: `$CallbackInfo` — `0` = show both tabs, `1` = phone only, `2` = email only.
- Forms: `POST /bind?action=email` and `POST /bind?action=phone`, both with `phone_code`/`email` + `code`.
- Code senders via `getCode()`: `oauth/bind_email_send` (`home/oauth/bindEmailSend`),
  `oauth/bind_phone_send` (`home/oauth/bindPhoneSend`). Also OAuth-specific routes
  `post oauth/bind_login_email` (`home/oauth/bindLoginEmail`), `post oauth/bind_login_phone`,
  `get oauth/callbackInfo`, `post login/v10/auth`.

### 1.5 Access-token login page
`loginAccessToken.tpl`, route `* loginAccessToken home/ViewClients/loginAccessToken`.
Carries the hidden `redirect_url` through; same second-verify plumbing (`secondVerifyModal`, `phoneCheckToken`).

---

## 2. Dashboard — `/clientarea`

- **URL**: `get clientarea` → `home/viewClients/clientarea`. Template `clientarea.tpl`.
- **Inline sub-request**: `GET /clientarea?action=list` returns **HTML** (not JSON) injected into
  `#sourceListBox` — the resource list include `includes/clientarea-list.tpl`. Sorting uses
  `getSourceList('sort', prop)` with `orderby=nextduedate` and `localStorage.sort`; page-size select
  `#sourcelimitSel` values 5/10/15/20/50/100; pagination is `$.get(href)` → `.html(data)`.
- **Data shown**
  - Identity card: `{$Userinfo.user.username}`, `{$Userinfo.user.id}`, `{$Userinfo.user.phonenumber}`
    (masked `substr=0,3 *** substr=7`), `$Userinfo.user.certifi.status` (`!=1` → grey shield).
  - Counters: `{$ClientArea.index.ticket_count}` (待处理工单), `{$ClientArea.index.order_count}`
    (未支付订单), `{$ClientArea.index.host}` (产品数量).
  - Finance card: ECharts gauge over `{$ClientArea.index.client.credit}` (余额) and
    `{$ClientArea.index.invoice_unpaid}`; `{$ClientArea.index.intotal}` (本月消费).
    Recharge button shown only `{if $ClientArea.index.allow_recharge == '1'}` → `/addfunds`.
  - Product groups: `{foreach $ClientArea.index.host_nav as $list}` → link `service?groupid={$list.id}`,
    text `{$list.groupname}` and `({$list.count})`.
  - Announcements: `{foreach $ClientArea.index.news as $list}` → `{$list.push_time|date="Y-m-d H:i"}`,
    `{$list.title}`, link `newsview?id={$list.id}`.
- **Resource list include** (`includes/clientarea-list.tpl`) columns:
  | Column | Variable |
  |---|---|
  | 机器状态 | `{$list.domainstatus_desc}` (dot colour class hard-coded `bg-success`) |
  | 主机名 | `{$list.productname}({$list.domain})` → `servicedetail?id={$list.id}` |
  | 到期时间 | `{$list.nextduedate|date="Y-m-d H:i"}` — hidden if `billingcycle=="free"` or `cycle_desc=='一次性'` |
  | 费用 | `{$list.price_desc}/{$list.cycle_desc}` (`billingcycle!="free"`) |
  | IP | `{$list.dedicatedip}` |
  Pagination uses `{$ClientArea.Total}`, `{$ClientArea.Limit}`, `{$ClientArea.Pages}`.
- Also present: `get index  home/index/index`, `get config_general/header  home/index/getHeader`,
  `get common_list  home/index/common_list`, `get navindex  home/common/index`
  (with `addindex_page`/`addindex_post`/`addindex_del` for user-defined shortcuts),
  `get sale_list  home/index/SaleList`, `get get_saler`/`post set_saler  home/user/getSaler|setSaler`.

---

## 3. Products / services

### 3.1 Service list
- **URL**: `get service` → `home/viewClients/service`. Filtered with `?groupid=N`.
- **Templates**: `service.tpl` (base), plus per-type skins that are **byte-identical copies** of
  `service_product.tpl` (md5 `2dc0682b33c3cd26d9f55043ca0e6c39`):
  `service_hosting.tpl`, `service_cloud.tpl`, `service_cloud_server.tpl`, `service_server.tpl`,
  `service_domain.tpl`, `service_soft.tpl`, `service_sms.tpl`, `service_cdn.tpl`.
  Only `service_ssl.tpl` differs materially (its own status set, and it `{include file="includes/paymodal"}`).
  `service.tpl` differs from `service_product.tpl` only in indentation plus `{include file="includes/paymodal"}`.
- **Columns**: checkbox, 状态 (`domainstatus`), 产品 (`productname` + `domain`), IP
  (`dedicatedip`, with a popover listing `$list.assignedips` when `count > 1`), 到期时间
  (`nextduedate|date="Y-m-d"`), 费用 (`price_desc` + billing cycle), 系统 (`os_url` + `svg` icon from
  `/upload/common/system/{$list.svg}.svg` or `{$list.os_url|getOsSvg}`), 备注 (`notes`, inline edit), 操作.
- **Cancellation marker**: `{if $list.host_cancel != ''}` shows a red error icon with popover
  `{$Lang.cancellation_time}：{$list.host_cancel.type}` / `{$Lang.cancelreason}：{$list.host_cancel.reason}`.
- **Auto-renew marker**: `{if $list.initiative_renew && billingcycle != 'free' && billingcycle != 'onetime'}`.
- **Status filter** (multi-select `#statusSel`): options are `{foreach $Service.domainstatus as $key => $list}`
  with labels `{$Lang['domainstatus_select_'.strtolower($key)]}`. Default when nothing in the URL:
  `['Pending','Active','Suspended']`. Applied via repeated query params `domain_status[]=…`.
  SSL list overrides the set: `['Pending','Active','Verifiy_Active','Overdue_Active','Issue_Active','Cancelled','Deleted']`.
- **Query params**: `groupid`, `keywords`, `sort`, `orderby` (`domainstatus`, `nextduedate`),
  `page`, `limit`, `domain_status[]`.
- **Bulk power state** — read from `/provision/default`, not from the list payload:
  ```js
  POST /provision/default   data: { id: [ids…], func: 'status ', code: '' }
  ```
  Response `data` is keyed by host id: `data.data[id] = { status: 200, data: { status: 'on'|'off'|'unknown'|'process', des: '…' } }`.
  `setColor()` maps `on → .on_color`, `off → .off_color`, `unknown → .unknown_color`, `process → spinning`.
  Polling loop `loopGetStatus` runs every 15 s for max 300 s.
- **Bulk operations** — `POST /provision/default` with `{ id: [...], func: 'on'|'off'|'reboot'|'hard_off'|'hard_reboot', code: '' }`.
  Dropdown items come from `{$Lang.batch_operation}`(开机)/`shut_down`(关机)/`restart`(重启)/
  `hard_shutdown`(硬关机)/`hard_restart`(硬重启). Gating is done by reading the rendered badge text
  (must all be 已激活 / 已暂停 for 续费, all 已激活 for bulk op).
- **Bulk renew**: 续费 button navigates to `/mulitrenew?host_ids[]=…&host_ids[]=…`.
- **Inline notes edit**: `POST /host/remark` `{ id, remark }` (also used on the detail page).
- **IP copy popover** uses `includes/pop.tpl` (`#popModal`, `#popTitle`, `#popContent`) + `clipboard.min.js`.

### 3.2 Service detail
- **URL**: `get|post servicedetail` → `home/viewClients/servicedetail`. Query `?id=<host_id>`.
- **Dispatcher template** `servicedetail.tpl` switches on host type:
  ```
  $Detail.host_data.type == 'hostingaccount' → servicedetail/hosting     (→ includes general.tpl)
                                'server'     → servicedetail/dedicated
                                'cloud'      → servicedetail/cloud
                                'dcimcloud'  → servicedetail/zjmfcloud
                                'dcim'       → servicedetail/zjmfdcim
                                'software'   → servicedetail/software
                                'cdn'        → servicedetail/cdn         (→ includes general.tpl)
                                'other'      → servicedetail/general
                                'ssl'        → servicedetail/ssl
  ```
  `servicedetail/hosting.tpl` and `servicedetail/cdn.tpl` are one-line files: `{include file="servicedetail/general"}`.
  `servicedetail.tpl.bak` is a truncated older variant (differs from `servicedetail.tpl` immediately).
  `servicedetail-v10-*.tpl` (cloud/common/dcim) are the v10 UI and are out of scope.
- **Modals defined in the dispatcher**:
  - `#modifyRemarkModal` — 备注, input `#remarkInp`, calls `modifyRemarkSubmit(id)` → `POST /host/remark {id, remark}`.
  - `#moduleResetPass` — 重置密码. Form fields: `password` (class `getPassword`), `force` checkbox (强制关机),
    hidden `func=crack_pass`, hidden `id`. Submitted to `POST /provision/default` with `+ '&code=' + code`.
  - `#moduleReinstall` — 重装系统. `name="os_group"` (`$Detail.cloud_os_group`, each `$item.id`/`$item.name`),
    `name="os"` (`$Detail.cloud_os`, `data-os='{:json_encode($Detail.cloud_os)}'`, options carry `data-group`),
    optional `name="port"` (only `{if $Detail.reinstall_random_port}`), optional
    `name="format_data_disk"` value `1` (only `{if $Detail.reinstall_format_data_disk}`),
    confirm checkbox `#moduleReinstallConfirm` (我已完成备份), hidden `func=reinstall`, hidden `id`.
    POST to `/provision/default`.
  - Password rule object injected as `var passwordRules = {:json_encode($Detail.host_data.password_rule.rule)}`
    with keys `len_num`, `num`, `upper`, `lower`, `special`; validated by `checkingPwd1()`
    (6–20 chars, forbids `^` and `%`).
- **Action endpoints used by the detail page** (from `assets/js/servicedetail.js` unless noted):
  | Purpose | Method | URL | Params |
  |---|---|---|---|
  | Load 升降级商品 modal | GET | `/servicedetail?id={id}&action=upgrade_page` | — (HTML) |
  | Load 升降级配置 modal | GET | `/servicedetail?id={id}&action=upgrade_configoption_page` | — (HTML) |
  | Load 流量包 modal | GET | `/servicedetail?id={id}&action=flowpacket` | — (HTML) |
  | Load 续费 modal | GET | `/servicedetail?id={id}&action=renew` | — (HTML) |
  | Load 财务信息 tab | GET | `/servicedetail?action=billing_page&id={id}&page&limit` | HTML, into `#finance` |
  | Load 日志 tab | GET | `/servicedetail?action=log_page&id={id}&page&limit` | HTML, into `#settings1` |
  | Generic module button | POST | `/provision/default` | `id`, `func`, optional `code` |
  | Custom module button | POST | `/provision/custom/{id}` | `id`, `func` |
  | Custom tab content | GET | `/provision/custom/content?id={hostid}&key={tabkey}` | HTML |
  | Custom chart data | GET | `/provision/chart/{id}` | — |
  | Custom button w/o host | POST | `/provision/button` | `{id, func}` (used for `resetLicense` 重置授权) |
  | DCIM buttons | POST | `/dcim/on|off|reboot|bmc|kvm|ikvm|rescue|reinstall|crack_pass|cancel_task|novnc|traffic` | `{id, code}` |
  | DCIM reinstall precheck | POST | `/dcim/check_reinstall` | `{id}` → `status 200` + `max_times`,`num`; `status 400` + `price` |
  | Buy reinstall quota | POST | `/dcim/buy_reinstall_times` | `{id}` → `data.invoiceid` |
  | Buy traffic packet | POST | `/dcim/buy_flow_packet` | `{id, fid}` → `data.invoiceid` |
  | Reinstall task progress | GET | `/dcim/resintall_status?id={id}` | `data.task_type` 0=重装 1=救援 2=破解 3=获取; `data.step` |
  | Host cancel request (closing) | DELETE | `/host/cancel` | `{id}` |
  | Auto balance renew toggle | POST | `/host/autorenew` | `{hostid, initiative_renew}` |
  | Per-host log list | GET | `/user_logdcims` | `{hid}` → `data.data.log_list[]` with `create_time`,`description`,`user`,`ipaddr` |
  | Per-host transaction list | GET | `/host/hostrecharge` | `{hostid, keywords}` → `data.data.invoices[]` with `pay_time`,`type`,`amount_in`,`trans_id`,`gateway` |
  | Power status refresh (DCIM) | POST | `/dcim/refresh_all_power_status` | `{id}` → `data[0].{status,msg}` |
  | Traffic usage series | GET | `/host/trafficusage` | `{id, start, end}` |
  | Dedicated bandwidth usage | GET | `/host/dedicatedserver` | `{host_id}` → `data.data.host_data.bwlimit` / `.bwusage` |
  | DCIM switch/port traffic | POST | `/dcim/traffic` | `{id, switch_id, port_name, start_time}` |
  | DCIM detail (switch list) | GET | `/dcim/detail` | `{id}` → `data.data.switch[]` |
  | DCIM traffic usage chart | GET | `/dcim/traffic_usage` | `{id, start, end}` |
  | noVNC open | POST | `/dcim/novnc` | `{id, code}` → `data.{password,url}`; window `/dcim/novnc?password=…&url=…&id=…&type=dcim` |
  | KVM/IKVM app download | POST | `/dcim/kvm` \| `/dcim/ikvm` | `{id, code}` → top-level `name`, `token` → `/dcim/download?name=…&token=…` |
  | SSL cert functions | POST | `/provision/sslCertFunc` | `func` ∈ `downloadCert`,`cancelVerify`,`getVerifiedStatus`,`issueBeforeCheckInfo`,`getVerifiedInfo`,`replaceDcvMethod` (+ `domainName`, `verifyMethod`), or the whole `#issus_form` |
  | SSL cert download | GET | `/provision/certDown/<orderNo>` | — |
  | Cancel request (customer) | POST | `/host/cancel` | serialized `includes/cancelrequire` form |
- **`/provision/default` button func values observed** in templates/JS:
  `status`, `reinstall`, `crack_pass`, `on`, `off`, `reboot`, `hard_off`, `hard_reboot`, `bmc`, `kvm`, `ikvm`,
  `vnc`, `rescue`, `rescue_system`, `resetLicense` (via `/provision/button`), `downloadCert`.
  Each list item supplies its own name/desc: `{$item.func}`, `{$item.type}` (`default` vs custom),
  `{$item.name}`, `{$item.desc}`; from `$Detail.module_button.control` and `$Detail.module_button.console`.
- **Detail page variables** (`general.tpl`):
  `$Detail.host_data.{id, productname, domain, remark, domainstatus, domainstatus_desc, firstpaymentamount_desc,
  regdate, billingcycle, billingcycle_desc, nextduedate, cycle_desc, status, price_desc, initiative_renew,
  format_nextduedate.{class,msg}, type, username, password, port, allow_upgrade_config, allow_upgrade_product,
  cancel_control, password_rule.rule.{len_num,num,upper,lower,special}}`,
  `$Detail.config_options[]` (`name`,`sub_name`), `$Detail.custom_field_data[]` (`fieldname`,`value`,`showdetail`),
  `$Detail.custom_fields_value`, `$Detail.module_client_area[]` (`key`,`name`), `$Detail.download_data[]`
  (`title`,`down_link`,`create_time`,`downloads`,`type` 1=zip 2=image 3=text),
  `$Detail.module_chart[]` (`type`,`title`,`select[]` with `value`/`name`),
  `$Detail.cloud_os_group[]`, `$Detail.cloud_os[]` (`id`,`name`,`group`),
  `$Detail.reinstall_random_port`, `$Detail.reinstall_format_data_disk`, `$Detail.module_power_status`,
  `$Detail.module_client_main_area[]` (software page: `[0].value`=有效域名, `[1].value`=有效目录),
  `$Cancel.{host_cancel.{type,reason}, cancelist[]}` (`includes/cancelrequire` uses `$Cancel.cancelist[].reason`),
  `$HostRecharge[]`, `$RecordLog[]`, `$Flowpacket[]` (`name`,`capacity`,`price`,`leave`,`id`),
  `$ForceContract.{base,has_contract,force,suspended,suspended_type,regdated}`.
- **Contract gate**: if `$ForceContract` is set, a full-screen overlay `div.contract_mc` + modal is rendered
  and blocks the page until the customer goes to `/contract` or `/contracthost?keywords={hostid}`.
  (合同 pages themselves are out of scope — one line: `/contract` and `/contracthost.tpl` handle e-signature.)
- **Detail includes**: `includes/modal.tpl` (二次验证 + confirm/custom modals + `modal.js`),
  `servicedetail/upgrade.tpl` (`#modalUpgradeStepOne`), `servicedetail/upgrade-configoptions.tpl`
  (`#modalUpgradeConfigStepOne`), `includes/cancelrequire.tpl`, `includes/pop.tpl`, `includes/chart.tpl`.

### 3.3 Renew
- **Single**: modal `#modalRenew` from `includes/renew.tpl`, HTML loaded from `/servicedetail?id=&action=renew`,
  form posts to `/servicedetail?id={id}&action=renew` with `billingcycles` radio (values from
  `$Renew.cycle[].billingcycle`, labels `billingcycle_zh`, prices `amount`, currency `$Renew.currency.{prefix,suffix}`).
- **Batch**: `* mulitrenew → home/viewClients/mulitrenew`, template `mulitrenew.tpl`.
  Query must contain `host_ids[]`. Form `POST mulitrenew?action=batchrenew`, per-row fields
  `host_ids[{index}]`, `cycles[{item.id}]` (options from `$item.allow_billingcycle[]` = `billingcycle`,
  `amount`, `billingcycle_zh`). Changing a cycle calls
  `POST /host/batchrenewpage` with the serialized form → `data.data.hosts[].nextduedate_renew`,
  `data.data.hosts[].saleproducts`, `data.data.total` (rendered with moment: `moment(ts*1000).format('YYYY-MM-DD HH:mm')`).
- **Auto-renew (v10 API)**: `get host/<id>/renew/auto  home/v10Cart/v10HostAutoRenewPage`,
  `put host/<id>/renew/auto  home/v10Cart/v10HostAutoRenew`.

### 3.4 Upgrade / downgrade
Two independent flows, both HTML-fragment based:
1. **Product upgrade** (`includes/upgrade.tpl`, loaded into `#upgradeProductDiv`):
   | Step | Method | URL | Params |
   |---|---|---|---|
   | advance | POST | `/servicedetail?id={id}&action=upgrade` | serialized `#modalUpgradeStepOne form`; response is HTML containing `modalUpgradeStepTwo` or a leading `<script>` |
   | settle | POST | `/upgrade/checkout_upgrade_product` | `{hid}` → `data.invoiceid` (redirect `/viewbilling?id=…`) or `status 1001` |
   | apply promo | POST | `/upgrade/add_promo_code_product` | `{hid, pormo_code, upgrade_type:'product'}` (note the typo `pormo_code`) |
   | extra promo step | POST | `/servicedetail?id={id}&action=upgrade_use_promo_code` | serialized step-2 form |
   | remove promo | POST | `/servicedetail?id={id}&action=upgrade_remove_promo_code` | serialized step-2 form |
2. **Config-option upgrade** (`includes/upgradeoption.tpl`, loaded into `#upgradeConfigDiv`):
   | Step | Method | URL | Params |
   |---|---|---|---|
   | advance | POST | `/servicedetail?id={id}&action=upgrade_config` | serialized form + `&action=upgrade_config` |
   | settle | POST | `/upgrade/checkout_config_upgrade` | `{hid}` (+ hidden `configoption[key]` inputs from `$UpgradeConfig`) → `data.invoiceid` |
   | apply promo | POST | `/upgrade/add_promo_code` | `{hid, pormo_code}` |
   | extra promo step | POST | `/servicedetail?id={id}&action=upgrade_config_use_promo_code` | — |
   | remove promo | POST | `/servicedetail?id={id}&action=upgrade_config_remove_promo_code` | — |
   | linkage recompute | GET | `/getLinkAgeList` (`home/viewCart/getLinkAgeList`) | config selections; sibling `get link_list → home/cart/getLinkAgeListJson` |
   Both flows live behind the 升降级 tab gated by `$Detail.host_data.allow_upgrade_product` /
   `allow_upgrade_config`, and both use `hide`/`show` on `$Detail.module_button` items; cloud/dcimcloud
   upgrades are confirmed with a "must be powered off" warn dialog before settling.
   Additional routes: `get upgrade/index/<hid>`, `post upgrade/upgrade_config_post`,
   `get upgrade/upgrade_config_page`, `post upgrade/remove_promo_code`, `post upgrade/upgrade_product_post`,
   `get upgrade/upgrade_product_page`, `post upgrade/remove_promo_code_product`.

---

## 4. Order flow (cart theme)

Theme root: `public/themes/cart/default/`. `theme.config`:
```
properties:  loggedheader:clientarea   nologinheader:clientarea
provides:    bootstrap 4.5.3   jquery 1.12.4   fontawesome 5.10.1
```
Sibling skins: `cart/area/` (config.tpl, ordersummary.tpl, product.tpl, topbar-categories.tpl) and
`cart/province/` (product.tpl, topbar-categories.tpl). v10 cart templates (`v10/`, `configureproduct-v10-*`)
are out of scope.

### 4.1 Product listing per group
- **URLs**: `get|post cart → home/viewCart/cart`; vanity order URLs `* store/<alias>` and `* buy/<alias>`
  → the same controller; also `get cart/index → home/cart/index`, `get cart/prolist → home/cart/proList`.
- **Templates**: `product.tpl` + `sidebar-categories.tpl` (`{include file="cart/default/sidebar-categories"}`).
- **Query**: `action=product&keywords=…`, `fid` (一级分组) / `gid` (二级分组), `site`.
  Sidebar links are `/cart?fid={$groups.id}&gid={$groups.second.0.id}`; search box redirects to
  `/cart?action=product&keywords=…`.
- **Variables**: `$Cart.product_groups[]` (`id`,`name`,`second[]{id,gid,name}`),
  `$Cart.product_groups_checked.{name,headline,tagline}`, `$Cart.products[]` (`id`,`name`,`description`,
  `stock_control`,`qty`,`has_bates`,`sale_price`,`product_price`,`billingcycle_zh`,`ontrial`,
  `ontrial_setup_fee`,`ontrial_price`,`ontrial_cycle`,`ontrial_cycle_type`), `$Cart.currency.{prefix,suffix}`.
- **Buy button**: `/cart?action=configureproduct&pid={$list.id}` (out of stock shows an icon instead when
  `stock_control==1 && qty<1`).
- AJAX helpers: `get cart/all → home/cart/getProducts`, `get cart/get_product_config → home/cart/getProductConfig`,
  `get cart/summary → home/Cart/summary`, `get cart/ontrialmax → home/Cart/ontrialAndMax`,
  `get cart/hostinfo → home/Cart/hostInfo`, `get cart/credit → home/Cart/getCredit`,
  `get cart/stock_control → home/Cart/getQty`.

### 4.2 Configure product
- **URL**: `/cart?action=configureproduct&pid={pid}[&i={position}]`. Template `configureproduct.tpl`,
  JS `assets/js/configureproduct.js`.
- **Form**: `#addCartForm`, method POST, `action="?action=configureproduct&pid={$CartConfig.product.id}"`
  (or `…&pos[]={$Think.get.i}"` when editing). Fields:
  | Field | Notes |
  |---|---|
  | `pid` | hidden, `$CartConfig.product.id` |
  | `currencyid` | hidden, `$CartConfig.dafault_currencyid` (sic, triple-a typo) |
  | `qty` | hidden, default 1 |
  | `promocode` | hidden, only if `$addParam.promocode` |
  | `aff` | hidden, only if `$addParam.aff` |
  | `sale` | hidden, only if `$addParam.sale` |
  | `configoption[{option.id}]` | one per config option (see types below) |
  | `customfield[{custom_fields.id}]` | one per custom field |
  | `billingcycle` | radio, values `$cycle.billingcycle`, labels `$cycle.billingcycle_zh`, discount `$cycle.cycle_discount` |
  | `host` | hidden hostname (`$CartConfig.host` or `$CartConfig.product.host.host`, only when `product.host.show != 0`) |
  | `password` | `$CartConfig.password` or `$CartConfig.product.password.password` |
  | `i` | hidden, edit position |
- **Config option `option_type` values seen** (all inside `{foreach $CartConfig.option as $option}`):
  `1` dropdown, `2` radio, `3` checkbox, `4` quantity (range + number, `qty_minimum`,`qty_maximum`,
  `qty_stage`, `unit`), `5` OS/version select, `12` area/country select. Others render radio groups.
  Linkage is client-side via `links = {:json_encode($CartConfig.links)}`,
  `window.onload → config_options_links()` with `config_condition({seq,sneq,sub_id})` and
  `config_result(array/list/hide/show)`. Selecting an option posts
  `GET /getLinkAgeList` with the form payload and re-renders `appendLinkAge()`.
- **Order summary**: `POST ?action=ordersummary&order_frm_tpl={$order_frm_tpl}&tpl_type={$tpl_type}` with the
  serialized form → HTML fragment inserted into `.configoption_total`. Template `ordersummary.tpl`.
  Variables: `$ConfigureTotal.{product_name,product_price,product_setup_fee,currency.prefix,type.{type,bates},
  total,sale_setupfee_total,sale_signal_price,signal_price,signal_setupfee,child[]}`;
  child rows: `option_name`, `option_type`, `icon_flag`, `icon_os`, `qty`, `sub_name`,
  `suboption_price`, `suboption_setup_fee`.
  Discount math uses `bcsub`/`bcadd`/`bcmul` in-template. `type.type` = `1` (折扣/折) or `2` (固定减免).
- **Add to cart**: `#addToCartBtn` / `#addToCartBtnTwo` → `$('#addCartForm').submit()` (after password-rule
  check via `checkingPwd1`). Server then redirects to the cart.
- **Other cart routes**: `post cart/createproducts`, `post cart/productsgroups`, `post cart/resource_product`,
  `get cart/set_config`, `get cart/advanced_config`, `get cart/set_config_post`, `post cart/get_total`,
  `post cart/modify_product_qty`, `post cart/edit_to_shop`, `post cart/add_to_shop`, `get cart/edit_to_shop_page`,
  `get cart/get_shop_data`, `get cart/global_search`, `get cart/market_app`, `get cartgateway → home/Cart/getGateway`.
  v10 equivalents: `post cart/v10/add → home/v10Cart/addToCart`, `get|post cart/v10/edit`.

### 4.3 Cart view + promo code
- **URL**: `/cart?action=viewcart`. Template `viewcart.tpl`, JS `assets/js/viewcart.js`.
- **Checkout form**: `#submit-form` → `POST cart?action=viewcart&statuscart=checkout`, with
  `register_or_login` (`register`|`login`, toggled by `changeType('old'|'new')`).
- **Inline register/login on the cart page** (guest checkout): phone vs email radio; fields
  `phone_code`,`phone`,`code`,`captcha`,`password`,`repassword`,`email`,`sale_id`,
  `fields[{id}]` (client custom fields), plus `{$list.name}` inputs from
  `$Register.login_register_custom_require`. Code senders: `POST register_phone_send` / `register_email_send`
  with `mk`, `phone`/`email`, `phone_code`, `captcha`. Captcha image reload endpoint:
  `/verify?name=<action>&request_time=<rand>`.
- **Product rows**: `{foreach $ShopData.cart_products as $cart_val=>$cart}` with `productsname`, `qty`,
  `allow_qty`, `productid`, `conf.host`, `conf_child[]{name,sub_name}`,
  `configoptions[key].value`, `type.{type,bates}`, `product_pricing`, `saleproducts`, `_sale_price`.
  Edit link `cart?action=configureproduct&pid={$cart.productid}&i={$cart_val}`.
  Delete link `removeItem('cart?action=viewcart&statuscart=remove', …, {i: [positions]})` (POST, see §0.2 pattern).
  Quantity update: `POST /cart?action=viewcart&statuscart=change&ajax=true` `{i, qty}` → reload.
- **Payment selection on the cart**: radio `paymt` with value `credit` (余额) or `credit_limit` (信用额,
  only if `$Userinfo.user.is_open_credit_limit`), plus gateway radio `payment` per `$list.name`
  (`$list.title`, `$list.author_url`).
- **Notes field**: `name="notes"` (maxlength 200).
- **Terms checkbox**: `name="terms" value="1" required`, links `{$Setting.web_tos_url}`.
- **Promo code**: `{$ShopData.promo.promo_desc_str}` for the applied code with a remove link
  `#removepromo`; input `name="promo"`; apply button posts
  `POST cart/add_promo` (`home/cart/addPromoToShop`) and removes via `POST cart/remove_promo`
  (`home/cart/removePromoToShop`). Also `post cart/remove_product`, `post cart/clear`,
  `get cart/check_promo_code`, `get cart/shop`, `get cart/check_page`, `post cart/settle → home/cart/settle`.
- **Totals**: `$ShopData.currency.prefix`, `$ShopData.total_price`, `$ShopData.promo.*`.

### 4.4 Checkout / settle / complete
- `get cart/check_page → home/cart/checkoutPage`, `post cart/settle → home/cart/settle`,
  `get cart/index → home/cart/index`.
- `complete.tpl` is **placeholder HTML only** (a 4-step Twitter-Bootstrap wizard with hard-coded English
  "Seller Details / Company Document / Bank Details / Confirm Detail" and no template variables) — the real
  下单成功 page is rendered elsewhere (title key `title_cart_complete` = 下单成功).
- After settle the flow lands on `/viewbilling?id=<invoiceid>&wakeup=1`, which auto-opens the pay modal
  (`viewbilling.tpl` `window.onload` → `#payamount` click).

---

## 5. Invoices / bills

### 5.1 Bill list
- **URL**: `get billing` → `home/viewClients/billing`. Template `billing.tpl`, JS `assets/js/billing.js`.
- **Filter**: `#statusSel` values `` (全部) / `Unpaid` / `Paid` / `Cancelled` / `Refunded`;
  navigates `billing?status=…&sort&orderby&page&limit`.
- **Columns** (sortable `prop` in brackets): 账单号 [`id`] (`#{$bill.id}`, links `viewbilling?id=`),
  类型 `{$bill.type_zh}`, 金额 [`subtotal`] `{$bill.subtotal}`, 支付时间 [`paid_time`],
  支付方式 [`payment_zh`], 逾期时间 [`due_time`], 状态 [`status`]
  (`<span class="status badge status-{$bill.status|strtolower}">{$bill.status_zh.name}</span>`), 操作.
- **Row actions**: 查看 `/viewbilling?id=`, 支付 `payamount({$bill.id})` (only when `status=='Unpaid'`),
  删除 `deleteConfirm('invoices', …)` → `DELETE /invoices/<id>` (route `delete invoices/<id> home/user_invoice/deleteOrder`).
- **Combine**: rows are `name="ids[{index}]" value="{$bill.id}"` inside `<form action="combinebilling">`.
  Selection triggers `GET /get_combine_invoices?ids[]=…` → `data.{count,total}`; when `count >= 2` the
  合并支付 button enables and `#pay-combine` is updated; submit goes to `* combinebilling → home/viewClients/combinebilling`.
- **Prepayment (信用额)**: `prepayment()` → `POST /credit_limit/prepayment` → `data.invoiceid` → `payamount(id)`
  (credit-limit payment is disallowed at that point).

### 5.2 Bill detail
- **URL**: `get viewbilling` → `home/viewClients/ViewBilling`. Template `viewbilling.tpl`.
  Sub-route `get invoices/<id> home/user_invoice/read`.
- **Data**: `$ViewBilling.detail.{status,companyname,username,phonenumber,create_time,paid_time,payment_zh,url}`,
  `$ViewBilling.payee`, `$ViewBilling.currency.{prefix,suffix}`,
  `$ViewBilling.invoice_items[]` (`type_zh`, `amount`, `description` — note the template explodes
  `description` on `\n` into multiple `<div>`s), `$ViewBilling.accounts[]`
  (`trans_id`, `amount_in`, `gateway`, `pay_time`), `$Pay.{invoiceid,total,PayStatus,payment,gateway_list,credit,
  credit_enough,use_credit,use_credit_limit,credit_limit_balance,pay_html.{type,data}}`,
  `$paymt.{is_open_credit_limit,credit_limit_balance,is_open_shd_credit_limit,subtotal}`.
- **Actions**: 立即支付 (`payamount(invoiceid)`), Print (`window.print()`), Download PDF
  (html2canvas + jsPDF, `PDF.save('账单#<id>_<ts>')`).
- **`wakeup=1`** query param auto-triggers payment, then if already Paid polls `/check_order`.

### 5.3 Combined bill view
`* combinebilling → home/viewClients/combinebilling`, template `combinebilling.tpl`.
`{foreach $Combine_billing as $index => $list}` with hidden `name="ids[{index}]" value="{$list.id}"`,
`{$list.total}` and `{foreach $list.items as $item}{$item.description} / {$item.amount}`.
POST target is the same `combinebilling` URL.

### 5.4 Payment flow (`/pay?action=…`)
Routes: `post pay → home/viewClients/pay`; helpers `get get_gateways/<module?> → home/pay/getGatewayList`,
`post change_paymt → home/pay/changePaymt`, `post start_pay → home/pay/startPay`,
`get order_list → home/pay/orderList`, `get recharge_page → home/pay/rechargePage`,
`post recharge → home/pay/recharge`, `get use_credit_page → home/pay/useCreditPage`,
`post invoice_page → home/pay/invoicePage`, `post apply_credit → home/pay/applyCredit`,
`post apply_credit_limit → home/pay/applyCreditLimit`.

The pay UI is `includes/pay.tpl` (23 KB) rendered inside `includes/paymodal.tpl`'s `#myModal` / `#pay`.
| Call | Method | Params | Notes |
|---|---|---|---|
| open pay panel | POST | `/pay?action=billing` | `{invoiceid, use_credit, payment, use_credit_limit}` → HTML |
| execute pay | POST | `/pay?action=billing&pay=true` | `{invoiceid, use_credit, payment, use_credit_limit}` |
| recharge pay | POST | `/pay?action=recharge` | `{amount, payment, use_credit_limit}` |
| switch 现金/信用额 | POST | `/change_paymt` | `{invoiceid, paymt}` (`1`=信用额, `0`=现金) |
| poll status | POST | `/check_order` | `{id: invoiceid}`; `status==1000` → redirect |
Gateways come from `$Pay.gateway_list[]` (`name`, `title`); `$Pay.pay_html.type` ∈
`url` (qrcode from `data`), `insert` (`<object data=…>`), `jump` (`window.open`), `html` (raw HTML injected).
Redirect preference after success: `data.data` → `$ReturnUrl` → `$ViewBilling.detail.url` →
`service?groupid={$ViewBilling.invoice_items.0.groupid}` (when `hid == '0'`) → `servicedetail?id={hid}`.
Credit balance toggle: checkbox `name="use_credit"`; credit-limit checkbox is disabled and the button
becomes 信用额支付 when `$Pay.use_credit_limit`.

### 5.5 Add funds / recharge
- **URL**: `get addfunds → home/viewClients/addfunds`. Template `addfunds.tpl`, JS `assets/js/addfunds.js`.
- **Data**: `$Addfunds.addfunds.{credit, currency.suffix, addfunds_minimum, addfunds_maximum,
  addfunds_maximum_balance, gateways[]{name,title,author_url}}`.
- **Form**: input `#addfundsInp` `name="amount"`, radio `name="payment"` per gateway
  (`.addfunds-payment` div carries `data-payment="{$gateways.name}"`), button `.pay-now-btn`.
- **Submit**: `POST /pay?action=recharge` with `{beforeCheck: 1, amount, payment}`.
  If the response is JSON with `status == 400 || 406` an inline alert `.beforecheck` replaces the box and
  the modal is hidden (used for "超出允许的余额上限"); otherwise the response HTML replaces
  `#pay .modal-body` and the pay modal opens.
  Client-side clamping `addfundsMaxMin()` uses `max = '{$Addfunds.addfunds.addfunds_maximum}'`,
  `min = '{$Addfunds.addfunds.addfunds_minimum}'`.

### 5.6 Credit / 信用额
- **URL**: `get credit → home/viewClients/credit`. Template `credit.tpl`.
- **Data**: `$Credit.{prefix,suffix,credit_limit,credit_limit_used,credit_limit_balance,
  credit_limit_used_percent,this_month_bill.{status,subtotal},amount_to_be_settled,bill_generation_date}`;
  table `{foreach $Invoices as $index => $item}` → `id`, `subtotal`, `create_time`, `due_time`, `payment`, `status`.
  Actions: `payamount({$item.id})` when Unpaid; `creditdetail?id={$item.id}`.
- **Detail**: `get creditdetail → home/viewClients/creditdetail`, template `creditdetail.tpl`
  (`$CreditDetail.invoices[].{id,subtotal,invoice_items[].{description,amount}}`, `$CreditDetail.currency`,
  `$ViewBilling.accounts[]`).
- Credit limit routes: `get credit_limit → home/credit_limit/index`, `get credit_limit/list`,
  `get credit_limit/user_invoice`, `get credit_limit/user_invoice_detail`, `post credit_limit/prepayment`.

### 5.7 Transaction records
- **URL**: `get transaction → home/viewClients/transaction`. Template `transaction.tpl` + `transaction/*.tpl`.
- **Tabs / `?action=` values**: `accounts_record` (交易流水), `recharge_record` (充值记录),
  `refund_record` (退款记录), `withdraw_record` (提现记录); the extra `<select id="accountsRecordSel">`
  offers `accounts_record` / `credit_record` (余额) / `credit_limit` (信用额 — only when
  `$Userinfo.user.is_open_credit_limit==1`). Each include:
  | Include | Columns |
  |---|---|
  | `transaction/accounts_record.tpl` | ID, 账单号 (`invoice_id`→`viewbilling?id=`), 金额 (`amount_in` if `$list.refund` else `amount_out`), 描述 (`description`), 支付方式 (`payment_zh`), 类型 (`type_zh`), 交易时间 (`pay_time`), 流水号 (`trans_id`) |
  | `transaction/credit_record.tpl` | ID, 金额 (`amount`), 描述, 类型, 支付时间 (`create_time`) |
  | `transaction/credit_limit.tpl` | ID, 账单号 (links `viewbilling?id={$list.id}`), 金额 (`subtotal`), 类型 (`type`), 交易时间 (`paid_time`) |
  | `transaction/recharge_record.tpl` | ID, 账单号, 金额, 支付方式 (`payment_zh`), 描述, 充值时间 (`pay_time`), 流水号 |
  | `transaction/refund_record.tpl` | ID, 账单号, 金额 (`amount_out`), 描述, 退款时间 (`pay_time`) |
  | `transaction/withdraw_record.tpl` | ID, 金额 (`num`), 描述 (`des`), 来源 (`reason`), 提现时间 (`create_time`) |
- **Sort props accepted**: `id`, `invoice_id`, `num`, `amount_in`, `amount_out`, `create_time`,
  `transaction_time`, `pay_time`, `trans_id`.
- Backing JSON routes: `get accounts_record → home/user_invoice/accountsRecord`,
  `credit_record`, `consume_record`, `recharge_record`, `refund_record`, `withdraw_record`,
  `finance_record`, `get_invoices`, `get_invoices_detail`,
  `get get_combine_invoices`, `post combine_invoices → home/user_invoice/combineInvoices`.

---

## 6. Support tickets (工单)

### 6.1 List
- **URL**: `get supporttickets → home/viewClients/supporttickets`. Template `supporttickets.tpl`.
- **Columns**: 工单部门 `{$ticket.department_name}`; 标题 `#{$ticket.tid}-{$ticket.title}` linking to
  `viewticket?tid={$ticket.tid}&c={$ticket.c}`; 创建时间 `{$ticket.create_time}`; 更新时间
  `{$ticket.last_reply_time}`; 状态 `<span class="status badge" style="background-color: {$ticket.status.color};">{$ticket.status.title}</span>`;
  操作 (查看).
- **Ticket statuses** come from the DB table `shd_ticket_status` (`id`,`title`,`color`,`order`,
  `show_active`,`show_await`,`auto_close`) — i.e. statuses are **admin-configurable rows**, not a fixed enum.
  `viewticket.tpl` hard-codes only the closed check `{if $ViewTicket.ticket.status.id != "4"}`.
  The commented-out filter block shows the stock set: 待回复 / 已回复 / 已关闭 / 已取消.

### 6.2 Submit
- **URLs**: `get|post submitticket → home/viewClients/submitticket`.
  Template `submitticket.tpl` dispatches `step`:
  `{if $Think.get.step != '2'} includes supporttickets/supporttickets-one {else} supporttickets/supporttickets-two`.
- **Step 1** (`supporttickets/supporttickets-one.tpl`): cards per `{foreach $SubmitTicket.department as $department}`
  (`id`, `name`, `description`) linking `submitticket?step=2&dptid={$department.id}`.
- **Step 2** (`supporttickets/supporttickets-two.tpl`): `enctype="multipart/form-data"` POST form with
  | Field | Source |
  |---|---|
  | `dptid` | select of `$SubmitTicket.department[]` (`id`,`name`) |
  | `hostid` | select of `$SubmitTicket.ticketpage.host_list` (key = host id, value = display name); preselected by `?pid=` |
  | `priority` | select of `$SubmitTicket.ticketpage.priority` (keys lower-cased, default `Medium`) |
  | `title` | text input |
  | `customfield[{list.id}]` | `{foreach $ticketCustom as $k => $list}`, `fieldtype` ∈ dropdown/password/text/link/tickbox/textarea, `required` honoured |
  | `content` | textarea (summernote/markdown includes are loaded) |
  | `attachments[]` | repeated file inputs, `#addFileBtn` adds another `.filebox`; allowed suffixes per `{$Lang.allowed_suffixes}` = .jpg/.gif/.jpeg/.png |
- **Endpoints**: `get ticket/department → home/ticket/getDepartmentList`,
  `get ticket/get_custom → home/ticket/getTicketCustom`,
  `get ticket/ticket_page → home/ticket/getOpenTicketPage`,
  `post ticket/create → home/ticket/createTicket`, plus `get ticket/list → home/ticket/getList`.
  Uploads also go through `post uploads → home/upload/upload`, `post upload_image → home/upload/uploadImage`,
  `post home/upload_file → home/upload/uploadFile`.

### 6.3 View / reply / close / rate
- **URL**: `get|post viewticket → home/viewClients/viewticket`. Template `viewticket.tpl`.
  Query `tid`, `c`. `get ticket/detail → home/ticket/ticketDetail`.
- **Data**: `$ViewTicket.ticket.{tid,title,status.{id,title,color},create_time,department.name,host,c}`,
  `$ViewTicket.list[]` = replies with `{id, admin, user_type, realname, format_time, content, attachment[],
  star, type}`, `$ViewTicket.feedback_request`.
  Attachment links are rendered as `http://{$attachments}` with the display name taken from
  `{:substr($attachments, strpos($attachments,"^")+1)}` (path `^` name convention).
- **Reply**: POST the same URL, multipart, fields `tid`, `c`, `content`, `attachments[]`
  (`post ticket/reply → home/ticket/replyTicket`).
- **Close**: `getModal('ticket/close', …, {tid, token})` → `POST /ticket/close` (`home/ticket/closeTicket`),
  then redirect `/supporttickets`.
- **Rating**: star widget `input.rating#starRating{reply.id}` → `adminScore(rid, type)` →
  `POST /ticket/evaluate` `{rid, type, star, tid}` (`home/ticket/evaluate`).
- **Attachment download**: `post ticket/download → home/ticket/downloadAttachment` and
  `get ticket/download → home/ticket/download`.
- Custom-field partial: `supporttickets/supporttickets-customfields.tpl` (referenced but commented out);
  `supporttickets/supporttickets-confirm.tpl` is empty (0 bytes).

---

## 7. Account, security, logs, messages, API

### 7.1 Dashboard/user info endpoints (JSON)
| Method | URL | Controller |
|---|---|---|
| GET | `index` | `home/index/index` |
| GET | `user_info` | `home/user/index` |
| PUT | `user_info` | `home/user/update` |
| POST | `modify_password` | `home/user/modifyPassword` |
| GET | `get_api_pwd` | `home/user/getApiPwd` |
| POST | `modify_api_pwd` | `home/user/modifyApiPwd` |
| GET | `auto_api_pwd` | `home/user/autoApiPwd` |
| GET | `get_areas` / `areas/<pid?>` / `country` | `home/user/getAreas` / `areas` / `country` |
| POST | `toggle_second_verify` | `home/user/toggleSecondVerify` |
| GET | `second_verify_page` | `home/user/getSecondVerifyPage` |
| POST | `second_verify_send` | `home/user/secondVerifySend` |
| GET | `user_action_log/<page?>` | `home/user/user_action_log` |
| GET | `user_logs` | `home/record_log/getUserLogs` |
| * | `user_logdcims` | `home/record_log/getUserLogDcs` |

### 7.2 Account details
- **URL**: `get|post details → home/viewClients/details`. Template `details.tpl`.
- **Form** (plain POST, no AJAX): contact info is read-only (`email`, `phonenumber`);
  editable fields `qq`, `username`, `companyname`, `country` (select from `$Details.areas.country[].name`),
  `province`, `city`, `region`, `address1`, `defaultgateway` (select from `$Userinfo.gateways[]`
  = `name`/`title`), `marketing_emails_opt_in` (checkbox, value 1), `send_close` (checkbox, value 1),
  and per-custom-field `custom[{custom.id}]` (`{foreach $Userinfo.customs as $custom}` with
  `sortorder`, `fieldname`, `fieldtype` ∈ dropdown/text/password/link/tickbox/textarea,
  `fieldoptions` (comma-joined for dropdown), `value`, `description`, `required`).
- Read-only displays: `$Userinfo.client_group.group_name` (default `默认分组`).

### 7.3 Security centre
- **URL**: `get security → home/viewClients/security`. Template `security.tpl`, JS `assets/js/security.js`.
- **Header**: `$Userinfo.user.username`, `$Userinfo.user.certifi.status` (`==1` → 已实名认证 badge),
  `$percentage[0]` (label) / `$percentage[1]` (progress %), `email`, `create_time`, `phonenumber`.
- **Cards and their endpoints**
  | Feature | Gate variable | Endpoint |
  |---|---|---|
  | 登录密码 change | `$Userinfo.user.is_password` | `POST /modify_password` `{flag, old_password, password, re_password, code, captcha}` — `flag` is `1` when a password exists (change) else `2` (set); success → `/login` after 2 s |
  | 手机绑定 | `$Userinfo.shd_allow_sms_send` | `POST /bind_phone_handle` `{phone_code:'+86', phone, code}` |
  | 手机换绑 — verify old | `$BindPhoneChange==0` | `POST /bind_phone_change` `{phone_code:'+86', tel, code, type:1}` |
  | 手机换绑 — bind new | `$BindPhoneChange==1` | `POST /bind_phone_change` `{…, type:2}` |
  | 登录短信提醒 | `$Userinfo.user.is_login_sms_reminder` | `POST /login_sms_reminder` `{status: 0|1, code?}` |
  | 邮箱绑定 | `$Userinfo.shd_allow_email_send` | `POST /bind_email_handle` `{email, code}` |
  | 邮箱换绑 | `$BindEmailChange` | `POST /change_email_handle` `{phone_code:'+86', email, code, type:1|2}` |
  | 登录邮箱提醒 | `$Userinfo.user.email_remind` | `POST /login_email_reminder` `{status: 0|1, code?}` |
  | 二次验证 on/off | `$Userinfo.allow_second_verify` / `$Userinfo.user.second_verify` | `POST /toggle_second_verify` `{second_verify: 1}` or `{second_verify: 0, type, code}` |
  | 实名认证 | `$Setting.certifi_open==1` | link to `verified` / `verified?action=enterprises&step=info` |
  | 第三方登录 bind/unbind | `$Security.oauthBind[]` (`name`,`img`,`oauth` ∈ `bind`/`unbind`,`username`,`url`,`dirName`) | `getModal('oauthBind/untie/{dirName}', …, {status: 1})` → `POST /oauthBind/untie/{dirName}`; bind uses `get oauthBind/<dirName>` |
  | 交互授权 (license) | `$Bot==1` | `GET /interflow/accountbind` → `data.data.qq`; `POST interflow/accountbind` `{qq}` |
  | 敏感操作验证识别码 | `{$Lang.Sensitive_operation_verification_identifier}` | see second verify |
- **Code-sending helper** `getCheckCode(action, name, button, method, type, modal, captcha)` posts
  `{phone_code:'+86', type, <name>, captcha}` to `WebUrl + action`, where `action` ∈
  `bind_phone`, `bind_phone_code`, `bind_email`, `change_email`, `remind_send`, `remind_email_send`,
  `second_verify_send`. 60-second client countdown via `setCutdown`.
- **API key modal** (legacy, now commented out in the UI): `#getapiModal`, field `name="api"` bound to
  `{$Userinfo.user.api_password}`, buttons 复制 / 重置 (`getApiPwd()` generates a 12-char random
  `[A-Za-z0-9_-~`!@#$%^&*()=+]` string client-side; `security.js` notes it is only echoed by the backend).
- API/second-verify/resource-API gates: `$Userinfo.allow_resource_api`, `$Userinfo.allow_second_verify`,
  `$Security.oauthBind`, `$Userinfo.shd_allow_sms_send`, `$Userinfo.shd_allow_email_send`.

### 7.4 Logs
Three near-identical pages, all with tabs linking between each other and a
`{include file="includes/tablesearch" url="…"}` search box (`keywords`, `sort`, `orderby`, `page`, `limit`):
| Page | URL | Template | Collection | Columns |
|---|---|---|---|---|
| 系统日志 | `get systemlog → home/viewClients/systemlog` | `systemlog.tpl` | `$SystemLog` | 操作详情 `{$list.description}`; 操作时间 `{$list.create_time|date="Y-m-d H:i"}`; IP 地址 `{$list.ipaddr}`; 操作人 `{$list.user}` |
| 登录日志 | `get loginlog → home/viewClients/loginlog` | `loginlog.tpl` | `$LoginLog` | same four, time format `Y-m-d H:i:s` |
| API 日志 | `get apilog → home/viewClients/apilog` | `apilog.tpl` | `$APILog` | same four, time format `Y-m-d H:i` |
Backing data: `shd_system_log` (`create_time`,`description`,`user`,`uid`,`user_type`,`ip`,`log_type`,`relid`)
and `shd_activity_log_home`. REST equivalents: `get v1/log/system|login|api` (`openapi/Log/*`).

### 7.5 Message centre
- **URL**: `get message → home/viewClients/message`. Template `message.tpl` +
  `message/messagetabledata.tpl`.
- **Tabs**: 全部信息 (`?type=0`) plus `{foreach $Setting.unread_nav as $navList}` — items `{$navList.id}`,
  label `{$Lang[$navList.name]}`, unread badge `{$navList.unread_num}`.
- **Columns**: checkbox, 标题内容 (unread dot when `{$list.read_time == '0'}`, click → `openContent(key, [id])`),
  提交时间 `{$list.create_time|date="Y-m-d H:i"}`, 类型 `{$Lang[$list.type_text]}`.
- **Detail modal**: renders `message[key].content` and, if `message[key].attachment?.length`, appends
  links `<a href="${v.path}" target="_blank">${v.name}</a>`; the `$Message` array is embedded via
  `var message={:json_encode($Message)}`. Opening a message fires `GET /read_messgage?ids=<id>`.
- **Bulk actions**:
  | Action | Method | URL | Params |
  |---|---|---|---|
  | 删除 | GET | `/delete_messgage` | `{ids: [...]}` or `{type: 0}` (all) |
  | 标记已读 | GET | `/read_messgage` | `{ids: [...]}` or `{type: 0}` (all) |
  | 全部已读 / 全部删除 | GET | same endpoints | `type=0` |
  Routes: `get sys_messgage → home/system_message/getMessageList`,
  `get sys_messgage_unread → home/system_message/getUnreadList`,
  `get read_messgage → home/system_message/readSystemMessage`,
  `get delete_messgage → home/system_message/deleteSystemMessage`.
- Backing table `shd_system_message` (`uid`,`title`,`content`,`obj`,`attachment`,`type` 1工单/2产品/3站内/4活动,
  `is_market`,`read_time`,…). REST: `get v1/message`, `put v1/message/<id>`, `delete v1/message/<id>`.

### 7.6 Second verify (二次验证) — shared plumbing
Files: `includes/modal.tpl` + `assets/js/modal.js`.
- Globals injected: `Userinfo_allow_second_verify`, `Userinfo_user_second_verify`,
  `Userinfo_second_verify_action_home` (JSON array), `Login_allow_second_verify`,
  `Login_second_verify_action_home`.
- `isNeedSecond(action)` returns true when enabled and the action name is in the injected array.
  Action names observed: `login`, `modify_password`, `closed`, `reinstall`, `crack_pass`, `rescue`, `vnc`,
  `rescue_system`, `get_api_pwd`.
- `getSecondModal(action, fn, username, password)` shows `#secondVerifyModal`; the select
  `#secondVerifyType` is populated from `$AllowType` (`name`, `name_zh`, `account`) or, for login,
  from `GET /login/second_verify_page` → `data.allow_type[]`.
- Send code: `POST /second_verify_send` (client area) or `POST /login/second_verify_send` (login page)
  with `{action, type, username, password}`; countdown `setCutdown('#secondCode')`.
- The verified `(type, code)` pair is then passed into the original AJAX payload as `code` (and
  `code_type` in the login form).
- Routes: `get verify → home/login/verify` (captcha image), `get login/second_verify_page`,
  `post login/second_verify_send`, `get mobile_login_page → home/login/mobileLoginVerifyPage`,
  `post login_send → home/login/mobileSend`, `post mobile_login → home/login/mobileLoginVerify`,
  `post login_pass_phone → home/login/phonePassLogin`, `post login_pass_email → home/login/emailLogin`,
  `get login_register_index → home/login/LoginRegisterIndex`, `get aff/<identy?> → home/login/aff`,
  `get product_list_page → home/login/getProuductlistPage`.
- REST: `post v1/second_verify → openapi/Public/secondVerify`.

### 7.7 API management (`/apimanage`) — most important page
- **URL**: `get apimanage → home/viewClients/apimanage`. Template `apimanage.tpl`.
- **Two states**, driven by `$api_open`:
  - `$api_open == 0` → "未开启" panel.
  - `$api_open == 1` → management panel; `$api_open == 2` → same panel plus a lock overlay
    (`.api-manage-modal` shown by `{if $api_open==2}` script).
- **State 0 (not enabled)** — gates, in template order:
  | Variable | Effect |
  |---|---|
  | `$api_open_total == 0` | shows 暂未开启API对接功能 (`api_no_open_title_total`) and **no** enable button |
  | `$need_bind_phone == 1` | shows 「使用API功能需要绑定手机 →<a href="security">去绑定手机</a>」 |
  | `$need_certify == 1` | shows 「使用API功能需要实名认证 →<a href="verified">去实名认证</a>」 |
  otherwise | 立即开启 button `.apiOn`, checkbox `.agreeOn`, protocol link `{$server_clause_url}` (`api_protocol`) |
  - Enable flow: the checkbox must be ticked (else `.no-check-pro` alert with `{$Lang.api_pro_no_checked}`),
    then a confirm modal, then
    **`POST /zjmf_finance_api/open`** with `data: {"api_open": 1}` (JSON) → `{status, msg}`;
    non-200 → toastr error, 200 → toastr success + `location.reload()`.
- **State 1 (enabled) — displayed fields**
  | Label | Variable |
  |---|---|
  | API密钥 | `{$API.client.api_password}` (plain text in the header box) |
  | API开通时间 | `{$API.client.api_create_time|date="Y-m-d H:i:s"}` |
  | API产品数量（已激活/总量） | `{$API.client.active_count}/{$API.client.host_count}` |
  | 代理商品数量 | `{$API.client.agent_count}` |
  | 今日API请求数量 | `{$API.client.api_count}` |
  | 日环比 | `{$API.client.up}` (bool → up/down caret) and `{$API.client.ratio}` |
  | 请求次数图表 (近七天) | `var formApi = {:json_encode($API.form_api)}` → 7 values, fee at `#api-charts-main` (ECharts line) |
  | 豁免产品列表 | `{foreach $API.free_products as $free_product}` → `{$free_product.name}`, `{$free_product.ontrial}` (试用数量), `{$free_product.qty}` (最大购买数量) |
- **Actions**
  | Button | Handler | Endpoint |
  |---|---|---|
  | 重置API | `resetApiPwd()` (confirm `是否确定重置API`) | `POST /zjmf_finance_api/reset` (`home/ZjmfFinanceApi/resetApiPwd`), no body |
  | 关闭API | `closeApi()` (confirm `是否确定关闭API`) | `POST /zjmf_finance_api/open` `{"api_open": 0}` |
  | (locked) | link to `/submitticket` | — |
- **Summary route**: `get zjmf_finance_api/summary → home/ZjmfFinanceApi/summary`.
- Related: `post resource_login_supplier → home/login/resourceLogin` (supplier login),
  `post zjmf_api_login → home/login/zjmfApiLogin`,
  `post zjmf_api/provision/custom/content → home/provision/postClientAreaContent`,
  `get provision/custom/content → home/provision/getClientAreaContent`,
  `get interflow/accountbind` / `post interflow/accountbind` (`home/interflow/*`).
- Note: `$Userinfo.allow_resource_api` gates a legacy "API" card on `security.tpl`, but that markup is
  commented out; the live API key UI is `/apimanage` plus `#getapiModal` on `security.tpl`.

---

## 8. News / knowledge base / downloads / affiliates / other

### 8.1 News & announcements
- Pages: `get news → home/viewClients/news` (`news.tpl`), `get newslist → home/viewClients/newsList`
  (`newslist.tpl` — **a static Bootstrap blog demo with no template variables**),
  `get newsview → home/viewClients/newsview` (`newsview.tpl`),
  `get notice/*`, `get news/<alias>` (aliases share the same controller).
- `news.tpl` data: `{foreach $NewsList as $news}` → `{$news.id}`, `{$news.title}`,
  `{$news.push_time|date='Y-m-d H:i'}`, link `./newsview?id=`. Categories:
  `{foreach $classify}` → `{$classify.id}`, `{$classify.title}`, `{$classify.count}`, link `./news?cate=`.
  Search box redirects with `keywords`.
- `newsview.tpl` data: `$ViewAnnouncement.{cate_name,title,push_time,content|raw,prev.{id,title},next.{id,title}}`.
  Content is injected into an `#viewcontent` iframe with an injected `<style>` block (see the
  `viewdoc.write(content)` block at the end of the file) — the iframe trick is how announcement HTML
  is isolated from the client-area CSS.
- REST: `get news/list → home/News/getList`, `get news/notice → home/News/getNotice`,
  `get news/content → home/News/getContent`, `get news/catelist → home/News/getCateList`,
  `get notice/list → home/News/getNoticeList`, `get notice/content → home/News/getNoticeContent`,
  `get v1/news`, `get v1/news/<id>`.
- Title keys: `title_news` = 新闻中心.

### 8.2 Knowledge base (帮助中心)
- Pages: `get knowledgebase → home/viewClients/knowledgebase` (`knowledgebase.tpl`),
  `get knowledgebase/<alias>`, `get knowledgebaselist` (`knowledgebaselist.tpl` — **empty, 0 bytes`**),
  `get knowledgebaseview → home/viewClients/knowledgebaseview` (`knowledgebaseview.tpl`).
- `knowledgebase.tpl`: `{foreach $help…}` → `{$help.id}`, `{$help.title}`,
  `{$help.create_time|date='Y-m-d H:i'}`, link `./knowledgebaseview?id=`; category list
  `{$classify.id}` / `{$classify.title}` / `{$classify.count}` (link `./knowledgebase?cate=`).
- `knowledgebaseview.tpl`: `$KnowledgeBaseArticle.{cate_name,title,create_time,description,content|raw,
  prev.{id,title},next.{id,title}}` plus a label list `{foreach … as $label}{$label}`.
- REST/other: `get knowledge_base/index → home/knowledge_base/index`,
  `post knowledge_base/search_article → home/knowledge_base/searchArticle`,
  `post knowledge_base/tags_list → home/knowledge_base/tagsList`,
  `get knowledge_base/view_article/<id> → home/knowledge_base/viewArticle`,
  `get v1/knowledgebase`, `get v1/knowledgebase/<id>`.
  Tables: `shd_knowledge_base`, `shd_knowledge_base_cats`, `shd_knowledge_base_links`, `shd_knowledge_base_tags`.

### 8.3 Downloads (资源下载)
- **URL**: `get downloads → home/viewClients/downloads`. Template `downloads.tpl`.
  `downloadscate.tpl` is empty (0 bytes).
- Data: `$Downloads.location_url` (if set, `window.open()` fires on load),
  `$Downloads.downloads.cate_data[]` (`id`,`name`,`file_count`, link `./downloads?cate_id=`),
  `$Downloads.downloads.downloads[]` (`title`, `down_link`, `update_time`, `downloads`,
  `type` ∈ `1` zip / `2` image / `3` text → icon class).
- Routes: `get download/cates → home/down/cates`, `post download/search → home/down/search`,
  `get download/product_file → home/down/productFile`, `get v1/downloads`, `get v1/downloads/<id>`.
  Table `shd_downloadcats` / `shd_downloads`.

### 8.4 Affiliates (推介计划) — brief
- `get affiliates → home/viewClients/affiliates`; template `affiliates.tpl` dispatches to
  `affiliates/unaffiliates` or `affiliates/affiliates` on `$Affiliates.aff`, and redirects to
  `/404.html` when `$Affiliates.is_open != 1`.
- Summary fields: `$Affiliates.data.{balance,withdraw_ing,audited_balance,payamount,visitors,registcount,url,suffix}`,
  `$Affiliates.affiliate_withdraw` (minimum). Actions: `GET /activation` (`home/user_affiliate/activation`),
  `getModal('withdraw', …)` → `POST /withdraw` (`home/user_affiliate/withdraw`).
- Sub-tables loaded by AJAX into tabs:
  `GET /affiliates?action=affbuyrecord` → `$AffBuyRecord[]` (`create_time`,`prefix`,`subtotal`,`suffix`,
  `type`,`aff_type`,`is_aff`,`aff_sure_time`,`paid_time`,`commission`);
  `?action=withdrawrecord` → `$WithdrawRecord[]` (`create_time`,`num`,`type` 1余额/2仅记录/3流水支持,
  `status` 1待审核/2审核通过/3拒绝 + `reason`,`user_nickname`);
  `?action=useraffilist` → `$UserAffilist[]` (`username`,`email`,`phonenumber`,`create_time`,`lastlogin`).
- Other routes: `get affpage`, `get affindex`, `get useraffi_list`, `* withdrawrecord`, `* affbuyrecord`.
  Tables `shd_affiliates`, `shd_affiliates_user`, `shd_affiliates_withdraw`, `shd_affiliate_ladder`.

### 8.5 Out-of-scope items (one line each)
- **合同 (contracts)**: `contract.tpl` (47 KB) / `contracthost.tpl` (26 KB), routes `get contract`,
  `get contracthost → home/viewClients/contractHost`, `get|post contract/*` — electronic contract
  signing with on-screen signature (`jSignature.js`); only relevant here because `servicedetail.tpl`
  can hard-block a product page behind `$ForceContract`.
- **产品转移 (product_divert)**: `product_divert/{pushserver,pullserver,pushpulllist}.tpl` + routes
  `* product_divert/*` — push/pull a host between installations; separate subsystem.
- **发票 (tax invoice / voucher)**: `invoicelist.tpl`, `invoiceapply.tpl`, `invoicecompany.tpl`,
  `invoiceaddress.tpl` and `POST /voucher/issuevoucher` (JSON body) — 6% style tax-invoice issuance and
  courier mailing, unrelated to the billing `/billing` pages.
- **DCIM / 魔方云 cloud panels**: `servicedetail/{zjmfdcim,zjmfcloud,cloud,dedicated}.tpl` and the whole
  `home/v10Cart/*` surface (`v10/*` templates, `servicedetail-v10-*.tpl`, `configureproduct-v10-*`) —
  power control, VNC, snapshots, disks, VPC/NAT, image/package config; documented only at the
  endpoint level in §3.2.
- **v10 templates**: `themes/clientarea/default/v10/**`, `themes/cart/default/v10/**`,
  `servicedetail-v10-common.tpl`, `configureproduct-v10-common.tpl` — a Vue-based rewrite of the same
  pages; ignore for a Laravel re-implementation of the classic UI.

### 8.6 Misc pages
| URL | Template | Notes |
|---|---|---|
| `get verified` / `post verified` (`home/viewClients/verified`) | `verified.tpl`, `verifiedpersonal.tpl`, `verifiedenterprises.tpl` | 实名认证. Personal form fields are dynamic: `certifi_type` select (`$Verified.certifi_select[]` = `value`/`name`/`custom_fields[]` = `{title,field,type ∈ text/file/select}`), plus `real_name` etc. Endpoints `POST certifi_ping` (`home/certification/ping`), `person_certifi_post`, `person_query_post`, `company_certifi_post`, `company_query_post`, `person_to_company` (all `home/certification/*`); polling helper `checkVerified()` in `assets/js/verified.js` hits `certifi_ping?type=…` every 3 s up to 30 times. Pricing block uses `$Verified.{total,freetimes,freetimes_use,invoiceid}` with `payamount(invoiceid,0)`. |
| `get maintenance` | `maintenance.tpl` | standalone `/维护中` page with `{$msg}` |
| `404.tpl` | `404.tpl` | static 找不到页面 page, no variables |
| `logout.tpl` | | empty (0 bytes); logout is handled by route |
| `get apps` / `appincome` / `apptransaction` / `applog` | `/* no tpl in default theme */` | developer-centre pages; routes `home/viewClients/apps|appincome|apptransaction|applog` |
| `get contacts/index`, `post contacts/save`, `delete contacts/del` | none in default theme | contacts CRUD (`home/contacts/*`) |
| `get authorDown → home/viewClients/authorDown` | | authorization file download |

---

## 9. Global layout (header / nav / language / currency / assets)

### 9.1 Layout skeleton
`header.tpl` opens the document; `footer.tpl` closes it. Both are conditional on `$TplName`:
```
{if $TplName != 'login' && $TplName != 'register' && $TplName != 'pwreset'
    && $TplName != 'bind' && $TplName != 'loginaccesstoken'}
```
i.e. those five pages render **without** the sidebar/header/footer chrome. `includes/pageheader` is
skipped when `$TplName == 'clientarea'` (the dashboard draws its own header); it renders
`{$Title}` (plus ` - {$Get.id}` on `viewbilling`) and a breadcrumb
(`includes/breadcrumb.tpl`: `{$Lang.title_clientarea}` → `{$Title}`).
`{$Title}` per page comes from `$Lang.title_*` keys (e.g. `title_login` 登录, `title_security` 安全中心,
`title_clientarea` 用户中心, `title_APIManage` API管理, `title_viewbilling` 账单内页).

### 9.2 Header bar (`#page-topbar`)
- Logo: `{$Setting.web_jump_url}`, `{$Setting.logo_url_home_mini}`, `{$Setting.web_logo_home}`
  (login-style pages use `{$Setting.web_logo}` instead).
- **Language switcher** (only `{if $Setting.allow_user_language}`): a dropdown over `{foreach $Language as $key=>$list}`
  → `?language={$key}` with flag `/upload/common/country/{$list.display_flag}.png` and label
  `{$list.display_name}`; current flag from `{$LanguageCheck.display_flag}`.
- Cart icon → `cart?action=viewcart` (badge markup is commented out).
- Bell → `message`, badge from `{$Setting.unread_num}` (adds `bx-tada` when non-zero).
- User dropdown (`{if $Userinfo}`): avatar initial from `{$Userinfo.user.username}` (CJK → first 3 chars,
  ASCII → 1 char uppercased); links `details` (个人信息), `security` (安全中心), `message` (消息中心),
  `verified` (实名认证, only `{if $Setting.certifi_open==1}`), `logout` (退出登录). Logged out → `/login` (请登录).
- Search dropdown is decorative (no handler).
- Hooks: `{php}$hooks=hook('client_area_head_output');{/php}` in `<head>` and
  `hook('client_area_footer_output')` in the footer; template-level hooks also seen:
  `template_custom_clientarea_captcha_html`, `template_after_service_domainstatus_selected`,
  `template_after_servicedetail_suspended`.

### 9.3 Side navigation (`includes/menu.tpl`)
- Rendered from `{foreach $Nav as $nv}` (3 levels) using `$nv.{url,fa_icon,name,child}`
  (+ optional `$nv.tag` for badges). `includes/menu.tpl` also has a **logged-out** hard-coded menu:
  `/clientarea` 首页, `/login` 登录, `/register` 注册, `/cart` 订购产品, `/news` 新闻中心,
  `/knowledgebase` 帮助中心, `/downloads` 资源下载. `app.js` auto-highlights the current item
  (`#sidebar-menu a`), with a `hidden_url` map so child pages keep the parent active:
  `/billing→/viewbilling`, `/service→/servicedetail`, `/supporttickets→/viewticket`,
  `/knowledgebase→/knowledgebaseview`, `/news→/newsview`, `/systemlog→/loginlog`.

### 9.4 Language & currency handling
- Backend locale files: `public/language/chinese.php` (中文简体), `chinese_tw.php`, `english.php`
  — a flat `$_LANG['key'] = '值'` map (1082 lines in the Chinese file), injected into the template as
  `$Lang` (`{$Lang.xxx}` / `{$Lang['xxx']}`) and into JS as `var language={:json_encode($_LANG)}`.
- Per-theme overrides live in `themes/clientarea/default/language/{chinese,english}.php` and must define
  at minimum `display_name` and `display_flag` (ISO-2 country code, uppercase; used for the flag image).
- Currency: templates never format amounts themselves except via `{$Currency.prefix}{$...}{$Currency.suffix}`
  (client area) / `{$Cart.currency.prefix}` / `$ShopData.currency` / `$Renew.currency` / `$Credit.prefix|suffix`
  / `$ConfigureTotal.currency`. Backing table `shd_currencies` (`code`,`prefix`,`suffix`,`format`,`rate`,`default`).
  `{$Lang.element}` = 元 is used where the currency is assumed to be CNY (e.g. billing combine hint).

### 9.5 Status values / enums (with Chinese labels)
**Host `domainstatus`** — DB enum, `shd_host.domainstatus`:
`enum('Pending','Active','Suspended','Cancelled','Fraud','Completed','Deleted')`.
Client-side labels come from `$_LANG['domainstatus_select_<lowercased key>']`:
| Value | `$Lang` key | 中文 |
|---|---|---|
| `Pending` | `domainstatus_select_pending` | 待开通 (SSL uses `pending1` = 待核验) |
| `Active` | `domainstatus_select_active` | 已激活 |
| `Suspended` | `domainstatus_select_suspended` | 已暂停 |
| `Cancelled` | `domainstatus_select_cancelled` | 被取消 |
| `Fraud` | `domainstatus_select_fraud` | 有欺诈 |
| `Deleted` | `domainstatus_select_deleted` | 被删除 |
| `Completed` | *(no label key)* | — (rendered via `domainstatus_desc` server-side) |
Additionally the templates use per-row `{$list.domainstatus_desc}` (server-rendered) and a CSS class
`status-{$list.domainstatus|strtolower}` → `.status-pending/.status-active/.status-suspended/.status-cancelled/.status-deleted`
defined in `assets_custom/css/global.css`.
`Cancelled` hosts carry `host_cancel.{type,reason}`: `type` ∈ `Immediate` (立即) / `Endofbilling`
(等待账单周期结束 — `$Lang.billing_cycle`).
Host suspend reasons: `host_suspend_due` 到期, `host_suspend_flow` 用量超额,
`host_suspend_uncertifi` 未实名认证, `host_suspend_other` 其他.

**Order `status`** — `shd_orders.status` comment:
`Pending 待审核, Active 已激活, Completed 已完成, Suspend 已暂停, Terminated 被删除, Cancelled 被取消, Fraud 有欺诈`.

**Invoice `status`** — `shd_invoices.status`:
| Value | label key | 中文 |
|---|---|---|
| `Paid` | `invoice_payment_status_paid` | 已支付 |
| `Unpaid` | `invoice_payment_status_unpaid` | 未支付 |
| `Refunded` | `invoice_payment_status_refunded` | 已退款 |
| `Cancelled` | `invoice_payment_status_cancelled` | 被取消 |
| `Draft` | `invoice_payment_status_draft` | 已草稿 |
| `Overdue` | `invoice_payment_status_overdue` | 已逾期 |
| `Collections` | `invoice_payment_status_collections` | 已收藏 |
Short list labels (`billing.tpl`): `未支付/已支付/被取消/已退款/已全部`. `billing.tpl` renders
`{$bill.status_zh.name}`; `invoicelist.tpl` renders `{$list.status_zh}`.
Credit-limit invoices use a separate set: `credit_limit_invoice_payment_status_{paid 已还款, unpaid 待还款,
prepayment 提前还款, overdue 已逾期}`.

**Billing cycle keys** — `$Lang.billing_cycle_*`:
| Key | 中文 | Key | 中文 |
|---|---|---|---|
| `free` | 免费 | `annually` | 年付 |
| `onetime` | 一次性 | `biennially` | 两年付 |
| `hour` | 小时 | `triennially` | 三年付 |
| `day` | 天 | `fourly` | 四年付 |
| `ontrial` | 试用 | `fively` | 五年付 |
| `monthly` | 月付 | `sixly` … `tenly` | 六年付 … 十年付 |
| `quarterly` | 季付 | | |
| `semiannually` | 半年付 | | |
The short forms (`$Lang.monthly` 月, `quarterly` 季, `semiannually` 半年, `annually` 年, `biennially` 两年,
`triennially` 三年, `onetime` 一次性, `free` 免费, `ontrial` 试用, `hour` 小时, `day` 天) are used in
list cells. Product pay type: `product_paytype_{free 免费, onetime 一次性, recurring 周期}`.

**Invoice item `type`** — `$Lang.invoice_type_*` (long) and `invoice_type_all_*` (filter labels):
`product/host 产品`, `renew 续费`, `setup 初装费`, `upgrade 产品升降级`, `down 降级`,
`packet/zjmf_flow_packet 流量包`, `zjmf_reinstall_times 重装次数`, `combine 合并账单`,
`voucher 发票`, `credit_limit 信用额`, `transfer_fee 产品转移费`, `recharge 充值`, `promo 优惠码`,
`discount 客户折扣`, `express 快递费`, `contract 合同邮费`, `certifi_person/company 个人/企业实名认证`.
`shd_invoices.type` comment names the coarse set: `recharge, product, renew`.

**Product types** — `$Lang.product_type_*`: `hostingaccount 虚拟主机`, `server 独立服务器`,
`cloud 云服务器`, `dcimcloud 魔方云`, `dcim 魔方DCIM`, `bare_metal 裸金属`, `software 软件产品`,
`cdn CDN`, `other 其他服务`, `ssl ssl证书`, `domain 域名`, `sms 短信服务`. These are exactly the
`$Detail.host_data.type` dispatch values in `servicedetail.tpl`.

**Client status / certification** — `client_status_{off 停用, on 正常, close 关闭}`;
`client_certifi_status_{0 未认证, 1 已认证, 2 未通过, 3 待审核, 4 提交资料}`.
Cancel requests: `cancel_requests_status_{0 未执行, 1 已执行, 2 已执行但失败}`.

**Tickets** — statuses are **DB rows** in `shd_ticket_status` (`title`,`color`,`order`,
`show_active`,`show_await`,`auto_close`); templates only use `{$ticket.status.color}` and
`{$ticket.status.title}` plus the `id != 4` closed check. Priority uses `Medium` as the default key
(`{if $key=='Medium'}`) with keys lower-cased on submit. Affiliate withdraw status:
`1 待审核 / 2 审核通过 / 3 拒绝`; affiliate record `is_aff` → 已确认/待确认.

**Power status** (module level, from `/provision/default` `func=status`):
`on`, `off`, `unknown`, `process`, `waiting`, `suspend`, `wait_reboot`, `wait`, `cold_migrate`, `hot_migrate`
→ mapped in `getPowerStatus()`/`setColor()` to `.on_color`, `.off_color`, `.unknown_color`, `.ing_color`,
`sprite start/closed/waitOn/pause/waiting`, `iconfont icon-shujuqianyi`.

**DCIM reinstall task types** (`/dcim/resintall_status` → `data.task_type`): `1` 救援, `2` 破解, `3` 获取,
else 重装.

**Message types** (`shd_system_message.type`): `1` 工单消息, `2` 产品消息, `3` 站内消息, `4` 活动消息;
`is_market` 营销信息.

**Icon/shape conventions to preserve**
- `type` on download rows: `1` = zip (`mdi-folder-zip text-warning`), `2` = image (`mdi-image text-success`),
  `3` = text (`mdi-text-box text-muted`).
- OS icons: `/upload/common/system/{$svg}.svg` with numeric fallbacks 1 Windows / 2 CentOS / 3 Ubuntu /
  4 Debian / 5 ESXi / 6 XenServer / 7 FreeBSD / 8 Fedora / 9 other (see `servicedetail.tpl`
  `cloud_os_group` loop and `configureproduct.tpl`).
- Country flags: `/upload/common/country/{$iso}.png`.

### 9.6 JS / CSS libraries in the theme
`includes/head.tpl` loads the baseline:
- CSS: `assets/css/bootstrap.min.css`, `assets/css/icons.min.css`, `assets/css/app.min.css`,
  plus `load_css('custom.css')` hook, `assets_custom/css/global.css`, `assets_custom/css/responsive.css`,
  and the icon font `assets_custom/fonts/iconfont.css`.
- JS: `assets/libs/jquery/jquery.min.js`, `assets/libs/bootstrap/js/bootstrap.bundle.min.js`,
  `assets/libs/metismenu/metisMenu.min.js`, `assets/libs/simplebar/simplebar.min.js`,
  `assets/libs/node-waves/waves.min.js`, `assets_custom/js/throttle.js`.
- Toastr CSS+JS (`assets/libs/toastr/build/toastr.min.js`) — used for all `toastr.success/error`.
- `assets/js/app.js` in the footer (menu activation, sidebar toggle, language click, Waves init;
  contains dead code for `my.idcsmart.com` / `my.doopcloud.com` developer/resource-pool menus).

Loaded per feature:
| Library | Path | Used by |
|---|---|---|
| Bootstrap 4.5.3 / jQuery 1.12.4 / FontAwesome 5.10.1 | (`theme.config`) | all |
| ECharts | `assets/libs/echarts/echarts.min.js` | clientarea, credit, apimanage, service charts, `includes/chart.tpl` |
| bootstrap-select | `assets/libs/bootstrap-select/…` | status filters, OS selects, `includes/tablestyle.tpl` |
| bootstrap-touchspin, bootstrap-rating | `assets/libs/…` | ticket star rating (`bootstrap-rating` + `assets/js/rating-init.js`) |
| moment | `assets/libs/moment/moment.js` | renew/DCIM date formatting |
| ClipboardJS | `assets/libs/clipboard/clipboard.min.js` | IP / password / referral-link copy |
| qrcode | `assets/libs/qrcode/{qrcode.min.js,jquery.qrcode.min.js}` | pay QR, `verified` QR |
| html2canvas + jsPDF | `assets/libs/{html2canvas,jspdf}` | invoice PDF download |
| summernote | `assets/libs/summernote/…` + `includes/summernote.tpl` | ticket reply/submit rich text |
| bootstrap-markdown-editor + ace + marked | `assets/libs/markdown-editor/…` + `includes/markdown.tpl` | alternative markdown editor |
| Dropzone | `assets/libs/dropzone/min/dropzone.min.js` | attachment widgets |
| toastr | `assets/libs/toastr/build/toastr.min.js` | global messaging |
| simplebar / metismenu / node-waves | `assets/libs/…` | sidebar |
| CryptoJS (vendored) | `assets/js/crypto-js.min.js` | client-side AES password encryption |
| jSignature | `assets/js/jSignature.js` | contract signature (out of scope) |
| dcimcloud vendor | `{$Request.domain}{$Request.rootUrl}/vendor/dcimcloud/{css/selectFilter.css,js/sweetAlert2.min.js,js/selectFilter.js}` | service detail panels |
| Cart theme | `themes/cart/default/assets/js/{configureproduct.js,viewcart.js,toastr,bootstrap-select,ion-rangeslider,masonry,RangeSlider.js}` | order flow |

Theme-level page scripts (client area):
`assets/js/{public.js (auth/captcha/encrypt), app.js, modal.js (confirm + 二次验证), security.js, servicedetail.js,
billing.js, addfunds.js, verified.js, rating-init.js, crypto-js.min.js, jSignature.js}`.

---

## 10. Practical notes for a Laravel re-implementation

1. **URL compatibility**: keep bare relative URLs (`details`, `security`, `service?groupid=`, not `/details`)
   exactly as-is — templates and `app.js` compare `window.location.href` against the `$Nav` entries to
   decide the active menu item, and `viewbilling.tpl` matches on the string `"viewbilling"` in the URL.
2. **`?action=` multiplexing** must be preserved per page (see §0.1) — it is part of the public contract
   for `/clientarea`, `/service`, `/servicedetail`, `/billing`, `/transaction`, `/message`, `/affiliates`,
   `/supporttickets`, `/submitticket`, `/viewticket`, `/downloads`, `/cart`, `/pay`, `/verified`.
3. **HTML-fragment AJAX**: many endpoints return rendered HTML, not JSON
   (`/clientarea?action=list`, `/servicedetail?action={renew,billing_page,log_page,upgrade_page,
   upgrade_configoption_page,upgrade,upgrade_config,flowpacket}`, `/pay?action=billing|recharge`,
   `/affiliates?action=…`, `/provision/custom/content`). Responses are injected with `.html(data)`,
   so the Laravel version must return Blade renderings of the same partials.
4. **Field-name compatibility matters most for**: `configoption[{id}]`, `customfield[{id}]`,
   `fields[{id}]`, `custom[{id}]`, `customfield[{id}]` (tickets), `host_ids[]`, `cycles[hospid]`,
   `ids[index]`, `domain_status[]`, `attachments[]`, `billingcycle(s)`, `paymt`, `use_credit`,
   `use_credit_limit`, `pormo_code` (sic), `remark`, `initiative_renew`.
5. **Watch the deliberate typos** carried in the original: `$CartConfig.dafault_currencyid`,
   `pormo_code`, `hash` param `rebackd` on `combinebilling.tpl`, `delete_messgage`/`read_messgage`
   routes (double `g`), and the `id` vs `hid` inconsistency for host ids (`servicedetail?id=` but
   `upgrade/checkout_*` takes `hid`).

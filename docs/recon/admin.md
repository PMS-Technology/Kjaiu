# 智简魔方财务 (ZJMF / IDCSmart finance) v3.7.6 — Admin Panel Reverse-Engineering Spec

Source of truth: the compiled admin SPA under `/www/wwwroot/mfcw.782778.xyz/public/admin123/`
(read-only), cross-checked against `docs/recon/routes.tsv`, `docs/recon/admin_menu.txt`
and `docs/recon/schema_install.md`. Nothing here was verified by calling the live API.

---

## 0. How the admin SPA is put together

### 0.1 Boot

`public/admin123/index.html` loads `js/config.js`, a language pack (`lang/zh.js`, or
`lang/<zjmf_lang_file_name>.js` from localStorage), then the webpack runtime chunk
`js/app~5a11b65b.edb7e1e9.js`, the app shell `js/app~d0ae3f07.dc550a27.js`, and
`js/app~40302777.2c46df08.js`. Every other page is a lazily-loaded chunk whose file name
is derived from the Vue component chunk name (e.g. `CustomerList~31ecd969.c3782fdc.js`).

```js
// config.js
window.global = 'https://w1.test.idcsmart.com'   // install-time value, not used at runtime
window.directory = 'admin'
```

### 0.2 Request layer (`module a27e`, in the runtime chunk)

* Axios instance: `axios.create({ baseURL: <module dee4>.a, timeout: 6e6 })`,
  `defaults.withCredentials = true`.
* `module dee4` resolves the base URL as `"production" === env ? (window.global || ".") : "/api"`.
  In the shipped production build the constant is `"production"`, so the SPA calls
  **relative URLs** — i.e. `url:"client_list"` means `POST /admin123/client_list`.
* Every request gets `params.request_time = Date.now()` and
  `params.languagesys = localStorage.getItem('zjmf_lang_type')`.
* Requests are de-duplicated by a signature (method + url + stringified params/data);
  a duplicate in flight is cancelled with `取消重复请求`, except for
  `admin/zjmf_finance_api/upstreamhost` and any url containing `admin/options/edit_config/`.
* Any data/params value that is a 13-digit number divisible by 1000 is divided by 1000
  before sending (i.e. **all timestamps are sent as seconds**).
* Response envelope: `{ status, msg, data, rule? }`.
  * `200` success; `rule` (if present) is written to `localStorage.menuList`.
  * `302` → `location.href = "/install"`; `307` → route to `/system-message`;
    `404` → `/404`; `405` → `/login`; `409` → `/`; `500` → alert `访问失败, 请重试!`;
    `400/403/406/410/422/501-505` are no-ops.
  * `401` → redirect to `/forbidden` with `{name, method}`, except for
    `identify` values `certifi_person_detail`, `clients_services`, `list_ticket_status`,
    `download_ticket_attachment` which pass through.
* API modules are tiny ES modules exporting one function per endpoint, e.g.

```js
// module e52a — 供应商 (upstream) API
function n(t){ return Object(a["a"])({ url:"zjmf_finance_api", method:"post", data:t }) }   // .a
function s(t){ return Object(a["a"])({ url:"zjmf_finance_api", method:"put",  data:t }) }   // .f
function o(t){ return Object(a["a"])({ url:"zjmf_finance_api/".concat(t) }) }                // .c
function i(t){ return Object(a["a"])({ url:"zjmf_finance_api/".concat(t), method:"delete" }) }//.b
function l(t){ return Object(a["a"])({ url:"zjmf_finance_api", params:t }) }                 // .d
function c(t){ return Object(a["a"])({ url:"zjmf_finance_api/".concat(t,"/status") }) }      // .e
function u(t){ return Object(a["a"])({ url:"zjmf_finance_api/upstreamcredit", params:t }) }  // .g
```

Throughout this document `GET x {...}` means query-string params, `POST x {...}` means a JSON body.

### 0.3 Route → chunk → module map (how to find a page)

The router lives in the runtime chunk. Entries look like:

```js
{ path:"commodity-list", name:"commodityList",
  component: () => Promise.all([n.e("ConfigureEdit~commodityList~31ecd969"), n.e("commodityList~31ecd969")])
                    .then(n.bind(null,"e7ca")) }
```

So `e7ca` is the component module id and the two chunk names are the files to read.
**Modules are keyed by id inside a chunk**, and the same numeric id can exist in different
chunks with different content — always resolve an id inside its own file.

Full route table (228 route definitions; the table below is abridged for the low-priority
modules — the exhaustive list is in the appendix tooling output) extracted from the runtime chunk:

| route name | path | module |
|---|---|---|
| login | /login | 9ed6 |
| Forbidden | /forbidden | 1227 |
| 404 | /404 | 75b4 |
| 500 | /500 | 7af1 |
| home | / | 7abe |
| homePage | home-page | 0537 |
| customerList | customer-list | 41f5 |
| customerView | customer-view | 5a39 |
| abstract | abstract | cc92 |
| abstractOld | abstractOld | 114e |
| person | person | 5899d |
| productList | product-list | bc20 |
| productInnerpage | product-innerpage | dda3 |
| bill | bill | 3d9c |
| transactions | transactions | 2e73 |
| credit | credit | 6334 |
| tickets | tickets | fd26 |
| log | log | b8ce |
| noticelog | noticelog | b9e7 |
| annex | annex | a505 |
| smslog | smslog | a951 |
| emaillog | emaillog | 9965 |
| developer | developer | de9a |
| customerAdd | customer-add | 7542 |
| CustomerApiOverview | api-overview | fe5e |
| customerGroup | customer-group | 50b8 |
| customerCustom | customer-custom | 9094 |
| CustomerLevel | customer-level | c184 |
| CustomerAuthentication | customer-authentication | 44d25 |
| CustomerResources | customer-resources | 3f74 |
| CustomerPromotionplan | customer-promotionplan | 07e9 |
| CustomerCancelReq | customer-cancelreq | 551f2 |
| orderList | order-list | 4707 |
| addOrder | add-order | a673 |
| orderDetail | order-detail | 6c3c |
| renewalOrder | renewal-order | d2a8 |
| supplierRenewalOrder | supplier-renewal-order | 035f |
| supplierOrderList | supplier-order-list | fb63 |
| customerProduct | customer-product | 2e50 |
| billManagement | bill-management | 6dec |
| billDetail | bill-detail | 9e24 |
| businessStatement | business-statement | c9e0 |
| CreditManagement | credit-management | f825 |
| CreditSrtting | credit-setting | 08cc |
| WithdrawalAudit | customer-withdrawal | 4d11 |
| supportTicket | support-ticket | 8a83 |
| supportTicketDetail | support-ticket-detail | f1234 |
| addSupportTicket | add-support-ticket | 85e3 |
| presetReply | preset-reply | d874 |
| addeditPreReply | addedit-pre-reply | 57e4 |
| workOrderDept | work-order-dept | e653 |
| newWorkOrderDept | new-work-order-dept | 40ef |
| workOrderStatus | work-order-status | 5a4e |
| WorkOrderRules | work-order-rules | 47bf |
| productServer | product-server | 1cb9 |
| editProduct | edit-product | 00bc |
| addProductGroup | add-product-group | 9493 |
| configurableOption | configurable-option | 14c2 |
| editConfigurableOptionGroup | edit-configurable-option-group | c0e4 |
| EditConfigurableOption1 | edit-configurable-option1 | 46fe |
| serverSettings | server-settings | 376e |
| addServer | add-server | 1f7a |
| addInterface | add-interface | 67a5 |
| groupList | group-list | 0057 |
| addGroup | add-group | 0108 |
| zjmf-api | zjmf-api | 1c84 (ZjmfApi~31ecd969) |
| add-supplier | add-supplier | ef86 (addSupplier~f71cff67) |
| commodityList | commodity-list | e7ca |
| commodityProduct | commodity-product | ced8 |
| commodityTaskQueue | commodity-taskQueue | 14b6 |
| TaskQueue | task-queue | c95f |
| ConfigureEdit | configure-edit | e04a (+ shared chunks) |
| api-setup | api-setup | fdb8 |
| munualResource | munual-resource | 7179 |
| upStreamEdit | upStream-edit | 94d8 |
| addOrEditResource | addOrEdit-resource | b002 |
| generalSettings | general-settings | ba24 |
| general | general | ff7c |
| twiceConfirm | twice-confirm | 2b45 |
| automaticTasks | automatic-tasks | f4f4 |
| timingResults | timing-results | 1185 |
| currencySettings | currency-settings | faa5 |
| paymentInterface | payment-interface | ae17 |
| promoCode | promo-code | 729a |
| promoCodeAdd | promo-code-add | 5486 |
| permissionsManagment | permissions-managment | 42e6 |
| permissionsEdit | permissions-edit | 7db2 |
| adminManagement | admin-management | b98d |
| adminEdit | admin-edit | 343a |
| emailList / emailEdit | email-list / email-edit | bd23 / 45d6 |
| smsTemplate | sms-template | b459 |
| smsTemplateIndex | sms-template-index | dd3a |
| smsSendSettings | sms-send-settings | 4cc7 |
| marketing-push | marketing-push | 3f33 |
| promotion_plan | promotion_plan | 479b / 9902 |
| newsList / addNews / newsCategory | news-* | e147 / 7100 / 52a5 |
| helpList / addHelp / helpCategory | help-* | 5839 / 5112 / 3b00 |
| service-support / file | service-support / file | 4374 / e494 |
| annual-statistics / new-customer / product-revenue / revenue-ranking | — | 4ce9 / 6e88 / e46f / 4c2b |
| dcim, dcim-traffic, dcim-traffic-log, zjmfcloud, … | — | 2229 / 8e8d / 71f4 / f01c … |
| app-store, app-list, my-app, app-detail, app-inner | — | ca5f / 15fc / 5fe8 / 915b / 69c2 |
| contracts_audit / contracts_setting / add_contract | — | 4cdc / 4e28 / 780a |

`generalSettings` (module `ba24`) is a **pure tab container** (single method `handleClick`)
that renders the child routes `general`, `local`, `support`, `promote`, `safe`, `other`,
`invoice`, `finance`, `captcha`, `source-api`, `second`, `class`, `order`,
`voucher-setting`, `login-setting`, `captcha`, `sourceApi`.

---

## 1. 客户 (Customers)

### 1.1 客户列表 — `/customer-list` (`CustomerList`, module `41f5`)

Purpose: search/browse all clients; click a name to open the client detail shell
(`/customer-view/abstract?id=<uid>`).

**List columns** (`el-table-column prop`)

| prop | label |
|---|---|
| `id` (width 70, center) | ID |
| `username` | 姓名 (link to `/customer-view/abstract?id=`) |
| `phonenumber` | 手机号/邮箱 |
| `host_total` | 服务 |
| `amount_in` | 收入/支出 (width 115; sortable, sorted client-side by `amount_in`/`amount_out` special-case) |
| `credit` | 余额 |
| `group_name` | 客户分组 (width 135, center) |
| `status` | 状态 (width 85, center) |
| `user_nickname` | 销售 |
| `credit_limit` | 信用额（已用/总计） (width 200) |
| `create_time` | API开通时间 (width 135, center) |

**Toolbar:** `添加客户` button → `/customer-add`; `高级搜索/收起搜索` toggle.

**Advanced search** — the form is generated from `searchOptions`, which comes from the
server (`getData` → `GET client_list` resp. `r.search` is `{key: 中文label}` and
`r.seachData` supplies the option lists — note the typo `seachData` in the API).

Search model (`search`), all sent to `POST client_list`:

```
page, limit (=localStorage.limit || 50), order ("id"), sort ("DESC"),
username, companyname, email, phonenumber, status, qq, custom, level,
api_status, sale, client_groups, certifi
```

Special filter widgets keyed by `searchOptions[].value`:
* `status` → `el-select` over `StatusOptions = [{label:正常,value:1},{label:禁用,value:0}]`
* `api_status` → `el-select` over `apiDtatusOptions` (server-supplied map from `common`… returned by `client_list`)
* `sale` → `el-select` filterable over `searchData.sale` (`label = user_nickname`, `value = id`)
* `client_groups` → `el-select` filterable over `searchData.client_groups` (`label = group_name`, `value = id`)
* `level` → `levelOptions` from `level_search`, with a synthetic `{id:999, level_name:全部}`
* everything else → `el-input` (`请输入关键字`, Enter triggers search)
* fixed extra item: `实名状态` → `search.certifi`, options `1=已实名`, `0=未实名`

**Endpoints**

| method | url | params | used by |
|---|---|---|---|
| POST | `client_list` | body = `search` | `getData` |
| POST | `searchfornamelist` | body = advanced-search payload | `adSearch`, `adSizeChange` |

Response fields consumed: `list`, `total`, `sum`, `search` (label map), `seachData`
(option map), `allow_resource_api`, `api_status`, `level_search`.

Also imports `$store.state.searchObj` (Vuex) so the advanced search survives
navigating into a client and back; `beforeDestroy` resets `searchQuery`/`setAdFlag`.

### 1.2 客户详情外壳 — `/customer-view` (`CustomerView`, module `5a39`)

Shell with a left `el-menu` (when `type` query is set) and an `el-tabs` header.
Tabs (label → route `name`):

`abstract` 客户摘要 · `developer` 开发者信息 (only if `developer`) · `person` 个人资料 ·
`productList` 产品/服务 · `bill` 账单 · `transactions` 交易记录 ·
`credit` 信用管理 (only when `edition==1 && license_type==1`) · `tickets` 工单 ·
`log` 日志 · `CustomerApiOverview` API概览 · `noticelog` 通知日志 ·
`annex` 附件 · `promotion_plan` 推介计划 (if `showPromanPlan`) ·
`followstatus` 跟进状态. Left menu also links `follow-status` (跟进状态).

**Endpoints**

| method | url | params |
|---|---|---|
| GET | `get_user` | `params` = `{id}` — `getCustomerBaseInfo`, `forcedRefresh`, `getCustomerInfo` |
| GET | `clients_services` | `{uid, hostselect}` — `getCustomerPro` |

The header shows `pageData.id / username / companyname`.

### 1.3 客户摘要 — `/customer-view/abstract` (`CustomerAbstract`, module `cc92`)

Combines three sub-components in one module: summary cards, profile-edit cards and the
verification (实名) card. Data collected into:

```
personInfo: { summary:{}, customfields:[], plugins_oauth:[] }
accounts_count, sms_countryOptions
cwBackBg: { totalIn, totalOut, noPay, upcomingPro, alredyPro, balance, Credit }
sale_idOptions, client_groupsOptions, client_statusOptions
customerForm: { password:"", sale_id, initiative_renew:0 }
customerFormRules (username required; email/postcode/phonenumber regex)
creditLimitData, unpaid:{total,num}, statusSwitch
certificationInfo: { type, company_name, company_organ_code, name, idcard, ... }
personInfoInput.<field>: { icon, input, text }   // per-field inline edit toggles
personInfoInput.companyName / .businessLicense / .submitPeopleName / .cardNumber /
  .createTime / .lastLoginTime
rechargeAmount
```

**Editable summary fields** (all `el-input`, saved via `POST profile_post`):

| v-model | meaning |
|---|---|
| `summary.companyname` | 公司名 |
| `summary.qq` | QQ |
| `summary.phonenumber` | 手机 |
| `summary.email` | 邮箱 |
| `other_info.groupname` | 客户分组 |
| `other_info.register_time` | 注册时间 |
| `other_info.last_login_ip` | 最后登录 IP |
| `summary.saler` | 销售 |
| `personInfo.summary.notes` | 备注 (textarea, `@blur` → `POST post_client_notes`) |

Verification card (实名) fields: `personInfoInput.companyName.text` (`certificationInfo.company_name`),
`personInfoInput.businessLicense.text` (`company_organ_code`), `certificationInfo.name`,
`certificationInfo.idcard`. Status toggle: `el-switch value=statusSwitch` → `GET close_client/<uid>`.

**Dialogs:** `创建充值账单` (title `创建充值账单`, `dialogVisible`) with
`el-input-number rechargeAmount` (precision 2, controls off) → `POST add_recharge_invoice/<uid>`.

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `credit_limit` | `{uid}` | `creditLimit` |
| POST | `credit_limit` | body | create credit line |
| PUT | `credit_limit` | body | update credit line |
| DELETE | `credit_limit` | `{uid}` | remove credit line |
| GET | `credit_limit/log` | params | credit change log |
| GET | `credit_limit/user_invoice` | params | credit invoices |
| GET | `credit_limit/user_invoice_detail` | params | invoice detail for credit |
| GET | `certifi_person_detail/<uid>` | — | 实名信息 |
| POST | `certifi_status` | `{uid,status,…}` | approve/reject 实名 |
| GET | `profile/<uid>` | — | profile |
| GET | `profile/getclients/<uid>` | params | – |
| POST | `profile_post` | body (see above) | save profile |
| GET | `summary?client_id=<uid>` | — | summary card data |
| GET | `login_by_user/<uid>` | — | **login-as-client** (returns a JWT/URL, used by `getJwt`) |
| GET | `common` | — | get platform info (`getCommon`) |
| POST | `add_user_invoice` | body | create an ad-hoc invoice |
| POST | `add_recharge_invoice/<uid>` | body | create a recharge invoice |
| GET | `close_client/<uid>` | params | enable/disable the client account |
| GET | `delete_client/<uid>` | — | delete client |
| POST | `post_client_notes` | body | save admin notes |

Methods present: `toAddRecords, goOrderView, formatDate, creditLimit, notesChangeSubmit,
goAddOrder, getMsgData, getCustomData, changeSwitchStatus, CompanySureEnter, editUserData,
getData, getJwt, rightHandleClick, getCommon, personDetail, addBillHandleClick,
creatBillHandleClick, toBalance, goToProduct, closeCustomer, deleteCustomer, changeStatus`.

### 1.4 客户个人资料 — `/customer-view/person` (`person`, module `5899d`)

Edit form (`customerForm`) with `姓名 username`, `性别 sex` (`sexOptions` from
`客户新增`-style list: 0 未知 / 1 男 / 2 女), `公司 companyname`, country/province/address,
postcode, phone (`phone_code` + `phonenumber`), email, qq, password, `defaultgateway`,
`language`, `sale_id`, `groupid`, `status`, `notes`,
`marketing_emails_opt_in`.

| method | url | params |
|---|---|---|
| GET | `profile/getclients/<uid>` | `{params}` |
| GET | `profile/<uid>` | — |
| POST | `profile_post` | body |
| GET | `common/get_getways` | — (payment gateways) |
| GET | `common/get_client_groups` | — |
| GET | `common/get_sms_country` | — |

### 1.5 新增客户 — `/customer-add` (`CustomerAdd`, module `7542`)

`customerAddForm` defaults:

```js
{ marketing_emails_opt_in: 1, password: "", status: 1, language: "zh-cn",
  phone_code: 86, defaultgateway: undefined, groupid: "", country: "中国",
  province: "", sale_id: 0, initiative_renew: 0, username: "", sex: "",
  companyname: "", address1: "", postcode: "", know_us: "", phonenumber: "",
  email: "", qq: "", notes: "" }
```

Form fields (prop → v-model): `姓名 username`, `性别 sex`, `所在公司 companyname`,
`国家 country`, `省 province`, `地址 address1`, `邮编 postcode`, `了解途径 know_us`,
`手机 phonenumber` (+ `phone_code` prepend select), `邮箱 email`, `QQ qq`,
`密码 password`, `支付方式 defaultgateway`, `语言 language`, `销售 sale_id`,
`客户分组 groupid`, `状态 status`, `管理员备注 notes`,
`marketing_emails_opt_in` (el-switch, active-text 接收营销信息).
Dynamic custom fields come from `customForm[item.id]` driven by `customList`
(`el-input` / password / textarea / `el-select` per `item.type`).

`sexOptions`: `{value:"0",label:?},{value:"1",label:男},{value:"2",label:女}`.

| method | url | params |
|---|---|---|
| GET | `create_client` | — → returns form metadata (`customList`, `languageOptins`, …) |
| POST | `create_client_post` | body = `{customerAddForm…, custom:<customForm>, …}` |
| GET | `common/get_getways` | — |
| GET | `common/get_client_groups` | — |
| GET | `common/get_sms_country` | — |

### 1.6 客户分组 — `/customer-group` (`customerGroup`, module `50b8`)

Three tabs: `客户分组` / `商品分组` / `折扣设置`.

* 客户分组 table: `group_name` 客户组名称, 组颜色, 操作. Add/edit dialog fields
  `addCustomerArray.name` 客户组名称, `addCustomerArray.color` 组颜色 (color picker).
* 商品分组 table: `group_name` 组名称, 操作. Form: `formData.group_name` 组名称,
  `formData.pids` 选择商品 (multi-select of product ids).
* 折扣设置 tab calls `getDiscount`.
Methods: `openDoc, changeTypr, getDiscount, handleSizeChange, currentChange, editPro, delPro,
onOpen, onClose, close, handelConfirm, addProduct, getProduct, onFirstOpen, addGroupVis,
pickerColor, getData, groupEdit, deletePrompt, addGroup, eidGroup, groupAdd, cancel, closed`.

### 1.7 客户等级 — `/customer-level` (`CustomerLevel`, module `c184`)

Table: `level_name` 客户等级, `expense` 收入, `buy_num` 购买商品数量,
`login_times` 累计登录次数, `login_times` 最近登录次数 (second column reuses the prop),
`renew_times` 续费次数, `last_renew_times` 最近续费次数, 操作.

Dialog fields: `level_name` 客户等级, `expense_min`/`expense_max` 收入(大于/小于),
`buy_num_min`/`buy_num_max` 购买商品数量(大于/小于), plus login/renew thresholds
(`login_times_min/max`, `renew_times_min/max`, `last_*`).

### 1.8 自定义客户字段 — `/customer-custom` (`customerCustom`, module `9094`)

`form` defaults: `{ addfieldtype:"dropdown", addadminonly:0, addrequired:0,
addshoworder:0, addshowinvoice:0 }`.

Row editing (`item.*`) and add-row (`form.add*`) use the same shape:
`fieldname` 字段名称, `fieldtype` 字段类型 (`type_list` map),
`description` 描述, `regexpr` 验证, `fieldoptions` 选项 (only when `fieldtype === "dropdown"`),
`sortorder` 显示排序, plus `其他设置` toggles `adminonly` / `required` / `showorder` / `showinvoice`.

### 1.9 实名认证 — `/customer-authentication` (`CustomerAuthentication`, module `44d25`)

实名认证审核列表. Related endpoints live in the customer API module (`f6b0`):

| method | url | notes |
|---|---|---|
| GET | `certifi_person_detail/<uid>` | 实名详情 |
| POST | `certifi_status` | `{uid,status}` approve/reject |

See also 1.3 (`certifi_person_detail`, `certifi_status`).

### 1.10 客户资源池 — `/customer-resources` (`CustomerResources`, module `3f74`)

Endpoints in the same chunk:

| method | url | params |
|---|---|---|
| GET | `credit_limit/client_list` | params |
| GET/POST/DELETE | `client_list` | — |

(Also reused by `CreditManagement` / `CreditSrtting`.)

### 1.11 我的业绩 / 统计

* `/sales-statistics` (`SalesStatistics`, module `27cf`) — `app\admin\controller\SaleController::saleStatistics`.
* `/customer-promotionplan` (`CustomerPromotionplan`, module `07e9`) — `AffiliateController`.
* `/marketing-push` (`MarketingPush`, module `3f33`) — batch SMS/email push
  (`SendMessageBatchController`), followed by `/message-write`.

### 1.12 Customer API surface (module `f6b0`, chunk `BalanceDetails1~….js`)

This single module is the shared client API and is imported by most customer pages:

| export | fn | method | url |
|---|---|---|---|
| r | `r(e)` | POST | `client_list` (data) |
| t | `i(e)` | GET | `summary?client_id=<e>` |
| o | `o()` | GET | `create_client` |
| a | `c(e)` | POST | `create_client_post` |
| s | `s(e)` | GET | `profile/<e>` |
| u | `u(e,t)` | GET | `profile/getclients/<e>` (params) |
| n | `l(e)` | POST | `profile_post` |
| m | `d(e)` | GET | `delete_client/<e>` |
| h | `m(e)` | GET | `close_client/<e>` |
| k | `f(e)` | GET | `user_invoice` (params) |
| i | `p(e,t)` | GET | `close_client/<e>` (params) |
| g | `g(e)` | GET | `client_ticket` (params) |
| f | `b(e)` | GET | `log_record` (params) |
| d | `h(e)` | GET | `zjmf_finance_api/logs` (params) |
| w | `_(e)` | GET | `login_by_user/<e>` |
| z | `v(e)` | GET | `certifi_person_detail/<e>` |
| c | `O(e)` | POST | `add_user_invoice` |
| b | `$(e)` | POST | `add_recharge_invoice/<e.uid>` |
| q | `j(e)` | GET | `get_user` (params) |
| C | `y(e)` | GET | `request_cancel_list` (params) |
| l | `w(e)` | DELETE | `request_cancel_list/<e>` |
| D | `x(e)` | GET | `searchlist?value=<e>` |
| v | `k(e)` | GET | `hostbyuid` (params) |
| A | `D(e)` | POST | `clients_services/host_batch_renew_page` |
| B | `z(e)` | POST | `clients_services/host_batch_renew` |
| e | `C(e)` | POST | `clients_services/apply_credit` |
| x | `L(e)` | GET | `invoice/paid` (params) |
| p | `R(e)` | GET | `get_combine_invoices` (params) |
| j | `P(e)` | POST | `combine_invoices` |
| y | `q(e)` | POST | `post_client_notes` |

---

## 2. 业务 (Products / Services)

### 2.1 产品订单 — `/order-list` (`OrderList`, module `4707`)

**Columns**

| prop | label |
|---|---|
| (selection) | — width 55 |
| `id` | ID (60, center) |
| `username` | 客户名 |
| `hosts` | 产品 (width 13) |
| — | IP (120) |
| `create_time` | 下单时间 (135, center) |
| `amount` | 金额 (120) |
| — | 付款状态/付款方式 (150) |
| `status` | 状态 (80) |
| `order_notes` | 客户备注 |
| `sum` | 提成/销售 (150) |

Tabs are built from a server-supplied `{label, value}` list (`tabsSearch`).

**Search model:**

```
id, username, uid, sale_id, pay_status, time (datetimerange, ts),
status, amount, payment, page, limit, order, sort
```

Filters: `订单ID` (`search.id`), `客户` (`search.username`, autocomplete
`GET order/getclients {username}`), `销售` (`search.sale_id`), `付款状态`
(`search.pay_status`), `时间` (`searchTime`, `datetimerange` value-format timestamp),
`状态` (`search.status`), `金额` (`search.amount`), `付款方式` (`search.payment`).

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `order/search_page` | params | `orderSearchPage` |
| GET | `order/search` | params | `getData` |
| GET | `order/getclients` | `{username}` | `querySearchAsync` |
| POST | `order/order_commission` | body | `getSum`, `getAdSum` (commission totals) |
| GET | `order/check` | params (usually `{id}`) | `examPassHandleClick` — **accept/审核通过** |
| GET | `order/cancel` | params | `cancelOrderHandleClick` — cancel |
| DELETE | `orders/delete` | params | `deleteOrderHandleClick` — delete |
| POST | `searchfornamelist` | body | advanced-search list |

Other methods: `creatOrderHandleClick` (→ `add-order`), `sendMessageHandleClick`,
`toDetailPage` (→ `order-detail`), `handleSelectionChange`, `sortChange`,
`clearUid`, `handleSelect`, `dateSelectChange`, `tabsSearch`, `adSearch`, `adSizeChange`.

### 2.2 订单详情 — `/order-detail` (`OrderDetail`, module `6c3c`)

Reuses the shared order API chunk: `order/check`, `order/cancel`, `order/create_page`,
`orders/delete`.

### 2.3 新建订单 (为客户下单) — `/add-order` (`AddOrder`, module `a673`)

Form model:

```
formData: { username, uid, payment, promo_code, promo_code_id?, products: [...] }
productItem: { id, cycle, qty, interior_price, interior_price_renew, configoption: [...] }
optionItem / configItem / configItemTwo: { option_value, option_id, ... }
```

Fields: `客户` (`formData.username`, autocomplete `order/getclients`),
`支付方式` (`formData.payment`), `优惠码` (`formData.promo_code`),
`产品/服务` (`productItem.id` → `productSelectChange` / `orders/set_config`),
`付款周期` (`productItem.cycle`), `数量` (`productItem.qty`),
`内部价格(首次)` (`productItem.interior_price`), `内部价格(续费)`
(`productItem.interior_price_renew`), plus per-config-option widgets driven by
`option_type` (`el-input-number`, `el-select`, `el-cascader`, `el-slider`).

**Endpoints**

| method | url | params |
|---|---|---|
| GET | `order/create_page` | params — form metadata |
| GET | `order/promo_code_page` | — |
| GET | `auto_promo_code` | — |
| POST | `order/save_promo_code` | body |
| GET | `orders/set_config` | `{pid, …}` — config options for the chosen product |
| POST | `get_total` | body — price calculation |
| POST | `order/create` | body — create |
| GET | `adminGetLinkAgeList` | params — linked config options (two levels) |
| GET | `common/get_getways`, `common/get_promo_code {type}`, `common/get_product_list {type,id}` | — |

### 2.4 续费订单 — `/renewal-order` (`RenewalOrder`, module `d2a8`)

**Columns:** `id` ID, `username` 客户名, `hosts` 产品, IP (120), `paid_time` 续费时间,
`amount` 金额, 付款方式.

**Filters:** `客户` (`search.username`), `时间` (`search.searchTime`, datetimerange),
`金额` (`search.amount`), `付款方式` (`search.payment`).

**Endpoints:** `GET order/search_page`, `GET invoice/renew {params}` (main list),
`GET order/getclients {username}`, `POST searchfornamelist`.
Same component is reused for `/supplier-renewal-order` (`supplierRenewalOrder`, module `035f`).

### 2.5 业务列表 — `/customer-product` (`CustomerProduct`, module `2e50`)

**Columns**

| prop | label |
|---|---|
| `id` | ID (70, center) |
| `username` | 客户 (350) |
| `productname` | 产品名称（主机名） (200) |
| `dedicatedip` | IP (120) |
| `type` | 类型 |
| `regdate` | 购买时间 |
| `nextduedate` | 到期时间 |
| `billingcycle` | 周期 (100) |
| `amount` | 价格 |
| `domainstatus` | 状态 (90, center) |
| `user_nickname` | 销售 |

**Filters** (`condition`): `product_type` 产品类型, `domainstatus` 主机状态,
`billingcycle` 付款周期, `domain` 主机名, `ip`, `username` 客户 (autocomplete).
Tabs from server list; `tabsStatusClick` switches `domainstatus`.

**Endpoints:** `POST searchfornamelist` (main list via `getProductList`/`adSearch`),
`GET order/getclients {username}`, `GET login_by_user/<uid>` (`getJwt` — open client area),
`DELETE clients_services/host`, `POST clients_services/info`,
`POST clients_services/transfer`, `POST clients_services/apply_credit`.

### 2.6 业务内页 / 编辑主机 — `/product-innerpage` (`ConfigureEdit` + `CustomerProductInnerpage`, module `dda3`)

`ConfigureEdit~CustomerProductInnerpage~MunualResource~31ecd969.a0e9e69d.js` +
`CustomerProductInnerpage~31ecd969.847eef89.js` + `CustomerProductInnerpage~852bc656.c3397e4d.js`
+ `CustomerProductInnerpage~154c4dfc.d4d417fd.js`.

**Host form model (`formData`)** — from `schema`: `shd_host`

```
id, uid, orderid, serverid, productid, regdate, domain, payment,
firstpaymentamount, amount, billingcycle, last_settle, nextduedate, nextinvoicedate,
termination_date, completed_date, domainstatus, username, password, notes,
subscriptionid, promoid, suspendreason, overideautosuspend, overidesuspenduntil,
dedicatedip, assignedips, ns1, ns2, port, upstream_cost,
auto_recalcre_curring_price, auto_terminate_end_cycle, auto_terminate_reason,
configoption[], customfield[], other
```

**Form fields** (label → v-model):

| label | v-model |
|---|---|
| 订单 | `formData.orderid` (disabled) |
| 商品/服务 | `formData.productid` |
| 供应商 | `manualInfo.name` (disabled, when upstream is manual) |
| 接口 | `zjmf_api.name` (disabled) or `formData.serverid` |
| IP地址 | `formData.dedicatedip` |
| 用户名 | `formData.username` |
| 密码 | `formData.password` |
| 其他IP | `formData.assignedips` (textarea, 英文半角逗号分隔) |
| 主机名 | `formData.domain` |
| 端口 | `formData.port` (number) |
| 客户备注 | `formData.remark` (disabled textarea) |
| 管理员备注 | `formData.notes` |
| 产品ID | `manualProductId` (readonly) |
| 裸金属ID | `dcimId` |
| 首付金额 | `formData.firstpaymentamount` (number, 2dp) |
| 付款方式 | `formData.payment` |
| 订购时间 | `regdateCus` (datetime → seconds) |
| 续费金额 | `formData.amount` |
| 付款周期 | `formData.billingcycle` (`@change cycleChange`) |
| 余额自动续费 | `formData.initiative_renew` (switch) |
| 到期时间 | `nextduedateCus` (datetime → seconds) |
| 状态 | `formData.domainstatus` (`@change domainStatusChange`) |
| 优惠码 | `formData.promoid` |
| 成本 | `formData.upstream_cost` |

Config options render per `configItem.option_type`
(select / radio / checkbox / slider / input-number / cascader / linked select),
custom fields per `customForm[item.id]`.

**Module actions** — `POST provision/default {id, func}` where `func` ∈
`create`, `suspend`, `unsuspend`, `terminate`, `renew`, `sync`, `status`,
`reinstall`, `crack_pass`, `rescue_system`, `pushHostInfo`.
`suspend` additionally sends `reason_type` and `reason` (`pauseForm.reason_type`,
`pauseForm.reason`, maxlength 20) — see `pausePage` (`GET clients_services/host_suspend`)
and `pauseSubmit` (`POST provision/default`).
`provision/custom` is used for the cloud-rescue path.

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `clients_services` | `{uid, hostselect}` | `getData` |
| POST | `clients_services/info` | body | `submitForm` (save host edit) |
| POST | `clients_services/transfer` | body | `transferTop` (change owner) |
| DELETE | `clients_services/host` | `{hostid}` | `del` |
| GET | `clients_services/host_renew` | params | `createBill` (renewal invoice) |
| GET | `clients_services/host_suspend` | params | `pausePage` |
| POST | `clients_services/apply_credit` | body | `useCredit` |
| GET | `invoice/paid` | params | `markPaid` |
| POST | `clients_services/upgrade_config` | body | upgrade config options |
| GET | `user_productaccounts` | params | `searchFlow` |
| GET | `user_productinvoice` | params | `searchBill` |
| GET | `client_ticket` | params | `searchWork` / `getTicketData` |
| GET | `adminGetLinkAgeList` | params | linked config options |
| GET | `common` | — | `getClientList` |
| GET | `hostbyuid` | params | host list for client |
| POST | `provision/default` | `{id, func, reason_type, reason}` | module create/suspend/unsuspend/terminate/renew |
| POST | `provision/custom` | body | module custom action |
| GET/POST | `upper/dcim_client/*`, `upper/ipmi/*`, `upper/emptyupper`, `upper/allotupper` | — | DCIM upstream proxies |
| POST | `dcim/on` `dcim/off` `dcim/reboot` `dcim/bmc` `dcim/kvm` `dcim/ikvm` `dcim/novnc` `dcim/reinstall` `dcim/cancel_task` `dcim/rescue` `dcim/crack_pass` `dcim/assign` `dcim/refresh_power_status` | body `{id,…}` | 魔方 DCIM power/reinstall |
| GET | `dcim/resintall_status`, `dcim/sales` | params | reinstall progress / server list |
| DELETE | `dcim/delete` | body | `setFree` |
| GET | `common/host_list {uid}`, `common/get_product_list {type,id}`, `common/get_promo_code {type}`, `common/get_getways`, `common/get_email_tem {type}` | — | message/product pickers |
| POST | `config_message/sendmessage_post` | body | send SMS/email |

### 2.7 产品暂停请求 — `/customer-cancelreq` (`CustomerCancelReq`, module `551f2`)

`search: {page:1, limit:50, order:"id", sort:"desc"}`, `cancelReasonOptions`, `delArr`.

**Columns:** `username` 姓名, `domain` 产品 (240), `dedicatedip` IP (120),
`type` 类型(立即、到期) (165), `reason` 原因 (200), `create_time` 请求时间 (180),
`nextduedate` 删除时间 (180), `domainstatus` 产品状态, `cancel_status` 执行状态, 操作 (80).

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `request_cancel_list` | params | `getData` |
| DELETE | `request_cancel_list/<id>` | — | `deleteHanleClick` |
| GET | `request_cancel_reason` | — | `cancelReason` |
| POST | `request_cancel_reason_post` | body | `handelConfirm` (accept/reject) |
| POST | `request_cancel_reason` | body | `addReason` |
| DELETE | `request_cancel_reason/<id>` | — | `removeReason` (with `cancel_request/list` DELETE variant) |

Also used by the client tab: `GET request_cancel_list {params}` (in `f6b0`).

---

## 3. 财务 (Finance)

### 3.1 交易流水 — `/business-statement` (`BusinessStatement`, module `c9e0`)

**Columns:** `username` 客户名, `create_time` 时间 (135, center), `gateway` 付款方式,
`description` 描述, `amount_in` 金额, `sale_id` 销售 (110), `trans_id` 流水号 (280),
`type_zh` 类型 (80), 操作 (135). Summary row: `code` 币种, `info.amount_in` 总收入,
`info.amount_out` 总支出, `info.surplus` 总结余.

**Filters (`search`):** `show` 显示, `description` 描述, `amount` 金额,
`trans_id` 付款流水号, `start_time` 开始时间, `end_time` 结束时间,
`gateway` 支付方式, `sale_id` 销售, `关联用户` (uid), `type`.

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `accounts` | params | `getTableData` |
| POST | `accounts` | body | `addSubmitForm` (add transaction) |
| GET | `accounts/create?uid=<uid>` | — | new transaction form |
| GET | `accounts/<id>` | — | detail |
| PUT | `accounts/<id>` | body | update |
| DELETE | `accounts/<id>` | — | delete |
| GET | `search_page` | — | `getPaymentData` (gateways) |

`schema shd_accounts`: `uid, currency, gateway, create_time, update_time, pay_time,
description, amount_in, fees, amount_out, rate, trans_id, invoice_id, refund, delete_time`.

### 3.2 账单管理 — `/bill-management` (`BillManagement`, module `6dec`)

**Columns:** (selection 55), `id` 账单 (105, center), `username` 客户名,
`create_time` 账单生成日 (135), `paid_time` 账单支付日 (135), `due_time` 账单逾期日 (135),
`subtotal` 总计, `payment` 付款方式, `sale_id` 销售 (120), `status` 状态 (80),
`type` 账单类型 (90), 操作 (80).

**Filters (`search`):** `username` 客户 (autocomplete), `uid`,
`create_time_bak` 账单生成日 (datetimerange), `due_time_bak` 账单逾期日,
`paid_time_bak` 账单支付日, `payment` 付款方式, `type` 账单类型, `sale_id` 销售,
`invoice_id` 账单号. Tabs from server (`tabsSearch`).

**Row/multi actions**

| method | url | action |
|---|---|---|
| GET | `invoice/index` | `getData` |
| GET | `invoice/search_page` | `searchPage` |
| GET | `invoice/paid` | `markPaidHandleClick` — 标记已支付 |
| GET | `invoice/unpaid` | `markUnpaidHandleClick` — 标记未支付 |
| GET | `invoice/cancelled` | `markCancelledHandleClick` — 取消 |
| GET | `invoice/duplicate` | `copyBillHandleClick` — 复制账单 |
| DELETE | `invoice/delete` | `deleteOrderHandleClick` — 删除 |
| POST | `searchfornamelist` | advanced search |

### 3.3 账单详情 — `/bill-detail` (`BillDetail`, module `9e24`)

**Sub-tables:** payment records (`pay_time` 时间, `gateway` 付款方式,
`trans_id` 付款流水号, `amount_in` 金额, 操作), invoice items (`id`, `description` 描述,
`amount` 金额, 操作), operation log (`id`, `create_time` 时间, `new_desc` 描述,
`user` 用户名, `ipaddr` IP地址).

**Option form (`optionsFormData`):** `create_time` 账单生成日 (datetime → ts),
`due_time` 账单逾期日, `notes` 备注 (textarea), `status` 状态, `payment` 付款方式.

**New payment form (`newPayFormData`):** `amount` 金额 (max = `billInfo.surplus`),
`pay_time` 时间 (date → ts), `gateway` 付款方式, `trans_id` 付款流水号,
`email` 发送邮件 (switch).

**Refund form (`refundFormData`):** `type` 退款类型, `amount` 金额 (max `diff_amount`),
`email` 发送邮件.

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `invoice/summary/<id>` | — | header |
| POST | `invoice/email` | body | `sendEmailHandleClick` |
| GET | `invoice/paid` / `invoice/unpaid` / `invoice/cancelled` | params | mark status |
| GET | `invoice/addpay_page/<id>` | — | add-payment dialog data |
| POST | `invoice/addpay` | body | `newPayHandleClick` — **add payment** |
| GET | `invoice/option_page/<id>` | — | edit-invoice dialog data |
| POST | `invoice/option` | body | `saveChangesHandleClick` — **save invoice header** |
| GET | `invoice/add_pay_invoice_page/<id>` | — | extra payments list |
| POST | `invoice/add_pay_invoice` | body | `addPayInvoice` |
| POST | `invoice/apply_credit_limit` | body | credit-line payment |
| POST | `invoice/delete_pay_invoice` | body | `deletePay` |
| GET | `invoice/refund_page` | params | `refundPage` |
| POST | `invoice/refund` | body | `refundHandleClick` — **refund** |
| GET | `invoice/notes_page` | params | `remarkPage` |
| POST | `invoice/notes` | body | `remarksSave` |
| DELETE | `invoice/delete_item` | params | `deleteBillItemHandleClick` |
| POST | `invoice/edit_item` | body | `billEditSaveHandleClick` — **edit line item** |
| DELETE | `invoice/delete_account/<id>` | — | delete a payment row |
| GET | `invoice/log_list` | params | `getLogListData` |
| GET | `common/get_email_tem {type}`, `common/get_getways` | — | pickers |

Related invoice endpoints used elsewhere: `GET user_invoice {params}`,
`POST add_user_invoice`, `GET get_combine_invoices {params}`, `POST combine_invoices`
(batch/合并账单, used by the client 账单 tab).

### 3.4 信用额管理 — `/credit-management` (`CreditManagement`, module `f825`)

Tabs `payment` / `repayment` / `customer` (`activeName`).

```
pageInfo: { page:1, limit:10, total:0 }
paymentSearch:   { order:"paid_time", sort:"desc", status:"ALL", username, uid,
                   paid_time:[], type:"", invoice_id:"" }
repaymentSearch: { order:"due_time", sort:"desc", payment_status:"ALL", username, uid,
                   paid_time:[], due_time:[], date:"" }
```

**Endpoints:** `GET credit_limit/list {params}` (`getPayment`),
`GET credit_limit/user_invoice {params}` (`getRepayment`),
`GET credit_limit/client_list {params}` (`getCustomer`),
`GET invoice/search_page {params}`, `GET order/getclients {username}`.

### 3.5 客户详情 → 信用管理 — `/customer-view/credit` (`credit`, module `6334`)

Adds: `GET credit_limit/user_invoice_detail {params}` (invoice detail dialog),
`GET credit_limit/log {params}` (change log: ID/描述/类型/操作时间/操作人/操作IP/账单ID/金额/产品),
`PUT credit_limit {data}` (`putCreditLimit`), `DELETE credit_limit {params:{uid}}`
(`deleteCreditLimit`), `GET credit_limit {params:{uid}}` (`creditLimit`),
`POST credit_limit {data}` (`MsgCreditLimit`), plus the invoice actions
(`invoice/paid|unpaid|cancelled|duplicate|delete`) and `certifi_person_detail/<uid>`.

### 3.6 信用额设置 — `/credit-setting` (`CreditSrtting`, module `08cc`)

Fields: `shd_credit_limit` 信用额总开关, `shd_credit_limit_amount` 信用额额度设置,
`shd_credit_limit_bill_generation_date` 出账日 (select),
`shd_credit_limit_bill_repayment_period` 最后还款日 (0-30),
`shd_credit_limit_liquidated_damages` 违约金总开关,
`shd_credit_limit_liquidated_damages_percent` 单日违约金百分比
(单日违约金 = 逾期金额 × 单日违约金百分比). Methods `getData`, `save`.

### 3.7 提现审核 — `/customer-withdrawal` (`WithdrawalAudit`, module `4d11`)

Two tables: 提现 (`id`, `username` 姓名(公司名), `num` 金额, `type` 类型,
`user_nickname` 操作人, `status` 状态, `reason` 拒绝原因, `create_time` 时间, 操作) and
推广收益 (`id`, `person` 昵称, `amount` 金额, `type_zh` 收款方式, `person` 收款人,
`account_num` 收款账号, `create_time` 提交时间, `status` 状态, `user_login` 操作人,
`cancelled_reason` 拒绝原因, 操作).

**Endpoints:** `GET withdraw/withdraw {params}` (`getProfitList`),
`POST withdraw/withdraw {data}` (`profitOperating`),
`POST aff/affiwithdraw_record {data}` (`getPromotionList`),
`GET aff/gateway_list` (`getpayType`),
`POST aff/affiwithdrawsh {data}` (`promotionSubmit`).

`schema shd_withdraw`: `uid, amount, relid, admin, status` ∈ `Pending|Cancelled|Active`,
`cancelled_reason`, `type` ∈ `credit|income`, `account_id`.
`shd_withdraw_method`: `type` ∈ `bank|alipay`, `account_bank, account_name,
account_num, account_address, username, alipay, default`.

---

## 4. 工单 (Tickets)

### 4.1 工单列表 — `/support-ticket` (`SupportTicket`, module `8a83`)

**Columns:** (selection 45), `id` ID (80), `title` 工单标题, `user_name` 提交人,
`status_title` 状态 (90), `handle_name` 处理人 (90), `department_name` 部门 (120),
`create_time` 提交时间 (135), `last_reply_time` 上次回复 (120).

**Filters:** `username` 客户 (autocomplete), `uid`, `dptid` 部门, `status` 状态,
`priority` 优先级, `content` 工单标题/内容, `tid` 工单编号.
Tabs come from `getTicketDepartment` (department list).

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `list_ticket` | params | `getData` |
| GET | `getClient` | — | `getUserData` |
| GET | `getTicketDepartment` | — | `getDepartmentData` |
| POST | `tastes/editUserTanstes` | body | `autoRefreshHandleClick` |
| POST | `merge_ticket` | body | `mergeTicketHandleClick` (合并) |
| POST | `close_ticket` | body | `closeTicketHandleClick` |
| POST | `delete_ticket` | body | `deleteTicketHandleClick` |
| POST | `searchfornamelist` | body | advanced search |
| GET | `order/getclients {username}` | — | customer autocomplete |

### 4.2 工单详情 — `/support-ticket-detail` (`SupportTicketDetail`, module `f1234`)

Tabs: `添加回复` / `添加备注` / `选项` / `产品信息`.

Host sub-tables: 关联产品 (`productname`, `amount`, `billingcycle`, `create_time`,
`nextduedate`, `domainstatus`) — rendered twice (hosts + products).

Forms: `formData.dptid` 部门, `formData.username`/`uid` 客户名, `formData.title` 工单标题,
`formData.status` 状态; transfer dialog `transferFormData.mode` 转移方式,
`.handle` 指定处理人, `.dptid` 处理部门, `.remarks` 备注.

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `list_ticket/<id>` | — | detail |
| GET | `list_ticket_status` | — | statuses |
| PUT | `ticket_receive` | body | `ticketReceive` (接单) |
| PUT | `ticket_transfer` | body | `handelConfirm` (转单) |
| GET | `ticket_transfer_list` | params | `handoverItem` |
| POST | `reply_ticket` | body | `replyHandleClick` / `replyAndCloseHandleClick` |
| POST | `close_ticket` | body | close |
| POST | `add_ticket_note` | body | add note |
| GET | `add_ticket_page` | params | new-ticket form metadata |
| POST | `save_ticket` | body | `submitForm` (new ticket) |
| POST | `save_ticket_reply` | body | edit reply |
| POST | `delete_ticket_reply` | body | delete reply |
| POST | `delete_ticket_note` | body | delete note |
| GET | `download_ticket_attachment` | params | download attachment |
| GET | `hostbyuid {params}` | — | related hosts |
| GET | `order/getclients {username}` | — | customer autocomplete |

### 4.3 新建工单 — `/add-support-ticket` (`AddSupportTicket`, module `85e3`)

Shares `add_ticket_page`, `save_ticket`, `hostbyuid`, ticket status/department APIs.

### 4.4 工单部门 — `/work-order-dept` (`WorkOrderDept`, module `e653`)

Table: `name` 部门名称, `description` 描述, 是否隐藏 (`hidden`), 自动回复 (`auto_reply`),
操作. Endpoints: `GET list_ticket_department`, `POST delete_ticket_department`,
`POST moveup_ticket_department`, `POST movedown_ticket_department`.
Row editing (`saveRowEdit`) toggles `hidden` / `auto_reply` inline.
New/edit page `/new-work-order-dept` (`NewWorkOrderDept`, module `40ef`).

`shd_ticket_department_upstream`: maps a local department to an upstream one
(`upstream_dptid`).

### 4.5 工单状态 — `/work-order-status` (`WorkOrderStatus`, module `5a4e`)

Table: `id` ID, 标题, `order` 排序, 操作. Form: `title` 状态标题, `color` 状态颜色
(color picker), `order` 产品排序 (1..10000).
Endpoints: `GET list_ticket_status`, `GET list_ticket_status/<id>`,
`POST add_ticket_status`, `POST save_ticket_status`, `POST delete_ticket_status`.

### 4.6 工单传递 — `/work-order-rules` (`WorkOrderRules`, module `47bf`)

Table: `departments` 部门, `products` 产品, `mask_keywords` 屏蔽关键字,
`is_open_auto_reply` 自动回复 (120), 操作 (130).
Dialog fields: `formData.departments` (multi-select of departments),
`formData.products` (multi-select of products), `formData.is_open_auto_reply` (switch),
`formData.bz` 自动回复内容 (textarea maxlength 100), `formData.mask_keywords` 屏蔽关键字.
Endpoints: `GET get_ticket_deliver`, `GET common/get_product_list {type,id}`.

### 4.7 预定义回复 — `/preset-reply` (`PresetReply`, module `d874`)

Category list + reply list: `id`, `title` 标题, `content` 内容, 操作.
Endpoints: `GET ticket_prereply_list`, `POST add_ticket_prereply_category`,
`POST save_ticket_prereply_category`, `DELETE delete_ticket_prereply_category/<id>`,
`POST search_ticket_prereply`, `DELETE ticket_prereply/<id>/`.
Edit page `/addedit-pre-reply` (`AddeditPreReply`, module `57e4`, uses `ticket_prereply/`).

---

## 5. 商品设置 (Product settings)

### 5.1 商品管理 — `/product-server` (`ProductServer`, module `1cb9`)

Tabs: 商品列表 / 商品组(two levels: `一级分组` = `shd_product_first_groups`, `分组` = `shd_product_groups`).

**Product table:** `guid` (40), `name` 商品名称, `type_zh` 类型, `pay_type` 定价 (100, center),
`qty` 库存 (100, center), `count` 已开通/总数量 (120, center), `auto_setup` 自动开通 (200, center),
操作 (150).
**Sync-result table:** selection (55), `name` 商品名称, `type` 类型 (150), `msg` 同步结果.

**Add-product form:** `formData.productname` 商品名称, `formData.type` 商品类型,
`formData.gid` 商品组, `formData.ptype` 会员中心导航分类.
**Copy-product form:** `copyProductFormData.existingproduct` 现有的商品,
`copyProductFormData.newproductname` 新商品名称.
**Group dialog:** `createType` 分组类型 (1 = 一级分组, 2 = 分组), `addGroupForm.id`,
`addGroupForm.gid` 一级分组, `addGroupForm.name` 商品组名称, `addGroupForm.headline` 商品组标题,
`addGroupForm.tagline` 商品组标语, `addGroupForm.alias` 访问别名 (`@blur bmVerify`),
`addGroupForm.tpl_type` 订购表格模板, `addGroupForm.order_frm_tpl`, `是否隐藏`,
and repeatable `{name, value}` 自定义字段 rows.

**Endpoints**

| method | url | params | action |
|---|---|---|---|
| GET | `product_list_page` | params | `getData` |
| GET | `add_product_page` | params | `showAddProduct` |
| POST | `create_product` | body | `submitForm` |
| GET | `edit_product_page/<id>` | — | `EditProduct` page load |
| GET | `del_product` | `{id}` | `deleteProduct` |
| GET | `del_product_group` | `{id}` | `deleteGroup` |
| GET | `del_product_first_group` | params | `deleteGroup` |
| GET | `edit_product_group_page` | `{id}` | `getGroupDialogData` |
| GET | `edit_product_first_group_page` | params | `getGroupDialogData` |
| POST | `save_product_group` | body | `editGroupSubmit` |
| POST | `save_product_first_group` | body | `editGroupSubmit` |
| POST | `product_duplicate` | body | `copyProduct` |
| POST | `update_productsort` | body | `proSortApi` (drag sort) |
| POST | `update_groupsort` | body | `proGroupSortApi` |
| POST | `update_firstgroupsort` | body | `firstGroupSortApi` |
| POST | `edit_stock` | body | `editStockHandleClick` (inline 库存 edit) |
| POST | `check_product_as` | body | `bmVerify` (alias uniqueness) |
| GET | `provision/<…>` | — | module config from `app\admin\controller\ProductController` |
| GET | `options/config_options_check_os` | — | `/` POST — OS list check |
| GET | `get_upstream_products` | params | upstream catalogue for 上下游 products |
| GET | `common/get_product_list {type,id}` | — | `showCopyProduct` |

### 5.2 商品新增/编辑 — `/edit-product` (`EditProduct`, module `00bc`)

Tabs (exact order and labels): `详 情` · `定 价` · `试 用` · `自动开通` · `产品配置` ·
`升级选项` · `自定义字段` · `商品推介计划` · `文件下载` · `链接`.

#### 详情 tab

| field | v-model | type |
|---|---|---|
| 会员中心导航分类 | `editProductFormData.ptype` | select |
| 商品名称 | `editProductFormData.name` | input |
| 商品类型 | `editProductFormData.type` | select (disabled when `isResource == 1` or saved upstream type is `zjmf_api`) |
| 商品组 | `editProductFormData.gid` | select |
| 商品描述 | `editProductFormData.description` | textarea (支持HTML) |
| 主机名 (host_show) | `editProductFormData.host_show` | switch `1/0` |
| 前缀 | `editProductFormData.host_prefix` | input |
| 允许的字符串 | `editProductFormData.host_rule_num` | input |
| 主机名长度 | `editProductFormData.host_rule_len_num` | input-number |
| 密码 (password_show) | `editProductFormData.password_show` | switch |
| 默认密码长度 | `editProductFormData.password_rule_len_num` | input-number |
| 密码规则 | `editProductFormData.password_rule_upper` (and `_lower`, `_num`, `_special`) | checkbox/switch group |
| 商品开通邮件 | `editProductFormData.welcome_email` | select |
| 库存控制 | `editProductFormData.stock_control` | switch `1/0` |
| 库存数量 | `editProductFormData.qty` | input-number (min 0) |
| 是否允许购买多个 | `editProductFormData.allow_qty` | switch `1/0` |
| 是否显示特性 | `editProductFormData.is_featured` | switch `1/0` |
| 是否隐藏 | `editProductFormData.hidden` | switch `1/0` |
| 购买是否需要实名 | `editProductFormData.is_truename` | switch `1/0` |
| 是否下架 | `editProductFormData.retired` | switch `1/0` |
| 购买是否需要绑定手机 | `editProductFormData.is_bind_phone` | switch `1/0` |
| 限制单个客户购买该商品的订购数量 | `editProductFormData.clientscount` | input-number |
| 商品数量计算规则 | `editProductFormData.clientscount_rule` | select |
| 客户是否可以申请停用 | `editProductFormData.cancel_control` | switch |
| 商品删除通知邮件 | `editProductFormData.auto_terminate_email` | select |

#### 定价 tab

Per-currency price matrix. The heading row is 币种 + 初装费成本 + 初装费成本/初装费 +
初装费 + 价格成本 + 价格成本/价格 + 价格, and a period row for
月/季/半年/年/两年/三年…/日/小时. Cycle flags in the component:
`checkedMonth, checkedQuarter, checkedHafe, checkedYear, checkedTwo, … checkedTen,
checkedDay, checkedHour` and per-cycle handlers
`monthlyPriceChange, quarterlyPriceChange, semiannuallyPriceChange, annuallyPriceChange,
bienniallyPriceChange, trienniallyPriceChange, fourly…ninelyPriceChange,
monthlyPlanChange …, allPlanChange, resetPrice, defaultPlan, saveEditPlan`.
Cost fields use `currencyCost`; sell fields use `currency`. `priceData`, `moduleData`,
`currencyDiff`, `setRateNone`.

**上下游 pricing (price plan):** `editProductFormData.upstream_price_type` 价格方案
(`percent` / `custom`) → `editProductFormData.upstream_price_value` 百分比,
plus `flag.bates` 代理商折扣 (read-only).

#### 试用 tab

`pay_ontrial_status` 是否允许试用, `pay_ontrial_condition` 试用条件 (checkbox group),
`pay_ontrial_cycle` 试用时长 + `pay_ontrial_cycle_type` (radio),
`pay_ontrial_num` 单个账户最大可试用数量, `pay_ontrial_num_rule` 试用数量计算规则.

#### 自动开通 tab

| field | v-model | notes |
|---|---|---|
| 付款类型 | `editProductFormData.pay_type` | radio (disabled for `zjmf_api`/`v10`) |
| 付费方式 | `editProductFormData.pay_method` | radio (`prepayment` / `postpaid`) |
| 开通方式 | `editProductFormData.api_type` | select, options from `getModule()`, `@change apiTypeChange`; `normal` = local module |
| 接口分组 / 供应商 | `editProductFormData.server_group` | select; label is 请选择接口分组 when `api_type == "normal"`, else 请选择供应商 |
| 供应商商品 | `editProductFormData.upstream_pid` | select, `@change groupChange`, populated by `getUpstreamProducts()` |
| module config fields | `item.default` | generated per module: input / password / textarea / select / radio |
| 开通方式 (auto) | `editProductFormData.auto_setup` | radio: 无/`on`/`payment`/`order` |

Buttons: `syncApiData` (calls `POST product/sync_product_info` via `syncApiDataInterface`) and
`getUpstreamPrice` (`GET product/get_upstream_price`).

#### 产品配置 / 升级选项 tab

`upgradepackages` 套餐升级 (multi-select), `config_options_upgrade` 产品配置 (switch),
`upgrade_email` 升级通知邮件.
Existing option tables (columns): `id`, `gid` 分组ID, `option_name` 配置项名称,
`option_type` 配置项类型, `order` 排序, `hidden` 隐藏/是否隐藏, `upgrade` 允许升降级,
`is_discount` 应用优惠码, `is_rebate` 客户分组折扣, `title` 操作 (135).

#### 自定义字段 tab

Row fields `item.fieldname` 字段名称, `item.fieldtype` 字段类型, `item.description` 描述,
`item.regexpr` 验证, `item.fieldoptions` 选项 (`dropdown` only), `item.sortorder` 显示排序.
Add-row uses `editProductFormData.addfieldname / addfieldtype / addcustomfielddesc /
addregexpr / addfieldoptions / addsortorder`.

#### 商品推介计划 tab

`affiliate_enabled` 是否启用推介, `affiliate_type` 推介计划比例类型,
`affiliate_bates` 推介计划比例, `affiliate_is_renew` 是否开启续费,
`affiliate_renew_type` 续费方式, `affiliate_renew` 续费比例.

#### 文件下载 tab

Downloads category (`addClassFormData.catid` 类别, `.title` 分类名称, `.description` 分类描述)
and file upload (`uploadFormData.catid` 类别, `.title` 文件名称, `.description` 文件描述,
选择文件). Endpoints: `POST product_manage_downloads`, `GET product_selectcates {productid}`,
`POST product_downloadcats`.

#### 链接 tab

`product_shopping_url` 快速订购链接, `product_group_url` 产品组链接 (both readonly).

#### 产品配置项组 dialog

`groupFormData.name` 组名, `groupFormData.description` 描述 →
`POST options/create_groups_post` or `POST options/edit_groups_post`.

**Endpoints used by the page**

| method | url | params |
|---|---|---|
| GET | `edit_product_page/<id>` | — (initial data; `getData`) |
| POST | `edit_product` | body (`saveApi`) |
| GET | `get_upstream_products` | params (`getUpstreamProducts` / `upperReachesChange`) |
| POST | `product/sync_product_info` | body (`syncApiDataInterface`) |
| GET | `product/get_upstream_price` | params (`getUpstreamPrice`) |
| POST | `options/create_groups_post` | body |
| POST | `options/edit_groups_post` | body |
| POST | `product_manage_downloads` | body (`addFile` / `delFile`) |
| GET | `product_selectcates` | `{productid}` |
| POST | `product_downloadcats` | body |
| POST | `product_del_custom` | body (`deleteCustom`) |
| GET | `common` | — (`getCommon`) |
| GET | `common/get_product_list {type,id}` | — (`getProductsList`) |
| GET | `common/get_email_tem {type}` | — (`getEmailList`) |
| GET | `provision/<id>` | — (module config) |

`schema shd_products` (key columns): `type, gid, name, description, hidden,
show_domain_options, welcome_email, stock_control, qty, prorata_billing, prorata_date,
prorata_charge_next_month, pay_type (free|onetime|recurring|day|hour|ontrial),
pay_method (prepayment|postpaid), allow_qty, auto_setup (""|on|payment|order),
server_type, server_group, config_option1..50, recurring_cycles, auto_terminate_days,
auto_terminate_email, config_options_upgrade, billing_cycle_upgrade, upgrade_email,
overages_enabled/disk_limit/bw_limit/disk_price/bw_price, tax, affiliateonetime,
affiliate_pay_type (percentage|fixed|none), affiliate_pay_amount, order, retired,
is_featured, auto_create_config_options`.

### 5.3 商品组新增/编辑 — `/add-product-group` (`AddProductGroup`, module `9493`)

Fields: `createType` 分组类型, `addGroupForm.gid` 一级分组, `addGroupForm.name` 商品组名称,
`addGroupForm.headline` 商品组标题, `addGroupForm.tagline` 商品组标语,
`addGroupForm.alias` 访问别名 (+`bmVerify`), `defaultPage`/`addGroupForm.tpl_type` 订购表格模板,
`addGroupForm.order_frm_tpl`, `name` 分组组名称, 是否隐藏.
Endpoints: `GET edit_product_group_page {id}`, `GET edit_product_first_group_page {params}`,
`POST save_product_group`, `POST save_product_first_group`.

Related schemas: `shd_product_first_groups`, `shd_product_groups`,
`shd_product_first_groups_customfields`, `shd_product_groups_customfields`,
`shd_user_product_groups`.

### 5.4 全局可配置项 — `/configurable-option` (`ConfigurableOption`, module `14c2`)

Table: `id` ID (70, center), `name` 组名, `description` 描述, `products` 产品,
`title` 操作 (135).

Duplicate-group dialog: `formData.gid` 配置项组, `formData.newname` 新的组名.

| method | url | params | action |
|---|---|---|---|
| GET | `options/groups_list` | params | `getData` |
| GET | `options/search_page` | params | `getSearchDate` |
| GET | `options/duplicate_groups` | — | `duplicateGroupHandleClick` |
| POST | `options/duplicate_groups_post` | body | `submitForm` |
| GET | `options/delete_groups/<id>` | — | `deleteGroupHandleClick` |

### 5.5 配置项组编辑 — `/edit-configurable-option-group` (`EditConfigurableOptionGroup`, module `c0e4`)

Group form: `groupFormData.name` 组名, `groupFormData.description` 描述,
`groupFormData.p_id` 指定产品 (multi-select).

Option table: `id`, `gid` 分组, `option_name` 配置项名称, `option_type` 配置项类型,
`order` 排序, `hidden` 是否隐藏, `upgrade` 允许升降级, `is_discount` 应用优惠码,
`title` 操作 (135).

| method | url | params |
|---|---|---|
| GET | `options/edit_groups/<id>?type=<type>` | — |
| POST | `options/create_groups_post` | body |
| POST | `options/edit_groups_post` | body |
| GET | `options/delete_options/<id>` | — |
| GET | `common/get_product_list {type,id}` | — |

Single option editor: `/edit-configurable-option1` (`EditConfigurableOption1`, module `46fe`) —
`options/edit_config/…` endpoints (the request interceptor explicitly exempts
`admin/options/edit_config/` from duplicate-cancellation).

### 5.6 商品订购设置 — `/order-product` (`OrderProduct`, module `68d0`)

`config_general/productgroup` and `config_general/productgroup_page`,
`config_general/navgrouporder`.

### 5.7 通用接口 (servers) — `/server-settings` (`ServerSettings`, module `376e`)

Tabs: 接口列表 / 接口分组.

**Interface table:** `id` ID (70, center), 状态 (60, center), `name` 接口名称,
`type` 服务器模块, `gname` 接口分组名, `ip_address` IP地址, `open_num`, `disabled` 状态,
操作 (200).

| method | url | params | action |
|---|---|---|---|
| GET | `servers_list` | `{limit,page,search,gid}` | `getInterface` |
| GET | `groups_list` | `{limit,page}` | `getInterfaceGroup` |
| GET | `server_test_link/<id>` | — | test connectivity |
| GET | `delete_servers/<id>` | — | delete |
| GET | `delete_server_groups/<id>` | — | delete group |

**Add interface** `/add-interface` (`AddInterface`, module `67a5`): `formData.name` 名称,
`formData.ip_address` IP地址, `formData.type` 服务器模块 (options `interfaceType`
from `POST get_modules_group {type}`), `formData.max_accounts` 接口容量,
`formData.hostname` 主机名, `formData.gid` 接口分组名.
Endpoints: `GET servers_add` (`addServerInit`), `POST servers_add_post {data, config}`
(the module config is posted as `config`), `POST edit_servers_post {data}`,
`GET edit_servers/<id>`, `POST get_modules_group {data}`.

**Add server (legacy)** `/add-server` (`AddServer`, module `1f7a`): `formData.name` 姓名,
`formData.ip_address` IP地址, `formData.hostname` 主机名, `formData.gid` 接口,
`formData.username` 用户名, `formData.password` 密码, `formData.port` 端口 →
`GET servers_add`, `POST servers_add_post {data, config}`, `POST edit_servers_post`.

**Server group** `/add-group` (`AddGroup`, module `0108`): `formData.group_name` 接口分组名,
`formData.mode` 分配方式 (radio), `formData.sid` 选择空闲接口 (`el-transfer` with titles
`空闲接口`/`已选接口`). Endpoints: `GET create_groups`, `POST create_groups_post`,
`GET edit_server_groups/<id>`, `POST edit_server_groups_post`.
Group list page `/group-list` (`GroupList`, module `0057`) uses `servers_list`.

**Module plugin list** `/module-plugin` (`ModulePlugin`, module `62b6`): table
`id`, `title` 插件名称, `name` 标识, `description` 描述, `author` 作者, `version` 版本,
`status` 状态, 操作.

### 5.8 支付接口 — `/payment-interface` (`PaymentInterface`, module `ae17`)

Table: `id` ID (80), `title` 插件名称, `name` 标识, `description` 描述, `author` 作者,
`version` 版本 (80, center), `status` 状态 (80, center), 操作 (200).
Config dialog renders plugin config fields from the plugin's own schema
(`item.value` — input / textarea / select). `plModel.newGatewayId` selects the gateway
to copy (`plCopyHandleClick`). Endpoint: `GET common/get_getways`.
Methods: `plInstallHandleClick, plUnInstallHandleClick, plUnInstallApi,
plToggleHandleClick, plToggleApi, plSettingHandleClick, plCopyHandleClick, jumpUrl,
saveHandleClick, getGetways, sure, rowDrag`.

### 5.9 优惠码 — `/promo-code` (`PromoCode`, module `729a`)

Table: `id` ID (50), `code` 优惠码 (100), `type` 类型 (120), `value` 价值,
`recurring` 循环优惠 (100, center), `used` 已使用次数 / 最大使用次数 (200, center),
`start_time` 开始时间 (135), `expiration_time` 失效时间 (135), 操作 (230).
`type` tab filter: `active` / others.
Endpoints: `GET list_promo_code`, `POST expired_promo_code {id}`, `POST delete_promo_code {id}`.

**Add/edit** `/promo-code-add` (`PromoCodeAdd`, module `5486`): `formData.code` 优惠码,
`formData.type` 类型, `formData.recurring` 是否为循环优惠, `formData.value` 折扣,
`formData.is_discount` 代理商可用, `formData.appliesto` 适用于 (multi),
`formData.requires` 需要 (multi), `formData.requires_exist` (switch),
`formData.cycles` 结算周期 (checkbox group), `formData.start_time` 开始时间,
`formData.expiration_time` 失效时间, `formData.max_times` 最大使用次数,
`formData.lifelong` 升降级产品配置, `formData.one_time` 一次性,
`formData.only_new_client` 新注册用户, `formData.only_old_client` 现有的用户,
`formData.once_per_client`, `formData.upgrades`, `formData.upgrade_config`, `formData.notes`.
Endpoints: `GET add_promo_code/page`, `GET save_promo_code/page {id}`,
`POST add_promo_code`, `POST save_promo_code`, `GET common/get_product_list {type,id}`,
`GET common/product_config_options`.
`schema shd_promo_code`: `code, type (percent|fixed|override|free), recurring, value,
cycles, appliesto, requires, requires_exist, start_time, expiration_time, max_times,
used, lifelong, one_time, only_new_client, only_old_client, once_per_client, recurfor,
upgrades, upgrade_config, notes`.

### 5.10 货币配置 — `/currency-settings` (`CurrencySettings`, module `faa5`)

Table: `id` (80, center), `code` 货币代码 (120, center), `prefix` 前缀（例如：￥） (150),
`suffix` 后缀 (80), `format` 格式, `rate` 汇率, 操作 (325).
Dialog: `formData.code`, `formData.prefix`, `formData.suffix`, `formData.rate`
(相对于第一个货币的汇率). Methods: `editCurrency, handleDelete, getCurrencyList,
setDefault, submitDialog, updateRate, updataPrice`.
`schema shd_currencies`.

### 5.11 充值与财务 / 发票 / 合同设置

* `/general-settings/finance` (`Finance`, module `3f5b`) — recharge & finance settings
  (`config_general/recharge`, `config_general/invoice`, `config_general/invoice_page`).
* `/voucher-setting` (`InvoiceSetting`, module `f0e8`) and `/invoice-audit` (`InvoiceAudit`,
  module `dde9`) — 发票列表 / 发票设置, endpoints `voucher/express` DELETE etc.
* `/contracts_setting` (`contractsSetting`, module `4e28`) and `/contracts_audit`
  (`contractsAudit`, module `4cdc`) — 合同模板与审核.
  Contract API (module `f32a`, chunk `taskQueue~…`):
  `GET contract/tpl` , `DELETE contract/tpl/<id>`, `GET contract/detail`,
  `POST contract/detail`, `GET contract/detail/<id>`, `POST contract/detail/<id>`;
  contract list API (module `4cdc` in the same chunk):
  `GET contract/contract`, `GET contract/download/<id>`, `GET contract/contract_page`,
  `POST contract/contract_page/<id>`, `POST contract/cancel`, `POST contract/cancel_post/<id.id>`,
  `POST contract/contract/<id>` (mail management: `is_post`, `express_company`,
  `express_order`).
* `/add_contract` (`addContract`, module `780a`).

---

## 6. 资源与商店 → 上下游 (Upstream / downstream) — the inter-finance-system reseller feature

Menu (`app\admin\controller\UpperReachesController`):

```
资源与商店 /app-store/app-list
  上下游 /zjmf-api
    上游资源
      供应商管理        /zjmf-api
      商品管理          /commodity-list
      产品管理          /commodity-product
      任务队列          /commodity-taskQueue
      服务器列表        /munual-resource
      订单列表          /supplier-order-list
      续费订单          /supplier-renewal-order
    下游管理
      任务队列          /task-queue
      API设置           /api-setup
```

### 6.1 Mental model

* **Upstream (上游)** = *I buy from someone else.* I register an account on another
  ZJMF finance install, paste that account's API key, and pull its product catalogue into
  my own panel. My customers buy from me; my panel places the real order with the upstream
  via `zjmf_finance_api/*`. Types are `manual` (just a note/bookkeeping record),
  `zjmf_api` (another 智简魔方财务 install) and `v10` (newer upstream protocol);
  the legacy form also supports `whmcs`.
  The upstream's product list is reached through `get_upstream_products`
  (admin-side proxy) and its live balance through `zjmf_finance_api/upstreamcredit`.
* **Downstream (下游)** = *someone else buys from me.* I create a normal client account,
  give it an API key, and it becomes a "资源池/代理商". My downstream's panel calls
  **my** `/zjmf_finance_api/*` endpoints (a separate route group, see §6.8), and I control
  it entirely from `/api-setup` plus the client's own API surface
  (`/customer-view/CustomerApiOverview` shows the downstream's view of it).
  The downstream feature is what `zjmf_finance_api/toggle|freepage|products|order|host|
  logs|downstream_summary|open` manage.
* **Resource pool (资源池)** is the same mechanism seen from the buyer side:
  `/resourcePool-set` holds `formData.username` 账号, `formData.password` 资源池连接密钥,
  `formData.ticket_open` 工单传递, with `POST agent/resourceticketopen`,
  `GET/POST agent/resourceinfo`, `POST agent/linktoresource`.

### 6.2 供应商管理 — `/zjmf-api` (`ZjmfApi`, module `1c84`, chunk `ZjmfApi~31ecd969.b2a51454.js`)

Hint text: `提供财务系统之间的互相代理，在此添加API接口信息即可成为代理商。`
(+ link to `https://bbs.idcsmart.com/forum.php?mod=viewthread&tid=136…` 帮助文档).

**List model:** `search = { page:1, limit: localStorage.limit || 50, orderby:"id", sort:"desc" }`

**Columns**

| prop | label | notes |
|---|---|---|
| `name` | 名称 | router-link to `configure-edit?{id,name,type}` |
| `type_zh` | 类型 | 手动 / 智简魔方 |
| `hostname` | 接口地址 | |
| `product_num` | 可售/已设置商品 | width 180, shows `product_num / set_product_num`; tooltip 可售：上游购物车接口中商品数量的总数 / 已设置：系统中使用该接口对接的商品数量 |
| `active_host_num` | 产品数量(正常/总) | width 160, shows `active_host_num / host_num` |
| `status` | 状态 | width 120, clickable refresh; icon tooltip `r.desc` ("链接成功"/"链接失败") |
| `credit` | 余额 | center, red when `< 100`; rendered `prefix + credit` |
| `des` | 描述 | |
| — | 管理 | width 120: 编辑 / 删除 |

Status column header has a global refresh icon → `getAllStatus()` refreshes every row.

**Add/edit dialog** (`formData`):

```js
{ id:0, name:"", hostname:"", username:"", password:"", des:"",
  type:"manual", contact_way:"" }
```

| field | prop | notes |
|---|---|---|
| 接口类型 | — | `type`: `manual`=手动, `zjmf_api`=智简魔方 (disabled once `id != 0`) |
| 名称 | `name` | 自定义，建议填写上游的公司名称 |
| 联系方式 | `contact_way` | only for `manual` |
| 接口地址(IP或者域名) | `hostname` | only for `zjmf_api`; 上游魔方财务系统的访问地址或ip |
| 用户名 | `username` | only for `zjmf_api`; 您在上游注册的账号，手机/邮箱 |
| API密钥 | `password` | only for `zjmf_api`; 上游客户中心-安全中心-API → 查看密钥 |
| 描述 | `des` | |

Required: `name`, `hostname`, `username`, `password`, `contact_way`.
`changeType` clears the type-specific fields.

**Endpoints** (module `e52a`)

| letter | method | url | body/params | action |
|---|---|---|---|---|
| a | POST | `zjmf_finance_api` | data = formData | `createApi` (submit new) |
| f | PUT | `zjmf_finance_api` | data = formData | `modifyApi` (submit edit) |
| d | GET | `zjmf_finance_api` | params = `search` | list (`getData`) |
| c | GET | `zjmf_finance_api/<id>` | — | detail (`getApiDetail`, also used by add-supplier) |
| b | DELETE | `zjmf_finance_api/<id>` | — | `deleteApi` |
| e | GET | `zjmf_finance_api/<id>/status` | — | `refreshStatus` → `{status, desc}` |
| g | GET | `zjmf_finance_api/upstreamcredit` | `{id}` | row balance → `data.credit`, `data.currency.prefix` |

`shd_zjmf_finance_api`: `id, hostname, username, password, status (0异常/1正常),
product_num, create_time`.

**Add/edit page** `/add-supplier` (`addSupplier`, module `ef86`)

Route-guard: uses `$route.query.data` to decide 编辑供应商 / 添加供应商; loads via
`GET zjmf_finance_api/<id>` and copies `id,name,hostname,username,password,des,type,contact_way`.

Fields, grouped under 基础信息 then 自动开通:
`供应商名称 formData.name`, `联系方式 formData.contact_way`, `备注 formData.des` (textarea),
`接口类型 formData.type` (`manual`=手动, `zjmf_api`=智简魔方, `v10`=v10),
`接口地址 formData.hostname`, `用户名 formData.username`, `API密钥 formData.password`.
Hostname/username/password are shown for `zjmf_api`, `whmcs` and `v10`.
Submit → `POST zjmf_finance_api` (new) / `PUT zjmf_finance_api` (edit).

### 6.3 商品管理 — `/commodity-list` (`commodityList`, module `e7ca` → `d1c8`)

The route module `e7ca` is a thin wrapper rendering `<commodityListTwo>` (module `d1c8`
in `ConfigureEdit~commodityList~31ecd969.73254d13.js`).

**List columns**

| prop | label |
|---|---|
| `name` | 商品名称 |
| `billingcycle_zh` | 定价 (100, center) |
| `list` | 供应商库存 (center) |
| `list` | 已开通/总数量 (center) |
| `list` | 供应商 (center) |
| `list` | 售价 (center) |
| `list` | 利润 (center) |
| — | 操作 (180) |

(Columns whose `prop` is `list` are rendered through scoped slots over a per-supplier
sub-array; the real fields live inside `list[*]`.)

**Import dialog (导入商品)** — `importdialogVisible`, form `formData`:

```js
{ grouping:"", upperReaches:"", commodity:[], percentage:"", classification:"" }
// rules: grouping required, upperReaches required, commodity required (array),
//        percentage required and must match /^([1-9]|[1-9]\d|100)$/  (1..100)
```

Fields:

| label | v-model | notes |
|---|---|---|
| 选择本地分组 | `formData.grouping` | filterable select of local product groups |
| 选择上游 | `formData.upperReaches` | filterable select of suppliers; `@change upperReachesChange` |
| 请选择上游商品 | `formData.commodity` | **multiple**, `collapse-tags`, filterable; options come from the upstream cart API |
| 利润百分比(%) | `formData.percentage` | 1–100 |
| 会员中心导航分类 | `formData.classification` | 会员中心导航分类 |

`upperReachesChange()` resets `commodityList`/`formData.commodity` and calls
`GET get_upstream_products {id:<upleReaches>}` → response `data.data` = commodity list,
plus `data.currency`, `data.upstream_currency`, `data.rate` (used for the rate dialog).

**Submit payload** — `submitForm([rate])` builds a `FormData` (multipart!) with:

```
type[<upstreamProductId>]  = <product.module>            // for every selected commodity
gid                        = formData.grouping
upstream_price_value       = Number(formData.percentage) + 100   // markup + 100 (i.e. 100 => no markup)
ptype                      = formData.classification
zjmf_finance_api_id        = formData.upperReaches
rate                       = <rate>                     // only when passed (rate dialog / #rateInp)
productnames[<id>]         = <upstream product name>    // for every selected commodity
```

sent via `POST zjmf_finance_api/inputproduct`. Before submitting, if the supplier
currency differs, the panel prompts with an HTML message and reads the exchange rate from
the `#rateInp` input, then calls `submitForm(rate)` (see `store_tips4`).

**Other endpoints** (module `0085`, shared chunk):

| letter | method | url | params |
|---|---|---|---|
| a | GET | `zjmf_finance_api/downstream_summary` | params |
| j | GET | `zjmf_finance_api/products` | params |
| h | GET | `zjmf_finance_api/host` | params |
| g | GET | `zjmf_finance_api/addpage` | — |
| k | GET | `get_upstream_products` | params |
| b | POST | `zjmf_finance_api/inputproduct` | data |
| f | POST | `zjmf_finance_api/upstreamhost` | data |
| d | GET | `zjmf_finance_api/manualhost` | params |
| c | POST | `zjmf_finance_api/manualhost` | data |
| i | GET | `zjmf_finance_api/order` | params |
| e | POST | `zjmf_finance_api/order_commission` | data |

Group management inside the same page uses `check_product_as {data}` (`bmVerify`),
`edit_product_group_page {id}`, `edit_product_first_group_page {params}`,
`save_product_group {data}`, `save_product_first_group {data}`, `del_product {id}`.
Event bus events: `commodity_List` (refresh list), `importGoods` (open dialog).

### 6.4 产品管理 — `/commodity-product` (`commodityProduct`, module `ced8`)

Upstream hosts bought through suppliers.

**Columns**

| prop | label |
|---|---|
| `id` | ID (80, center) |
| `name` | 产品名称(主机名) (190, left) |
| `server_name` | 供应商 (120, center) |
| `username` | 客户 (140) |
| `dedicatedip` | IP (center) |
| `type_zh` | 类型 (center) |
| `create_time` | 购买时间 (150, center) |
| `nextduedate` | 到期时间 (150, center) |
| `amount` | 续费价格 (120, center) |
| `credit` | 利润 (120, center) |
| `saler` | 销售 (100, center) |
| `domainstatus_zh` | 状态 (120, center) |

**Edit dialog** (`dialogVisiable`, `dialogTitle`): `formData.regate` 供应商到期时间
(datetime), `formData.amount` 续费价格, `formData.billingcycle` 周期,
`formData.dedicatedip` IP, `formData.assignedips` 附加IP (textarea),
`formData.create_time` 开通时间 (datetime). Validation on `amount`.

**Endpoints:** `GET zjmf_finance_api/manualhost {params}` (`getTable`) /
`POST zjmf_finance_api/manualhost {data}` (`submit`), `POST zjmf_finance_api/upstreamhost
{data}` (`getTableTwo`), `GET zjmf_finance_api/host {params}` (`getlist`).

### 6.5 任务队列 (upstream) — `/commodity-taskQueue` (`commodityTaskQueue`, module `14b6`)

**Columns:** `user` 客户 (180), `domain` 产品, `active_type` 动作 (70),
`description` 描述, `from_type` 来源 (70), `credit` 状态 (60),
`last_execute_time` 最后操作时间 (120), `credit` 操作 (120).
Action: `POST run_map/repeat_task {data}` (`goToProduct`) — retry a queued module action.

### 6.6 服务器列表 — `/munual-resource` (`MunualResource` + `ConfigureEdit`)

Manual (non-API) upstream resource records: `ConfigureEdit~CustomerProductInnerpage~
MunualResource~…` + `MunualResource~e74b0f58.…`. Related pages:
`/upStream-edit` (`UpStreamEdit`, module `94d8`), `/addOrEdit-resource`
(`AddOrEditResource`, module `b002`), `/resource-pool` (`ResourceRool`, module `9aff`),
`/resource-pool-shop`, `/resourcePool-set`, `/resourcePool-logs` (`journalManagement`).

`AddOrEditResource` uses the resource-party API:
`GET agent/agentlist`, `GET resource_party/resourcepartylist`,
`POST resource_party/checkresourceparty`.

### 6.7 订单列表 / 续费订单 for suppliers

* `/supplier-order-list` (`supplierOrderList`, module `fb63`) — resolves to the shared
  task-queue chunk; columns match `ConfigureEdit`'s order tab.
* `/supplier-renewal-order` (`supplierRenewalOrder`, module `035f`) — same component as
  `/renewal-order` (`RenewalOrder`), i.e. `GET zjmf_finance_api/renew {params}`
  instead of `invoice/renew` for the supplier tab.

Upstream order tab (`ConfigureEdit`) columns/actions:

`id` ID (60, center), `username` 客户名, `hosts` 产品, IP (120), `create_time` 下单时间,
`amount` 金额, 付款状态/付款方式, `status`, `order_notes`, `sum` 提成/销售;
actions `GET zjmf_finance_api/order {params}` (`getData`), `POST
zjmf_finance_api/order_commission {data}` (`getSum`), `GET order/check {params}`
(`examPassHandleClick`), `GET order/cancel {params}`, `DELETE orders/delete {params}`,
`GET order/search_page {params}` (`orderSearchPage`), `POST searchfornamelist {data}`,
`POST zjmf_finance_api/upstreamhost {data}` (`getTableTwo`).

Upstream product tab columns: `name`, `billingcycle_zh` 定价 (100, center),
供应商库存 / 已开通·总数量 / 供应商 / 售价 / 利润 (all under prop `list`), 操作 (180).
Upstream host tab columns: `id`, `name` 产品名称(主机名) (190, left), `server_name` 供应商,
`username` 客户, `dedicatedip` IP, `type_zh` 类型, `create_time` 购买时间,
`nextduedate` 到期时间, `amount` 续费价格, `credit` 利润, `saler` 销售,
`domainstatus_zh` 状态.

`ConfigureEdit` (`/configure-edit?id=<apiId>&name=<n>&type=<t>`) is the **supplier detail
tabs page**: 上下游概览 (`zjmf_finance_api/downstream_summary`), 商品
(`zjmf_finance_api/products`, `inputproduct`, `addpage`, `get_upstream_products`),
产品/主机 (`zjmf_finance_api/host`, `manualhost` GET/POST, `upstreamhost` POST),
任务队列 (`run_map/repeat_task`, `run_map/list?query[<k>]=<v>`),
订单 (`zjmf_finance_api/order`, `order/check`, `order/cancel`, `orders/delete`),
资源管理 (`upper/upperindex`, `upper/index`, `upper/addupperpage`, `upper/editupperpage`,
`upper/addpost`, `upper/edituppost`, `upper/del`, `upper/delupper`, `upper/allotupper`,
`upper/emptyupper`), DCIM/IPMI power ops (`upper/ipmi/{status,on,off,reboot,vnc}`,
`upper/dcim_client/{status,on,off,reboot,vnc,reinstall,crack_pass,get_os,
resintall_status,cancel_task}`).

### 6.8 下游管理

#### 6.8.1 任务队列 — `/task-queue` (`TaskQueue`, module `c95f`)

**Columns:** `user` 客户, `domain` 产品, `active_type` 动作, `description` 描述 (260),
`from_type` 来源, `credit` 状态, `last_execute_time` 最后操作时间, `credit` 操作 (120).
Methods: `goToView, clearSearch, searchClick, handleClick, handleSizeChange, getData,
formType, activeType`.
Related pages: `/statistics-taskQueue` (`statisticstaskQueue`, module `0d1f`),
`/resourcePool-taskQueue` (`resourcePoolTaskQueue`, module `49b0`).

API module (`4e25`, duplicated in every task-queue chunk) — this is the **upstream
resource/DCIM proxy** used by both the supplier detail page and the queue:

| export | fn | method | url |
|---|---|---|---|
| s | `n(t)` | GET | `upper/index` (params) |
| l | `s(t)` | POST | `upper/del` |
| b | `i(t)` | POST | `upper/addpost` |
| o | `u(t)` | POST | `upper/edituppost` |
| r | `c(t)` | GET | `upper/upperindex` |
| a | `l(t)` | POST | `upper/addupperpost` |
| k | `o(t)` | POST | `upper/delupper` |
| n | `d(t)` | POST | `upper/editupperpost` |
| c | `p()` | GET | `upper/addupperpage` |
| A | `h(t)` | GET | `upper/ipmi/status {id}` |
| w/f/v/x/y | `f/g/m/_` | POST | `upper/ipmi/{on,off,reboot,vnc}` `{id}` |
| m | `b(t)` | GET | `upper/editupperpage {id}` |
| g | `v(t)` | POST | `upper/dcim_client/on {id}` |
| f | `$(t)` | POST | `upper/dcim_client/off {id}` |
| h | `S(t)` | POST | `upper/dcim_client/reboot {id}` |
| j | `y(t)` | POST | `upper/dcim_client/vnc {id}` |
| i | `O(t)` | GET | `upper/dcim_client/status {id}` |
| B | `k(t)` | POST | `upper/dcim_client/reinstall` |
| p | `w(t)` | POST | `upper/dcim_client/get_os` |
| e | `j(t)` | POST | `upper/dcim_client/crack_pass` |
| q | `x(t)` | POST | `upper/dcim_client/resintall_status` |
| d/z | `I/z(t)` | POST | `upper/dcim_client/cancel_task` |
| u | `C(t,e,a)` | GET | `run_map/list?query[<e>]=<a>` (params) |
| t | `L(t)` | POST | `run_map/repeat_task` |

#### 6.8.2 API设置 — `/api-setup` (`ApiSetUp`, module `fdb8`)

This is the **downstream-facing switchboard**: it decides whether my own install exposes
itself as an upstream for other finance systems, and under which conditions.

Fields:

| field | v-model | type | notes |
|---|---|---|---|
| 是否开启资源API | `formData.allow_resource_api` | el-switch `"1"/"0"` | master on/off |
| API秘钥获取条件 | — | text | `只有满足以下已开启的条件才能获取秘钥` |
| 实名认证 | `formData.allow_resource_api_realname` | el-switch `"1"/"0"` | shown only when `allow_resource_api == 1` |
| 是否需要绑定手机号 | `formData.allow_resource_api_phone` | el-switch `"1"/"0"` | shown only when `allow_resource_api == 1` |

Actions: 保存更改 (`submitForm` → `POST config_general/apiconfig`), 取消更改 (`resetForm`
→ re-`getData`).

| method | url | params | action |
|---|---|---|---|
| GET | `config_general/apiconfig` | — | `getData` |
| POST | `config_general/apiconfig` | body = formData | `submitForm` |

The same page also exists as `/source-api` (`SourceApi`, module `e485`) with the labels
`是否开启资源API`, `是否需要实名认证`, `是否需要绑定手机号` — identical endpoints.

#### 6.8.3 The downstream/upstream API endpoints themselves (`admin/zjmfFinanceApi/*`)

From `docs/recon/routes.tsv` (controller `admin/zjmfFinanceApi`). These are the endpoints
the panel calls **and** the ones a downstream install calls:

| method | url | controller action | purpose |
|---|---|---|---|
| POST | `admin123/zjmf_finance_api` | `createApi` | add a supplier |
| PUT | `admin123/zjmf_finance_api` | `modifyApi` | edit a supplier |
| GET | `admin123/zjmf_finance_api` | `index` | supplier list |
| GET | `admin123/zjmf_finance_api/<id>` | `detail` | supplier detail |
| DELETE | `admin123/zjmf_finance_api/<id>` | `deleteApi` | delete supplier |
| GET | `admin123/zjmf_finance_api/<id>/status` | `refreshStatus` | connection + balance refresh |
| GET | `admin123/zjmf_finance_api/summary` | `summary` | summary |
| POST | `admin123/zjmf_finance_api/reset` | `resetApiPwd` | reset the API secret key |
| POST | `admin123/zjmf_finance_api/toggle` | `apiToggle` | **enable/disable downstream API access** |
| GET/POST/DELETE | `admin123/zjmf_finance_api/freepage` | `apiFreePage`/`apiFreePost`/`apiFreeDelete` | 「免登录/白名单」page config |
| GET | `admin123/zjmf_finance_api/products` | `apiProducts` | downstream product catalogue |
| GET | `admin123/zjmf_finance_api/order` | `apiOrder` | downstream orders |
| POST | `admin123/zjmf_finance_api/order_commission` | `apiOrderCom` | commission on downstream orders |
| GET | `admin123/zjmf_finance_api/renew` | `getRenew` | downstream renewals |
| GET | `admin123/zjmf_finance_api/host` | `apiHost` | downstream hosts |
| GET | `admin123/zjmf_finance_api/downstream_summary` | `downstreamSummary` | **downstream dashboard** |
| GET | `admin123/zjmf_finance_api/logs` | `apiLog` | **API call log** (also shown per client) |
| POST | `admin123/zjmf_finance_api/open` | `apiOpen` | open/enable |
| GET | `admin123/zjmf_finance_api/addpage` | `addPage` | import-product form metadata |
| POST | `admin123/zjmf_finance_api/inputproduct` | `inputProduct` | **import upstream products** |
| POST | `admin123/zjmf_finance_api/upstreamhost` | `upstreamHost` | upstream host operations |
| GET | `admin123/zjmf_finance_api/manualhost` | `getManualHost` | manual host list |
| POST | `admin123/zjmf_finance_api/manualhost` | `postManualHost` | manual host add/edit |
| GET | `admin123/zjmf_finance_api/upstreamcredit` | `upstreamCredit` | upstream balance |

There is also an un-prefixed group for the downstream to call **as a client**
(`home/ZjmfFinanceApi/*`):

| method | url | action |
|---|---|---|
| POST | `zjmf_finance_api/reset` | `resetApiPwd` |
| POST | `zjmf_finance_api/open` | `apiOpen` |
| GET | `zjmf_finance_api/summary` | `summary` |

and a catch-all agent proxy for the resource-pool / app-store agent protocol:

| method | url | controller |
|---|---|---|
| GET | `admin123/agent/checktoken` | `admin/public/checkToken` |
| GET/POST/PUT/DELETE/PATCH | `admin123/agent/<action>` | `<method><action>` (dynamic dispatch) |

Agent endpoints observed in the bundles: `agent/agentlist`, `agent/token`, `agent/baseinfo`,
`agent/consumption`, `agent/host`, `agent/income`, `agent/inspectiondetail`,
`agent/inspectionip`, `agent/inspectionlists`, `agent/order`, `agent/ordersearchpage`,
`agent/products`, `agent/renew`, `agent/renewsearchpage`, `agent/resourceinfo`,
`agent/runmaplists`, `agent/tickets`, `agent/agentLogs`, `agent/afterSaleDetail?id=`,
`agent/refundDetail?id=`, `agent/aftersale`, `agent/unaftersale`, `agent/checkagent`,
`agent/evaluation`, `agent/linktoresource`, `agent/refund`, `agent/resourceinspection`,
`agent/resourceticketopen`, `agent/checktoken`.

`schema shd_zjmf_pushhost` — the outbound push log used when a host is created locally and
must be registered downstream: `host_id, status (1成功/0失败), url, post_data, time, num`.

#### 6.8.4 服务器列表 / API日志 in the downstream context

* `/api-log` (`ApiLog`, module `fdc9`) — logs written by `zjmf_finance_api/logs` and
  `log_record` (`DELETE log_record/delete_log`).
* `/munual-resource` (`MunualResource`) — the supplier's own resource inventory page.

---

## 7. 设置 (Settings)

### 7.1 常规设置 — `/general-settings/general` → `general` (`General`, module `ff7c`)

Also reachable as `/base-info` (`BaseInfo`, module `323d`), `/official-setting`
(`OfficialSetting`, module `79c1`), `/local` (`Local`), `/support` (`Support`),
`/promote` (`Promote`), `/safe` (`Safe`), `/other` (`Other`), `/invoice` (`Invoice`),
`/finance` (`Finance`, module `3f5b`), `/captcha` (`Captcha`), `/second` (`Second`),
`/class` (`Class`), `/order` (`OrderGoods`), `/login-setting` (`LoginPage`).

**Controls grouped by form object**

`baseInfoForm`: `company_name` 品牌名, `domain` 系统链接, `system_url` 网站域名.
`localForm`: `language` 默认语言, `allow_user_language` 启用语言选择菜单.
`showForm`: `per_page_limit` 每页显示记录, `credit_limit` 前台信用额,
`allow_custom_clients_id` 自定义起始客户ID, `custom_clients_id_start` 客户ID前缀 (max 10 digits).
`baseSafeForm`: `cancellation_time` 管理员登录时长(天), `home_ip_check` 前台登录IP检查,
`admin_ip_check` 后台登录IP检查; 后台管理目录路径 (read-only display).
`modeForm`: `main_tenance_mode` 维护模式, `main_tenance_mode_message` 维护模式信息,
`main_tenance_mode_url` 维护模式重定向的链接.
`debugForm`: `shd_debug_model` Debug调试 (warns it grants the vendor admin access).
`BaseInfo` also has `main_phone` 手机, `company_qq` qq, `main_address` 地址,
`record_no` 备案号, `map` 坐标(x,y), `www_logo` 官网LOGO, `seo_keywords` 关键字,
`seo_desc` 描述, `company_profile` 公司简介.

**Endpoints (all through module `a494` = config general API)**

| method | url | getter / setter export |
|---|---|---|
| GET / POST | `config_general/general` | `.f` / `.r` |
| GET / POST | `config_general/local` | `.h` / `.t` |
| GET / POST | `config_general/support` | `.n` / `.z` |
| GET / POST | `config_general/invoice` | `.g` / `.s` |
| GET / POST | `config_general/recharge` | `.l` / `.x` |
| GET / POST | `config_general/affiliate` | `.k` / `.w` |
| GET / POST | `config_general/safe` | `.m` / `.y` |
| GET / POST | `config_general/other` | `.j` / `.v` |
| GET / POST | `config_general/register_login_page` / `config_general/register_login` | `.i` / `.u` |
| GET / POST | `config_general/invoice_page` / `config_general/invoice_post` | `.o` / `.p` |
| GET / POST | `config_general/productgroup_page` / `config_general/productgroup` | `.A` / `.B` |
| GET / POST | `config_general/apiconfig` | `.a` / `.b` (see §6.8.2) |
| GET / POST | `config_general/secondverify` | `.D` / `.C` |
| GET / POST | `config_general/captcha_page` / `config_general/register_login_captcha` | `.e` / `.E` |
| GET / POST | `config_general/navgrouporder`, `config_general/buy_product_page` / `config_general/buy_product` | `.q` / `.c` / `.d` |
| POST | `config_general/newGeneral` | generic setter (used by 二次验证) |
| POST | `config_general/getConfig` / `config_general/getConfigOption` | read a config block |
| GET | `common` | platform info (company name, license, language list) |

All settings live in `shd_configuration` (`setting`, `value`, timestamps) —
see `docs/recon/config_defaults.sql` for the shipped defaults.

### 7.2 定时任务 — `/automatic-tasks` (`AutomaticTasks`, module `f4f4`)

Reads `GET cron_page`, saves `POST save_cron`; log tabs use
`GET run_cron/trend {params}` and `GET run_cron/list {params}`.

**Config keys exposed by the form** (all are `shd_configuration` keys):

| key | label |
|---|---|
| `cron_day_start_time` | 定时任务执行时间 (您希望在每天何时执行) |
| `cron_host_suspend` + `cron_host_suspend_time` | 产品/服务到期暂停 (+ 天数) |
| `cron_host_suspend_send` | 产品暂停通知 |
| `cron_host_unsuspend` | 支付后自动解除暂停 |
| `cron_host_unsuspend_send` | 产品解除暂停通知 |
| `cron_host_terminate` + `cron_host_terminate_time` | 产品/服务到期后自动删除 (+ 天数) |
| `cron_host_terminate_high` | 高级设置开关 |
| `cron_host_terminate_time_{hostingaccount,server,cloud,dcimcloud,dcim,software,cdn,other}` | per-product-type terminate days |
| `cron_invoice_create_default_days` | 续费通知并生成账单 (days before due) |
| `cron_invoice_pay_email` + `cron_invoice_unpaid_email` | 账单未付款提醒邮件 (+ days) |
| `cron_invoice_first_overdue_email_switch` + `cron_invoice_first_overdue_email` | 第一次提醒 |
| `cron_invoice_second_overdue_email_switch` + `cron_invoice_second_overdue_email` | 第二次提醒 |
| `cron_invoice_third_overdue_email_switch` + `cron_invoice_third_overdue_email` | 第三次提醒 |
| `cron_ticket_close_time_switch` + `cron_ticket_close_time` | 自动关闭工单 |
| `cron_order_unpaid_time_high` + `cron_order_unpaid_time` + `cron_order_unpaid_action` | 自动取消/删除订单 (`Cancelled` / `Delete`) |
| `cron_invoice_recharge_delete1` + `cron_invoice_recharge_delete_time` | 删除未付款充值账单 |
| `cron_credit_limit_suspend_time_switch` + `cron_credit_limit_suspend_time` | 账单逾期自动暂停 (0=立即 / 1=自定义) |
| `cron_credit_limit_invoice_unpaid_email_switch` + `cron_credit_limit_invoice_unpaid_email` | 账单未付款提醒 |
| `cron_credit_limit_invoice_{first,second,third}_overdue_email_switch` + `…_email` | 信用额逾期第 1/2/3 次提醒 |

The page also renders a per-product-type matrix (虚拟主机 / 独立服务器 / 云服务器 /
魔方云 / 魔方DCIM / 软件产品 / CDN / 其他服务).

`/timing-results` (`TimingResults`, module `1185`) shows the 定时任务状态 dashboard.

### 7.3 员工管理与分组权限

**员工管理** `/admin-management` (`AdminManagement`, module `b98d`)

Table: `id` ID (70, center), `user_nickname` 真实名称, `user_email` 邮件地址,
`user_login` 用户名, `role` 管理员角色, `dept` 分配到的部门, `is_sale` 是否是销售 (100, center),
`sale_is_use` 销售是否启用 (120, center), 操作 (125).
Endpoints: `GET adminuser {page, limit}`, `DELETE adminuser/<id>/`.

**员工新增/编辑** `/admin-edit` (`AdminEdit`, module `343a`)

Fields: `adminName` 员工角色 (select, disabled when `id === 1`), `userName` 用户名,
`realName` 真实姓名, `email` 邮箱, `pwd` 密码 (max 64), `confirmPwd` 确认密码,
`language` 语言. Endpoints: `GET create_page` (`addInit`), `GET adminuser/<id>`,
`POST adminuser` (`createApi`), `POST adminuser/update` (`editApi`), `GET common`,
`POST second_verify_send` (when 二次验证 is on).

**分组权限** `/permissions-managment` (`PermissionsManagment`, module `42e6`)

Table: `id` (70, center), `name` 分组名称, `remark` 说明, 状态 (80, center),
`user_login` 组成员, 操作 (120, left).
Copy-role dialog: `formData.role_id` 原分组, `formData.role_name` 新分组名称,
`formData.role_remark` 新分组说明.
Endpoints: `GET rbac`, `POST rbac/copyRole`, `DELETE rbac/<id>/`.

**权限编辑** `/permissions-edit` (`PermissionsEdit`, module `7db2`)

`addFromData.name` 分组名称, `addFromData.remark` 描述, `addFromData.status` 禁用 (switch),
`filterText` 权限 (keyword filter over the permission tree), `user` 分组用户,
`secondVerifyFormData.code` 验证码.
Endpoints: `GET rbac/role_page` (`detailPermiss`), `GET rbac/<id>`, `POST rbac`
(`createApi`), `POST rbac/edit` (`editApi`), `GET common`, `POST second_verify_send`.
`shd_role`: `name, remark, status, auth_role, parent_id, list_order`;
`shd_role_user`: `role_id, user_id`; `shd_auth_rule` / `shd_auth_access` hold the rule tree.

**销售设置** `/sales-management` (`SalesManagement`, module `8de4`)

Three tables: 销售分组 (`group_name` 组名称, `bates` 新购提成比例 / 续费提成比例 /
升降级提成比例, `is_renew` 包含续费计算, `updategrade` 计算升降级, 操作),
销售阶梯 (`turnover` 营业额, `bates` 额外提成奖励, 操作), 销售列表 (`id` ID (60),
`user_nickname` 真实姓名, `user_email` 邮箱, `user_login` 用户名, `role` 管理员分组,
`is_sale` 是否销售 (100), `sale_is_use` 下单时可选 (125), `only_mine` 只能查看自己客户 (165),
`cat_ownerless` 未分配客户所有人可见 (180)).
Schemas: `shd_sales_product_groups` (`group_name, bates, is_renew, updategrade, pids`),
`shd_sale_ladder` (`turnover, bates, is_flag`), `shd_sale_products`.

### 7.4 短信邮件设置

**接口设置** `/sms-template/sms` (`SmsTemplateI`, module `0671`) — SMS gateway config.
**邮件模板** `/email-list` (`EmailList`, module `bd23`)

Table: `id` ID (70, center), 状态 (80, center, from `disabled`), 模板名称, 操作 (135).
Add dialog: `addEmailForm.type` Email Type, `addEmailForm.name` 邮件识别名称;
language dialog `langForm.language` 请选择语言.
Endpoints: `GET email_template/email_list`, `GET email_template/create_template`,
`POST email_template/create_template_post`, `POST email_template/disabled_template`,
`GET email_template/delete_template/<id>`, `GET email_template/manage_language`,
`POST email_template/manage_language_post`, `POST email_template/disabled`,
`POST email_template/operator_switch`, `POST config_message/send_email` (test send).

**邮件编辑** `/email-edit` (`EmailEdit`, module `45d6`) — `emailForm.disabled` 是否禁用,
附件 upload (`el-upload`, limit 5), `GET email_template/edit_template/<id>`,
`POST email_template/edit_template_post`. Preview: `/email-preview` (`EmailPreview`).

**短信模板** `/sms-template-index` (`SmsTemplateIndex`, module `dd3a`) — endpoints
`GET config_message/template_list`, `GET config_message/update_tem_status`,
`GET config_message/config_mobile`, `GET config_message/delete_template {ids,type}`,
`POST config_message/check_post {ids,type}`,
`GET config_message/test_message_template_page`, `POST config_message/update_template_post`,
`POST config_message/test_message_template`.
Also `/sms-create-template` (`SmsCreateTemplate`), `/sms-send-settings` (`SmsSendSettings`,
`POST config_message/SetSmsTemplate`).
Schemas: `shd_message_template`, `shd_message_template_link`.

### 7.5 安全相关

* `/twice-confirm` (`TwiceConfirm`, module `2b45`) — 二次验证.
  `forwardForm.second_verify_home` 会员中心开启二次验证,
  `forwardForm.second_verify_action_home_type` 验证方式 (checkbox group),
  `forwardForm.second_verify_action_home` actions; `backForm.second_verify_admin`,
  `backForm.second_verify_action_admin`; `pageData` = `{admin_action, home_action,
  home_type, home_action_user}`; `secondAction = "second_verify_set"`.
  Endpoints `POST config_general/getConfig`, `POST config_general/getConfigOption`,
  `POST config_general/newGeneral`, `GET common`, `POST second_verify_send`.
  Defaults: `second_verify=1`, `second_verify_action=on,off,reboot,hardOff,hardReboot,
  crackPass,rescue,vnc`, `second_verify_action_type=email,phone`.
* `/general-settings/captcha` (`Captcha`, module `c925`) — captcha settings
  (`config_general/captcha_page`, `config_general/register_login_captcha`). Defaults in
  `shd_configuration`: `captcha_length`, `captcha_combination`,
  `allow_register_email_captcha`, `allow_register_phone_captcha`,
  `allow_login_phone_captcha`, `allow_login_email_captcha`, `allow_login_code_captcha`,
  `allow_login_id_captcha`, `allow_phone_forgetpwd_captcha`,
  `allow_email_forgetpwd_captcha`, `allow_resetpwd_captcha`.
* `/black-list` (`blackList`, module `a0d2`) — `shd_blacklist`, `DELETE adminuser/<id>`,
  `GET adminuser`.
* `/third-login` (`ThirdLogin`, module `f957`) — OAuth plugins
  (`pl_index/<module>/`, `pl_setting/mail/<id>`, `pl_install`, `pl_uninstall`,
  `pl_toggle`, `pl_copy`, `pl_setting_post`, `pl_update`).
* `/data-migration` (`DataMigration`, module `b38b`), `/about` (`About`, module `4c5e`),
  `/system-message` (`SystemMessage`, module `987c`, `GET /upgrade/sqlupdate`),
  `/php-message` (`PhpMessage`, module `3b7d`), `/database-message` (`DatabaseMessage`).

### 7.6 日志

| route | module | endpoint |
|---|---|---|
| `/system-log` | `6001` | `GET log_record/systemlog {params}` (also `zjmf_finance_api/logs`) |
| `/system-admin-log` | `f432` | `log_record/adminlog` |
| `/sms-log` | `063a` | `log_record/smslog` |
| `/station-letter-log` | `4ca5` | `log_record/systemmessagelog` |
| `/email-log` | `d45d` | `log_record/emaillog` |
| `/api-log` | `fdc9` | `GET log_record/api_log {params}` |
| `/automatic-task-log` | `5b02` | `GET log_record/cronsystemlog {params}` |
| `/log-cleanup` | `b4b7` | `DELETE log_record/delete_log` |

Common table shape: `id` ID (80, center), `create_time` 时间 (135, center),
`new_desc` 描述, `username` 用户名 (120), `ip` / `ipaddr` IP (120-180).
`shd_activity_log` / `shd_activity_log_home`: `create_time, description, user, uid, ipaddr,
type, activeid, usertype`.

### 7.7 站务设置 (brief)

* 基础信息 `/base-info` (`BaseInfo`, module `323d`) — see 7.1.
* 主题模板 `/theme-template` (`ThemeTemplate`, module `3508`).
* 官网自定义字段 `/custom-template-fields` (`CustomTemplateFields`, module `0f2a`) and
  `/add-custom-template-fields` (`AddCustomTemplateFields`, module `e2e3`).
* 导航管理 `/menu_manage` (`MenuManage`, module `7f0f`), `/create_menu` (`CreateMenu`,
  module `a046`), `/create_menu_www` (`CreateMenuWww`, module `e7a1`).
* 友情链接 `/friendly_link` (`MenuManage` module `8842`).
* 文件下载 `/service-support` (`ServiceSupport`, module `4374`) and `/file` (`File`,
  module `e494`) — `GET/DELETE downloads/cat`, `GET/DELETE downloads/file`.
* 新闻中心 `/news-list` (`NewsList`, module `e147`, endpoints `GET news/list {page,limit,
  parent_id,orderby,sorting}`, `DELETE news/content {id}`),
  `/add-news` (`AddNews`, module `7100`), `/news-category` (`NewsCategory`, module `52a5`).
* 帮助中心 `/help-list` (`HelpList`, module `5839`, same `news/*` endpoints),
  `/add-help` (`AddHelp`, module `5112`), `/help-category` (`HelpCategory`, module `3b00`).
  Schemas: `shd_news`, `shd_news_type`, `shd_news_menu`, `shd_knowledge_base*`,
  `shd_downloads`, `shd_downloadcats`.
* 知识库 / 分类 `/knowledge-base`, `/cate-management` (`knowledgeBase`, `cateManagement`).
* 营销推送 `/marketing-push` (`MarketingPush`, module `3f33`) + `/message-write`
  (`MessageWrite`, module `0c75`).

### 7.8 统计 / 其他

* `/annual-statistics` (`AnnualStatistics`), `/new-customer` (`NewCustomer`),
  `/product-revenue` (`ProductRevenue`), `/revenue-ranking` (`RevenueRanking`),
  `/support-statistics` (`SupportStatistics`), `/sales-statistics` (`SalesStatistics`),
  `/statistical-information` (`statisticalInformation`), `/business-management`
  (`businessManagement`), `/order-management-list` (`orderManagementList`),
  `/refund-detail` (`refundDetail`), `/aftersale-detail` (`aftersaleDetail`).
* `/resource-pool-shop`, `/app-store`, `/app-list`, `/my-app`, `/app-detail`, `/app-inner`,
  `/hot-app`, `/app-leaderboard`, `/plug-management`, `/plug-export`,
  `/application-list` (`appCheckList`), `/application-detail`, `/comment-list`,
  `/assist-apply`, `/assist-detail`, `/ssistant-audit`, `/resourcePool-*`.
* DCIM / 魔方云: `/dcim`, `/dcim-view`, `/dcim-traffic`, `/dcim-traffic-log`,
  `/dcim-product`, `/zjmfcloud`, `/zjmfcloud-product` (modules `2229`, `5fbe`, `8e8d`,
  `71f4`, `9897`, `f01c`, `916f`).

---

## 8. Implementation notes for the Laravel rewrite

1. **Response envelope.** Every admin endpoint returns `{status, msg, data}`; `status` is the
   HTTP-ish code used by the SPA switch (200/302/307/400/401/403/404/405/406/409/410/422/500).
   The SPA always reads `data.data` for payloads; list endpoints put `list`/`total` (or
   `sum`) inside `data`.
2. **Auth.** Session cookie + `withCredentials`. `401` must include `identify` set to the
   endpoint identifier so the SPA can decide whether to route to `/forbidden`.
3. **Timestamps.** All times are sent and returned as **seconds**; the request layer also
   silently divides 13-digit millisecond values by 1000. Date pickers use
   `value-format="timestamp"`.
4. **Pagination.** `{page, limit}` with `orderby`/`order` + `sort`/`sorting` (`DESC`/`ASC`).
   `limit` defaults to `localStorage.limit || 50`; page sizes `[10,15,20,25,50,100]`.
5. **CSV/HTML in payloads.** `zjmf_finance_api/inputproduct` is **multipart/form-data**
   (bracket keys `type[<id>]`, `productnames[<id>]`), not JSON.
6. **Menus/permissions.** The server returns `rule` in the response when the menu list
   changed; the SPA caches it in `localStorage.menuList`. Route guards read
   `localStorage.noPowerName` + `name` query for the `Forbidden` page.
7. **Module config payloads.** `servers_add_post` posts `{data, config}` — the plugin's
   own config fields are a nested object, and `edit_servers_post` posts `{data}` only.
8. **The 上下游 feature is symmetric** — the same controller
   (`admin/zjmfFinanceApi`) serves the supplier-management UI, the downstream API surface,
   and the resource-pool protocol. When reimplementing, model it as one
   `upstream_apis` table (`shd_zjmf_finance_api`) plus one downstream-facing API
   route group guarded by an API key and the `allow_resource_api`,
   `allow_resource_api_realname`, `allow_resource_api_phone` switches.

---

### Appendix — extraction tooling

The SPA was reconstructed by evaluating the webpack chunks in a Node `vm` context
(`docs/recon/work/`): `load.js` captures every module, `page.js` prints an annotated,
beautified dump per route (with `$lang` keys resolved from `lang/zh.js` and API calls
rewritten to `API_<METHOD>(<url>)`), `struct.js` extracts tables/columns/forms,
`mod.js` prints `data()`/`methods`, and `endpoints.tsv` is the full 4,221-row
endpoint inventory keyed by chunk/module/export letter.

# Kjaiu

[English](README.md) | [简体中文](README.zh-CN.md)

Kjaiu 是一套基于 Laravel 12 的财务与托管运营系统。项目提供响应式管理后台，并实现智简魔方财务系统（IDCsmart Finance）v1 兼容 API 的核心子集，涵盖身份认证、商品目录、购物车结算、账单、余额、交易和服务续费。

## 功能范围

- 支持客户与管理员账户，以及 Session 和 HS256 JWT 身份认证
- 支持商品分组、商品、多付款周期、库存预占和自动或手动开通状态
- 支持基于零起始位置的购物车结算、幂等键、稳定加锁顺序和精确库存释放
- 支持订单、账单、账单项目、账户交易、余额支付和充值账单
- 支持服务创建、状态管理、月末计费锚点、手动续费和余额自动续费
- 提供客户、商品、账单、服务、交易和余额调账审计管理页面
- 提供旧版商品目录接口及智简魔方主题使用的核心 v1 路由别名
- 提供持久化供应商开通流程、串行旧版购物车变更和安全主机状态确认
- 提供账单过期、自动续费、供应商开通和主机轮询定时命令

本项目专注于核心兼容能力，并非智简魔方财务系统所有模块的完整复刻。工单、消息、推介、实名认证、新闻、下载和营销模块尚未实现。

## 环境要求

- 64 位 PHP 8.2 或更高版本，并启用 `ctype`、`curl`、`dom`、`fileinfo`、`filter`、`hash`、`mbstring`、`openssl`、`pdo_mysql`、`session` 和 `tokenizer` 扩展
- 运行默认本地测试套件时需要 `pdo_sqlite`
- Composer 2
- MySQL 5.7.8+ 或 8.x，并使用 InnoDB 和 `utf8mb4`（CI 覆盖 5.7.44 和 8.4）
- Node.js `^20.19.0` 或 `>=22.12.0`
- npm

## 安装

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan kjaiu:jwt-key
```

在 `.env` 中配置 MySQL 和 `KJAIU_*` 参数，然后初始化应用：

```bash
php artisan migrate --seed
npm ci
npm run build
```

如需在填充数据时创建首个管理员，请在运行 `php artisan db:seed` 前配置：

```dotenv
KJAIU_ADMIN_NAME=Administrator
KJAIU_ADMIN_EMAIL=admin@example.com
KJAIU_ADMIN_PASSWORD=replace-with-a-strong-password
```

管理后台位于 `/admin`。数据填充器还会创建银行转账渠道和一组小型示例商品目录。

仓库中的 `composer.lock` 由发布工作流生成并审计。生产部署必须使用该锁文件安装依赖，或直接使用版本化部署包；不得在生产服务器解析浮动依赖版本范围。

## 开发

```bash
composer dev
```

常用检查命令：

```bash
composer test
npm run lint:php
npm run format:check
npm run build
npm audit --audit-level=high
```

`npm run lint:php` 使用 JavaScript PHP 解析器执行快速语法检查，不能替代 PHPUnit、Laravel 启动验证或 MySQL 集成测试。

## 定时任务

生产环境中应每分钟运行一次 Laravel 调度器：

```cron
* * * * * cd /var/www/kjaiu && php artisan schedule:run >> /dev/null 2>&1
```

调度器执行以下任务：

| 命令                                | 频率       | 用途                                                        |
| ----------------------------------- | ---------- | ----------------------------------------------------------- |
| `kjaiu:expire-invoices`             | 每 15 分钟 | 取消已逾期的未支付账单，并释放账单快照记录的库存预占        |
| `kjaiu:auto-renew`                  | 每小时     | 按服务到期时间创建唯一续费账单，并使用客户可用余额支付      |
| `kjaiu:supplier-reconcile-renewals` | 每分钟     | 将旧版遗留的排队供应商续费安全标记失败，不扣款也不发起 HTTP |
| `kjaiu:supplier-recover`            | 每分钟     | 保守分类超时的供应商运行声明，且不重放采购变更              |
| `kjaiu:supplier-process`            | 每分钟     | 处理到期且处于排队状态的首次供应商开通操作                  |
| `kjaiu:supplier-poll`               | 每分钟     | 安全确认供应商主机状态，不重放采购变更                      |

供应商采购路由只使用本地结算时写入并经过哈希校验的不可变映射快照；之后的展示或映射变更不会改变已排队请求。旧版供应商余额自动支付默认关闭。结算返回上游账单后，Kjaiu 会持久化账单和主机引用，但不会调用 `/apply_credit`；本地服务保持 `Pending`，操作以 `blocked_credit` / `awaiting_manual_supplier_payment` 和 `legacy_payment_review_required` 等待人工审核。上游主机 ID、`Active` 主机状态或本地已支付账单均不能证明上游已经付款，也不能激活该服务。

人工付款时，必须先在供应商后台打开该操作对应的准确账单，对照冻结操作和本地账单/服务核对账单 ID、商品、金额及币种，再支付该账单。供应商侧显示已付款后，还要确认主机确实属于同一账单，然后在 **管理后台 > 供应商操作 > 已在上游人工付款并确认主机** 中填写主机 ID、当前管理员密码并勾选明确作证。这只会记录具名人工证据，不是密码学付款证明；该操作不会调用供应商付款接口，也不会直接激活服务。只有后续只读轮询返回 `Active` 才能激活。单独使用主机证据关联不构成付款确认。

管理员可为单个供应商显式启用高级兼容选项，以允许旧版 `/apply_credit`。启用或关闭都必须验证当前管理员密码，使用共享的供应商敏感操作限流，并且在该账户存在未终结操作或待结算订单项路由时禁止变更；仅凭据轮换的例外不适用于此选项。审计只记录该选项变更前后的布尔值。旧版接口无法携带预期金额、币种、账单版本或幂等前置条件，因此存在检查与使用时点分离（TOCTOU）及无上限扣款风险：账单可能在报价校验后、余额扣除前发生变化，冻结报价无法原子限制供应商实际扣除的金额或币种。显式启用后，只有结构有效的应用状态 `1001` 才构成持久化付款确认；状态 `200` 和未知结果仍视为未确认，且绝不重放。

自动主机轮询和本地激活必须先具有持久化付款证据。`running` 开通操作连续 15 分钟未更新即视为超时。恢复流程只会重新排队通过校验且没有任何变更证据的预检声明；已确认付款且已知主机的操作进入只读确认轮询；任何无法证明结果的供应商变更都进入 `ambiguous`。处于 `blocked_credit` 或 `ambiguous` 状态的供应商操作必须由管理员审核，不得直接将其重新改为 `queued`。对于 `ambiguous`，严禁重试采购、结算、付款或任何其他供应商写操作，只能核对供应商侧证据并使用管理页面提供的证据型主机对账；该操作只读取供应商主机并保存本地关联，不确认付款。

在 `clearCart` 开始前，供应商客户端构造、登录、`setConfig`、报价、DNS/TLS 和临时读取错误均可证明尚未发生变更。可重试的预检失败继续使用 `queued` 和 `available_at`：第一次等待 60 秒，第二次等待 120 秒，第三次总失败后以 `preflight_retry_exhausted` 进入 `failed`。不可变快照、报价金额或币种不匹配等确定性错误立即失败。选择器只处理 `available_at` 为空或已到期的记录，因此重复执行命令不能绕过退避；任何变更步骤一旦开始都不会自动重放。

活动供应商账户即使存在未终结操作，也可以在验证当前管理员密码后仅轮换已保存凭据。账户 ID 和冻结的基础地址身份保持不变，供应商 JWT 缓存会失效，已有排队操作将使用新凭据。基础地址、驱动、内部代码、启停状态、映射和旧版余额兼容选项仍须等相关操作终结后才能修改。定时遗留续费协调器会把预发布版本留下的排队 `renew` 记录以 `unsupported_supplier_renewal` 安全终结，且不扣款、不访问供应商。

## 支付边界

Kjaiu 不会因为客户选择了外部支付渠道就将付款标记为成功。在真实网关集成完成前，`POST /v1/pay` 只会返回 `requires_gateway: true`。

网关适配器必须在调用 `BillingService::recordPayment()` 前验证签名、商户订单、外部流水号、金额、币种和已配置渠道。外部流水号全局唯一，入账过程在数据库事务中完成。管理员也可以在后台手动记录已经确认的银行、现金或已配置网关付款。

API 契约请参阅 [`docs/API.md`](docs/API.md)，生产部署和网关集成要求请参阅 [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)。

## 架构

- `app/Services/BillingService.php`：结算、余额支付、已验证外部入账、取消、充值、续费和开通的事务边界
- `app/Services/JwtService.php`：独立的两小时 HS256 API 令牌，并验证签发者、受众和令牌版本
- `app/Http/Controllers/Api`：v1 兼容 JSON 控制器
- `app/Http/Controllers/Web`：管理后台控制器
- `database/migrations`：MySQL 数据结构和财务约束
- `routes/console.php`：密钥生成、账单过期、自动续费和供应商操作命令
- `tests`：金额、认证、API 契约、幂等、库存、余额和续费测试

金额以 `DECIMAL(18,2)` 存储，计算时转换为整数最小货币单位。资金变更使用数据库事务和行锁；生产并发验证必须在 MySQL 上执行，不能仅依赖 SQLite。

MySQL 5.7 兼容层使用原生 JSON 类型、`utf8mb4_unicode_ci` 排序规则、显式 InnoDB 表和索引安全的字符串长度。MySQL 5.7 已结束上游维护；无法立即升级到 MySQL 8 时，应使用带供应商安全支持的 5.7.44。

## 安全

- `APP_KEY` 和 `KJAIU_JWT_SECRET` 必须使用相互独立的密钥并妥善保管
- 使用 `php artisan kjaiu:jwt-key` 生成 256 位 API 令牌密钥
- 生产环境应设置 `APP_DEBUG=false`、`APP_URL=https://...` 和 `SESSION_SECURE_COOKIE=true`
- 在 Web 服务器或可信代理终止 TLS，并适当限制管理后台访问
- 修改密码、API 注销、停用客户或重置客户密码会递增令牌版本并撤销现有 API 令牌
- 不得将 `BillingService::recordPayment()` 直接暴露为未经签名验证的公共路由

## 许可证

Kjaiu 仅依据 [GNU 通用公共许可证第 3 版](LICENSE)（`GPL-3.0-only`）授权。第三方依赖仍分别遵循其各自的许可证。

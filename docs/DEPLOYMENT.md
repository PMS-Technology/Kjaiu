# Kjaiu 部署指南

Kjaiu 是 [智简魔方财务 (ZJMF / IDCSmart Finance) v3.7.6](https://mfcw.782778.xyz) 的
Laravel 13 重写实现：功能近似，并且**上下游 API 与 `/v1` 公共 API 保持线上兼容**。

- 开发目录：`/www/Project/Kjaiu`
- 部署目录：`/www/wwwroot/kjaiu.782778.xyz`（nginx 站点根目录指向 `<部署目录>/public`）
- 面板站点：`kjaiu.782778.xyz`（PHP 8.3）

## 环境要求

| 组件 | 版本 | 说明 |
| --- | --- | --- |
| PHP | 8.3+ | 需要 `pdo_mysql`、`mbstring`、`openssl`、`curl`、`gd`、`zip` |
| MySQL | 5.7.8+ | 库表使用 `utf8mb4`，金额列为 `decimal(10,2)` |
| Node.js | 20.19+ / 22.12+ | 仅构建前端资源时需要 |

## 数据库

生产库为 `kjaiu`，测试库为 `kjaiu_testing`。两者都已导入与原始平台**逐表一致**的
163 张 `shd_` 前缀表结构。

`.env` 关键配置：

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kjaiu.782778.xyz

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kjaiu
DB_USERNAME=kjaiu
DB_PASSWORD=...

# 会话与缓存使用文件驱动（镜像库中没有 cache/sessions 表）
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
```

`.env` 中的密码在写入时未做引号包裹，`php artisan config:cache` 也能正确解析。

> `DB_PREFIX=shd_` 已在 `config/database.php` 中默认设置；不要移除，否则所有表名将失配。

## 首次部署

```bash
cd /www/Project/Kjaiu

# 1. 依赖（生产环境不安装 dev 依赖）
COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction

# 2. 环境文件与密钥
cp .env.example .env      # 首次部署时创建，随后填入数据库与站点信息
php artisan key:generate --force

# 3. 初始化本站专属数据（管理员、货币、默认设置、工单状态）
#    管理员密码通过环境变量提供；留空时会生成随机密码并打印一次
KJAIU_ADMIN_USERNAME=admin \
KJAIU_ADMIN_PASSWORD='<强密码>' \
php artisan db:seed --force

# 4. 前端资源
npm ci && npm run build                       # 客户区 / 官网（Vite）
cd admin-spa && npm ci && npm run build && cd ..   # 管理后台 SPA

# 5. 缓存
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 发布到站点目录

```bash
bash scripts/deploy.sh            # 见下文；会同步代码并保留站点 .env
```

`scripts/deploy.sh` 使用 `rsync` 同步源码（排除 `.env`、`node_modules`、`storage`
中的运行时文件与 `admin-spa/`），随后在目标目录执行缓存重建与属主修正。

## 定时任务

计划任务由 Laravel 调度器统一驱动，只需在面板中添加**一条** crontab：

```
* * * * * cd /www/wwwroot/kjaiu.782778.xyz && /www/server/php/83/bin/php artisan schedule:run >> /dev/null 2>&1
```

调度项（`routes/console.php`）：

| 命令 | 频率 | 作用 |
| --- | --- | --- |
| `kjaiu:module-queue` | 每分钟 | 执行 `shd_module_queue` 中的开通/暂停/删除任务 |
| `kjaiu:unsuspend-paid` | 每小时 | 账单已结清的业务自动解除暂停 |
| `kjaiu:upstream-sync` | 每小时 | 同步上游商品（受供应商 `auto_update` 开关控制） |
| `kjaiu:invoice-generation` | 每天 | 按到期日出账（提前天数取自设置） |
| `kjaiu:suspend-overdue` | 每天 | 逾期暂停业务 |
| `kjaiu:terminate-overdue` | 每天 | 逾期过久删除业务 |
| `kjaiu:client-care` | 每天 | 客户关怀触发（`shd_client_care_trigger`） |

命令执行结果写入 `shd_cron_log`。

## 上下游（API 兼容）

本站既可作为**下游**转售上游资源，也可作为**上游**向下游开放接口。

**当下游使用**（在管理后台「资源与商店 → 上下游 → 供应商管理」添加供应商）：

- 供应商记录存于 `shd_zjmf_finance_api`，字段为 `hostname` + `username` + `password`（API 密钥）。
- 登录流程：`POST /v1/login_api`（`account` = 用户名，`password` = API 密钥）返回 `jwt`，
  之后所有请求携带请求头 `authorization: JWT <jwt>`，令牌有效期 2 小时。
- 商品导入为 multipart 请求，`upstream_price_value` 存储的是**加价百分比 + 100**
  （例如加价 20% 存 120）。
- 资源池类型（`is_resource = 1`）受三项设置约束：`allow_resource_api`、
  `allow_resource_api_phone`、`allow_resource_api_realname`。

**当上游使用**（本站对下游开放）：下游通过 `/v1/*` 调用本站接口，全部 110 个端点见
`/tmp/recon/api_v1_spec.json`（文档页面 `https://<站点>/document`）。下游客户端通过
「下游管理」查看与管理，本站的 `allow_resource_api*` 设置决定其可用范围。

## 数据迁移（可选）

若需从原平台迁移业务数据，可导出后按表导入；结构完全一致，无需字段映射：

```bash
mysqldump --no-create-info --single-transaction mfcw_782778_xyz \
    shd_clients shd_products shd_product_groups shd_pricing shd_host \
    shd_orders shd_invoices shd_invoice_items shd_accounts shd_credit \
    shd_ticket shd_ticket_reply shd_servers shd_server_groups \
    > /tmp/kjaiu_migrate.sql

mysql -D kjaiu < /tmp/kjaiu_migrate.sql
```

注意：客户端密码与管理员密码的散列方案完全一致（管理员 `md5`，客户 `###` + 双重 `md5`），
因此迁移后原密码仍然可用。

## 回滚

```bash
php artisan down            # 维护模式
git -C /www/Project/Kjaiu log --oneline -10
# 切换代码版本后
php artisan config:clear && php artisan config:cache
php artisan up
```

数据库结构未由迁移管理（直接使用与原始平台一致的 DDL），因此回滚代码不需要回滚结构。

## 安全说明

- 生产环境务必保持 `APP_DEBUG=false`；调试模式会把内部异常详情返回给接口调用方。
- 管理后台路径由 `KJAIU_ADMIN_PATH` 配置（默认 `admin`）；如需隐藏后台，可改为一个不易猜测的路径。
- 首次部署后请立即从环境配置中移除 `KJAIU_ADMIN_PASSWORD`，避免重复初始化。
- `/v1` 接口使用独立的 JWT 密钥 `KJAIU_JWT_SECRET`（未设置时回退到 `APP_KEY`），
  轮换该密钥会使所有已签发的下游令牌立即失效。

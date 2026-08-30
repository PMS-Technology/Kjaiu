# Kjaiu

Kjaiu 是一套基于 Laravel 13 的账务与财务系统，提供公开官网、客户门户、管理后台、商品与服务管理、订单结算、账单支付、余额交易及供应商开通能力，并提供与 IDCsmart Finance 核心接口兼容的 API。

## 主要功能

- 客户门户：商品浏览、购物车、结算、订单、账单、服务续费及个人资料管理
- 管理后台：客户、商品、账单、服务、账户余额和审计记录管理
- 财务核心：账户余额、充值账单、交易流水、库存预留、幂等结算与自动续费
- API：支持 IDCsmart Finance 风格的登录、商品、购物车、主机、账单和交易接口
- 供应商接入：供应商账户、目录同步、商品映射、异步开通及异常操作恢复
- 安全控制：独立 JWT 密钥、令牌吊销、请求限流、敏感字段加密和操作审计

> 当前内置的 `BankTransfer` 是线下支付渠道。其他外部支付渠道仍需实现签名校验、回调验签、金额与币种核对以及幂等入账，不应仅通过 `POST /v1/pay` 将账单标记为已支付。

## 技术栈

- PHP 8.3 或更高版本（64 位）
- Laravel 13
- MySQL 5.7.8+ 或 MySQL 8.x
- Blade、Tailwind CSS 4、Vite 7
- PHPUnit 12

## 环境要求

开始前请准备：

- PHP 8.3+（64 位）
- Composer 2
- MySQL，使用 InnoDB、`utf8mb4` 字符集
- Node.js `^20.19.0` 或 `>=22.12.0`
- npm

PHP 至少应启用 Laravel 和 MySQL 所需扩展，包括 `ctype`、`curl`、`fileinfo`、`filter`、`mbstring`、`openssl`、`PDO`、`pdo_mysql`、`session` 和 `tokenizer`。

SQLite 仅适合本地快速测试，无法覆盖 MySQL 行锁、死锁重试、精确小数和并发结算等行为。

## 快速开始

1. 克隆项目并进入目录：

```bash
git clone https://github.com/PMS-Technology/Kjaiu.git
cd Kjaiu
```

2. 创建环境配置：

```bash
cp .env.example .env
```

编辑 `.env`，至少填写数据库连接信息：

```dotenv
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kjaiu
DB_USERNAME=kjaiu
DB_PASSWORD=你的数据库密码
```

请先创建对应数据库和数据库用户，再执行初始化。

3. 安装依赖、生成密钥、执行迁移并构建前端资源：

```bash
composer run setup
```

该命令会依次安装 Composer 依赖、生成 `APP_KEY` 和独立的 `KJAIU_JWT_SECRET`、执行数据库迁移、安装 npm 依赖并构建前端资源。

4. 创建管理员并初始化支付渠道：

在 `.env` 中临时配置管理员信息：

```dotenv
KJAIU_ADMIN_NAME=Administrator
KJAIU_ADMIN_EMAIL=admin@example.com
KJAIU_ADMIN_PASSWORD=请替换为强密码
```

然后执行：

```bash
php artisan db:seed
```

确认管理员账户创建成功后，如果不需要自动重复执行种子数据，建议从生产环境配置中移除 `KJAIU_ADMIN_PASSWORD`。

5. 启动开发环境：

```bash
composer run dev
```

该命令会同时启动 Laravel 开发服务器、队列监听器、日志查看器和 Vite。默认可访问：

- 客户门户：<http://localhost:8000/portal>
- 管理后台：<http://localhost:8000/admin>
- 登录页面：<http://localhost:8000/login>

## 常用配置

以下项目配置位于 `.env`：

| 变量                    | 说明                      | 默认值                |
| ----------------------- | ------------------------- | --------------------- |
| `KJAIU_COMPANY_NAME`    | 公司或站点名称            | `Kjaiu`               |
| `KJAIU_COMPANY_EMAIL`   | 客服邮箱                  | `support@example.com` |
| `KJAIU_CURRENCY_CODE`   | 币种代码                  | `CNY`                 |
| `KJAIU_CURRENCY_PREFIX` | 金额前缀                  | `¥`                   |
| `KJAIU_CURRENCY_SUFFIX` | 金额后缀                  | `元`                  |
| `KJAIU_JWT_SECRET`      | API JWT 的独立 HS256 密钥 | 无                    |
| `KJAIU_JWT_TTL`         | JWT 有效期，单位为秒      | `7200`                |

如果需要单独生成或轮换 JWT 密钥，可执行：

```bash
php artisan kjaiu:jwt-key
php artisan kjaiu:jwt-key --force
```

不要将 `APP_KEY` 复用为 `KJAIU_JWT_SECRET`。轮换 `APP_KEY` 会影响加密数据和会话，轮换 `KJAIU_JWT_SECRET` 会使现有 API 令牌全部失效。

## API

API 路由直接挂载在应用根路径，不带 Laravel 默认的 `/api` 前缀。例如：

```http
GET /v1/login
GET /v1/products
POST /v1/login
GET /v1/user
```

需要认证的接口支持以下请求头：

```http
Authorization: JWT <token>
Accept: application/json
```

也支持 `Authorization: Bearer <token>`。为兼容 IDCsmart Finance，部分认证和校验错误通过 JSON 响应体中的 `status` 表示，客户端不能只依赖 HTTP 状态码。

完整接口、参数和响应约定请参阅 [API 文档](docs/API.md)。

## 定时任务

账单过期、自动续费、供应商操作恢复与开通轮询均由 Laravel 调度器负责。生产环境必须每分钟执行：

```cron
* * * * * cd /var/www/kjaiu && php artisan schedule:run >> /dev/null 2>&1
```

可通过以下命令检查已注册任务：

```bash
php artisan schedule:list
```

## 测试与质量检查

运行默认测试套件（使用内存 SQLite）：

```bash
composer run test
```

运行静态检查、格式检查和前端构建：

```bash
npm run lint:php
npm run format:check
npm run build
```

CI 还会在 MySQL 5.7.44 和 MySQL 8.4 上运行完整测试。不要对生产数据库执行测试，功能测试会重建数据库结构。

## 生产部署

生产环境至少应遵循以下要求：

- 将 Web 服务器根目录指向项目的 `public/`，不要指向仓库根目录
- 设置 `APP_ENV=production`、`APP_DEBUG=false` 和正确的 HTTPS `APP_URL`
- 设置 `SESSION_SECURE_COOKIE=true`，并建议启用 `SESSION_ENCRYPT=true`
- 保持 `APP_KEY` 与 `KJAIU_JWT_SECRET` 独立、稳定且不进入版本控制
- 赋予 Web 用户对 `storage/` 和 `bootstrap/cache/` 的写权限
- 部署时执行 `php artisan migrate --force` 和 `php artisan optimize`
- 配置 Laravel 调度器，并在需要异步队列时使用进程管理器守护队列工作进程
- 迁移前备份 MySQL 数据库

完整的构建、发布、供应商操作恢复、支付渠道上线门槛、验证和回滚流程请参阅 [部署文档](docs/DEPLOYMENT.md)。

## 项目结构

```text
app/                 应用业务、模型、控制器和中间件
config/              Laravel 与 Kjaiu 配置
database/migrations/ 数据库迁移
database/seeders/    初始支付渠道和可选管理员数据
docs/                API 与生产部署文档
resources/           Blade 视图、JavaScript 和样式
routes/              Web、API、控制台命令与调度配置
tests/               单元测试和功能测试
```

## 许可证

本项目基于 [GNU General Public License v3.0](LICENSE) 发布。

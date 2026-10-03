# Kjaiu

Kjaiu 是一套基于 **Laravel 13** 的 IDC 财务 / 账务系统，功能对标
[智简魔方财务 (ZJMF / IDCSmart Finance) v3.7.6](https://mfcw.782778.xyz)，
并保持 **上下游 API 与 `/v1` 公共 API 的线上兼容**：原有下游可直接接入，本站也可作为下游转售上游资源。

- 开发目录：`/www/Project/Kjaiu`
- 线上站点：<https://kjaiu.782778.xyz>
- 部署说明：[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)

## 特性

- **客户区 / 官网**：商品浏览与订购、购物车与结算、账单与支付、业务管理（开机/关机/重启/重装/续费/升降级）、工单、余额与交易流水、实名认证、API 管理、消息中心、帮助中心与下载。
- **管理后台**：客户、订单、业务、账单与交易、工单、商品与定价、可配置项、接口服务器、员工与权限、系统日志，全部走与原平台一致的 `admin/*` JSON 接口(路径可由 `KJAIU_ADMIN_PATH` 配置)。
- **公共 API（`/v1`）**：110 个端点，字段与返回结构与原平台一致，JWT 通过
  `authorization: JWT <token>` 传递。
- **上下游**：供应商管理（`zjmf_api` / `manual` / `resource` 三种类型）、上游商品导入与加价、订单与主机转发、资源池、任务队列；同时作为可被下游接入的上游。
- **自动化**：模块队列、出账、逾期暂停/删除、结清解封、上游同步、客户关怀，全部由 Laravel 调度器驱动。
- **兼容性**：数据库结构与原平台逐表一致（163 张 `shd_` 前缀表），账户密码散列方案一致，历史数据可直接导入。

## 技术栈

| 组件 | 版本 |
| --- | --- |
| PHP | 8.3+（64 位） |
| Laravel | 13.x |
| MySQL | 5.7.8+ / 8.x，`utf8mb4` |
| 前端 | Blade + Tailwind CSS 4（客户区）、Vue 3 + Element Plus（管理后台） |
| 测试 | PHPUnit 12 |

会话、缓存使用文件驱动；队列使用数据库驱动（镜像结构中不包含 `cache` / `sessions` 表）。

## 快速开始

```bash
# 依赖
COMPOSER_ALLOW_SUPERUSER=1 composer install

# 环境
cp .env.example .env
php artisan key:generate

# 导入与原始平台一致的库表结构（163 张表）
mysql -D kjaiu < database/schema/kjaiu.sql

# 初始化本站数据（管理员 / 货币 / 默认设置 / 工单状态）
php artisan db:seed

# 前端资源
npm install && npm run build
cd admin-spa && npm install && npm run build && cd ..

php artisan serve
```

访问：

- 客户区 / 官网：<http://localhost:8000>
- 管理后台：<http://localhost:8000/admin>

首次运行 `php artisan db:seed` 时，管理员密码取自 `KJAIU_ADMIN_PASSWORD`；
未设置则生成随机密码并打印一次，请立即保存。

## 目录结构

```
app/
  Auth/                 旧平台兼容的认证 Provider 与 Hasher
  Console/Commands/     调度命令（出账、暂停、删除、同步、队列）
  Http/Controllers/
    Api/V1/             公共 API（下游兼容）
    Admin/              管理后台 JSON 接口
    Web/                客户区与官网
  Integrations/Upstream/上游供应商客户端与资源池
  Models/               映射 shd_ 表的 Eloquent 模型
  Services/             定价、购物车、订单、账单、工单、开通、支付
  Support/              密码散列、JWT、响应结构
admin-spa/              管理后台 SPA 源码（独立 Vite 构建）
docs/                   部署文档
scripts/deploy.sh       发布脚本
```

## 测试

```bash
php artisan test
```

kjaiu.782778.xyz 本身就是测试站点，测试直接运行在站点库 `kjaiu_782778_xyz` 上（见 `phpunit.xml`），
涉及数据库的用例都包在事务里，结束即回滚。因此读写数据库的测试类必须 `use DatabaseTransactions`，
不能用 `RefreshDatabase`，也不要调用 `migrate:fresh` / `db:wipe`，否则会清空站点数据。
覆盖重点：旧版密码散列与校验、JWT 签发、循环计费与建账、优惠码、配置项加价、余额结算与流水记账。

## 兼容性说明

- **密码**：管理员为 `md5($plain)`；客户为 `"###" . md5(md5($authCode . $plain))`。
  客户区提交前会做 AES-128-CBC（key `idcsmart.finance`，IV `9311019310287172`）加密，
  服务端两种形式都接受。历史数据导入后原密码仍可登录。
- **金额与周期**：金额为 `decimal(10,2)`；计费周期列取值为 `-1` 表示该周期不提供。
- **上游商品加价**：`upstream_price_value` 存储的是**加价百分比 + 100**（加价 20% 存 120）。
- **接口路径**：公共 API 挂在根路径 `/v1/*`（非 `/api/v1/*`），与上游一致。

## 许可

见 [LICENSE](LICENSE)。

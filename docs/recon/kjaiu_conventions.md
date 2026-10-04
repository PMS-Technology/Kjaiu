# Kjaiu — build conventions (read before writing code)

Kjaiu is a Laravel 13 re-implementation of 智简魔方财务 (ZJMF / IDCSmart Finance) v3.7.6.
Goal: feature-similar to https://mfcw.782778.xyz and **wire-compatible** on the
upstream/downstream API surface.

## Stack / environment
- PHP 8.3, Laravel 13.34, MySQL 5.7.44, no Redis (cache = file), queue = database.
- Working dir: `/www/Project/Kjaiu`. Deploy target: `/www/wwwroot/kjaiu.782778.xyz`
  (nginx root = `<site>/public`, PHP 8.3).
- DB: `kjaiu_782778_xyz` / user `kjaiu_782778_xyz`. Client config for CLI SQL:
  `mysql --defaults-extra-file=/root/.kjaiu.cnf -D kjaiu_782778_xyz`.
- **All 163 tables already exist** in the `kjaiu_782778_xyz` database, byte-identical to the
  original's schema, with prefix `shd_` (set via `DB_PREFIX=shd_` in config/database.php).
  Baseline rows (settings, auth rules, nav, menus, currencies, ticket statuses) are
  already imported. Do NOT create migrations for these tables — they exist.
- Tests run against that same database: kjaiu.782778.xyz is the designated test site and
  there is no separate test database. Every test class that touches the database must
  `use DatabaseTransactions`; never `RefreshDatabase`, `migrate:fresh` or `db:wipe`,
  which would wipe the site.

## Money / time conventions
- Timestamps are **unix integers** in `create_time` / `update_time` / `*_time` columns.
  Models extend `App\Models\ShdModel` with `$timestamps = false`.
- Money is `decimal(10,2)`; round with `App\Services\PricingService::money()`.
- Billing cycle keys (schema order): `onetime, hour, day, ontrial, monthly, quarterly,
  semiannually, annually, biennially, triennially, fourly..tenly`. A cycle column of
  `-1` means "not offered".

## Response envelope (mandatory)
Every JSON response — API and admin — uses:
```json
{ "status": 200, "msg": "请求成功", "data": {} }
```
- `App\Support\ApiResponse::success($data, $msg, $extra)` / `::error(...)`.
- Status codes: `200` ok, `400` failure, `406` validation failure, `401` unauthenticated,
  `1001` soft success ("nothing to pay").
- Chinese messages, matching the original's wording where known.
- Pagination payload: `{ list, total, page, limit, total_page }`.
- Pagination query params: `page`, `limit`, `orderby`, `sort` (ASC/DESC), `keywords`.

## Auth
- Clients: `shd_clients`, guard `client`, provider `clients`.
- Admins: `shd_user`, guard `admin`, provider `admins`, admin URL prefix `admin` (config `KJAIU_ADMIN_PATH`).
- Both use `App\Auth\LegacyUserProvider` + `App\Auth\LegacyHasher`.
- Password hashes: admin = `md5($plain)`; client = `"###" . md5(md5($authCode . $plain))`
  via `App\Support\PasswordHasher`.
- Client-area forms AES-encrypt the password before submit (AES-128-CBC, key
  `idcsmart.finance`, IV `9311019310287172`, base64). Use
  `PasswordHasher::acceptedPlain()` to accept either encrypted or plain input.
- Public API (`/v1`) auth = JWT in header `authorization: JWT <token>`, issued by
  `App\Support\JwtService` (HS256, `userinfo.id`/`userinfo.username` claims, 2h TTL).
  Middleware alias: `api.auth`. Client-area middleware alias: `client.auth`.
  Admin middleware alias: `admin.auth`.

## Existing code you must reuse (do not duplicate)
- `app/Models/*` — Eloquent models for the mirrored schema (Client, User, Host, Product,
  Pricing, Invoice, InvoiceItem, Order, Account, Credit, Ticket, TicketReply,
  TicketDepartment, TicketStatus, Server, ServerGroup, PaymentGateway, PromoCode,
  ProductConfig*, Currency, Configuration, FinanceApi, UpperReach*, ModuleQueue,
  SystemMessage, Download*, CustomField*, Role, CartSession, ClientGroup, ...).
  `Configuration::value($key, $default)` reads `shd_configuration`.
- `app/Services/PricingService.php` — cycle price/setup fee, config-option totals,
  promo application, group discounts, `cycleDays()`, `nextDueDate()`.
- `app/Services/CartService.php` — cart in `shd_cart_session`, add/remove/qty/promo/totals.
- `app/Services/InvoiceService.php` — create invoice from items, pay with credit,
  markPaid, refund, `outstanding()`, invoice numbering.
- `app/Services/OrderService.php` — cart → order + invoice + Pending hosts + module queue.
- `app/Services/VerifyCodeService.php` — email/SMS verification codes.
- `app/Http/Controllers/Api/V1/ApiController.php` — base class with `ok()`, `fail()`,
  `pagination()`, `applyListQuery()`, `paginated()`, `money()`, `requireClient()`.

## Layout expectations
- Client area and storefront: Blade + Tailwind, server-rendered (the original is
  ThinkPHP templates; we are free on markup but keep the same URLs and JSON endpoints).
- Admin: JSON API under `admin/*` (config `KJAIU_ADMIN_PATH`) + a Vue 3 + Element Plus SPA.
- Keep the original's URL shapes where the client area uses `?action=` multiplexing
  (e.g. `/clientarea`, `/service`, `/servicedetail?id=N&action=renew`, `/billing`,
  `/cart`, `/pay`, `/login`, `/register`, `/pwreset`, `/ticket`).

## Known original quirks to preserve
- Consistent original typos in the API: `dafault_currencyid`, `pormo_code`,
  `delete_messgage` / `read_messgage`, `rebackd`.
- Ticket statuses are rows in `shd_ticket_status` (admin-editable), not an enum.
- Client-area routes carry no CSRF middleware; admin routes do.
- `/v1` endpoint list and field-level spec: `docs/recon/api_v1_spec.json` (110 endpoints)
  and `docs/recon/api_v1_spec.md`.

## Recon references (already written, read these)
- `docs/recon/clientarea.md` — 1,104-line client-area spec (routes, templates, fields,
  AJAX endpoints, enums with Chinese labels, assets).
- `docs/recon/admin.md` — ~106KB admin spec (menu → SPA route → chunk → API map, every
  form's field names, the 上下游/upstream section in depth).
- `docs/recon/routes.tsv` — all 1,665 original routes: `method \t rule \t controller/action \t options`.
- `docs/recon/columns.txt` — every table's columns.
- `docs/recon/schema_install.md` — schema with column comments.
- `docs/recon/api_v1_spec.json` — parsed /v1 docs (request + response fields per endpoint).

## Rules
- Run `php -l` on every file you write, and `php artisan route:list` after touching routes.
- Do not modify files outside your assigned area (parallel workstreams are running).
- Do not add dependencies without checking `composer.json` first (firebase/php-jwt is already in).
- Write real, working code — no TODOs or stubs standing in for the feature you were asked for.
- Chinese UI strings; code comments in English, only where they add information.

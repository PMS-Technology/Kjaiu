# Kjaiu

[English](README.md) | [简体中文](README.zh-CN.md)

Kjaiu is a Laravel 12 billing and hosting operations system. It combines a responsive administration console with an IDCsmart Finance v1-compatible API subset for authentication, catalog browsing, cart checkout, invoices, credit, transactions, and service renewal.

## What Is Included

- Client and administrator accounts with session and HS256 JWT authentication
- Product groups, products, multiple billing cycles, stock reservations, and automatic/manual provisioning states
- Cart checkout with zero-based positions, idempotency keys, stable lock ordering, and exact stock release
- Orders, invoices, invoice items, account transactions, credit payments, and recharge invoices
- Service creation, status management, monthly billing anchors, manual renewal, and credit-based automatic renewal
- Administrator dashboards for clients, products, invoices, services, transactions, and audited credit adjustments
- Legacy catalog endpoints and the core v1 route aliases used by IDCsmart Finance themes
- Scheduled invoice expiration and automatic renewal commands

This is a focused compatibility implementation, not a complete clone of every IDCsmart Finance module. Tickets, messages, referrals, identity verification, news, downloads, and marketing modules are not implemented.

## Requirements

- 64-bit PHP 8.2 or newer with `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pdo_mysql`, `session`, and `tokenizer`
- `pdo_sqlite` when running the default local test suite
- Composer 2
- MySQL 5.7.8+ or 8.x with InnoDB and `utf8mb4` (CI covers 5.7.44 and 8.4)
- Node.js `^20.19.0` or `>=22.12.0`
- npm

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan kjaiu:jwt-key
```

Configure the MySQL and `KJAIU_*` values in `.env`, then initialize the application:

```bash
php artisan migrate --seed
npm ci
npm run build
```

To create the first administrator during seeding, set these values before `php artisan db:seed`:

```dotenv
KJAIU_ADMIN_NAME=Administrator
KJAIU_ADMIN_EMAIL=admin@example.com
KJAIU_ADMIN_PASSWORD=replace-with-a-strong-password
```

The administrator console is available at `/admin`. The seeder also creates a bank-transfer channel and a small example product catalog.

Before the first production release, resolve dependencies in a trusted PHP/Composer environment and commit the generated `composer.lock`. Laravel's application skeleton does not include one, and production hosts must not resolve floating dependency ranges during deployment.

## Development

```bash
composer dev
```

Useful checks:

```bash
composer test
npm run lint:php
npm run format:check
npm run build
npm audit --audit-level=high
```

`npm run lint:php` uses a JavaScript PHP parser for a fast syntax pass. It does not replace PHPUnit, Laravel boot validation, or MySQL integration tests.

## Scheduled Work

Run Laravel's scheduler every minute in production:

```cron
* * * * * cd /var/www/kjaiu && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs:

| Command                 | Frequency        | Purpose                                                                                 |
| ----------------------- | ---------------- | --------------------------------------------------------------------------------------- |
| `kjaiu:expire-invoices` | Every 15 minutes | Cancel overdue unpaid invoices and release snapshotted stock reservations               |
| `kjaiu:auto-renew`      | Hourly           | Create one renewal invoice per service due date and pay it from available client credit |

## Payment Boundary

Kjaiu does not mark an external payment successful merely because a client selected a gateway. `POST /v1/pay` returns `requires_gateway: true` until a real gateway integration exists.

A gateway adapter must verify the signature, merchant order, external transaction number, amount, currency, and configured channel before calling `BillingService::recordPayment()`. The external transaction number is unique and settlement is transactional. The administration console can also record a confirmed bank, cash, or configured gateway payment manually.

See [`docs/API.md`](docs/API.md) for the API contract and [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for production and gateway integration requirements.

## Architecture

- `app/Services/BillingService.php`: transaction boundary for checkout, credit payment, verified external posting, cancellation, recharge, renewal, and provisioning
- `app/Services/JwtService.php`: independent two-hour HS256 API tokens with issuer, audience, and token-version checks
- `app/Http/Controllers/Api`: v1-compatible JSON controllers
- `app/Http/Controllers/Web`: administrator console controllers
- `database/migrations`: MySQL schema and finance invariants
- `routes/console.php`: key generation, invoice expiration, and automatic renewal
- `tests`: money, authentication, API contract, idempotency, stock, credit, and renewal tests

Amounts are stored as `DECIMAL(18,2)` and converted to integer minor units for calculations. Finance mutations use database transactions and row locks; production concurrency validation must run against MySQL, not SQLite.

MySQL 5.7 compatibility uses the native JSON type, the `utf8mb4_unicode_ci` collation, explicit InnoDB tables, and index-safe string lengths. MySQL 5.7 is upstream end-of-life; use 5.7.44 with vendor security support when an upgrade to MySQL 8 is not yet possible.

## Security

- Keep `APP_KEY` and `KJAIU_JWT_SECRET` independent and secret.
- Run `php artisan kjaiu:jwt-key` to generate a 256-bit API token key.
- Set `APP_DEBUG=false`, `APP_URL=https://...`, and `SESSION_SECURE_COOKIE=true` in production.
- Terminate TLS at the web server or trusted proxy and restrict administrator access appropriately.
- Password changes, API logout, client suspension, and client password resets increment the token version and invalidate existing API tokens.
- Do not expose `BillingService::recordPayment()` directly as an unsigned public route.

## License

Kjaiu is licensed under the [GNU General Public License version 3](LICENSE) only (`GPL-3.0-only`). Third-party dependencies remain subject to their respective licenses.

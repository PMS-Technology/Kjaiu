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
- Durable supplier provisioning with serialized legacy-cart mutations and safe host confirmation polling
- Scheduled invoice expiration, automatic renewal, supplier provisioning, and host polling commands

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

The committed `composer.lock` is generated and audited in the release workflow. Production deployments must install from this lock file or use a versioned deployment archive; they must not resolve floating dependency ranges.

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

| Command                             | Frequency        | Purpose                                                                                 |
| ----------------------------------- | ---------------- | --------------------------------------------------------------------------------------- |
| `kjaiu:expire-invoices`             | Every 15 minutes | Cancel overdue unpaid invoices and release snapshotted stock reservations               |
| `kjaiu:auto-renew`                  | Hourly           | Create one renewal invoice per service due date and pay it from available client credit |
| `kjaiu:supplier-reconcile-renewals` | Every minute     | Fail unsupported legacy queued supplier renewals without charging or HTTP               |
| `kjaiu:supplier-recover`            | Every minute     | Classify stale running supplier claims without replaying purchase mutations             |
| `kjaiu:supplier-process`            | Every minute     | Process due queued first-purchase supplier provisioning operations                      |
| `kjaiu:supplier-poll`               | Every minute     | Safely confirm supplier host state without replaying purchase mutations                 |

Supplier purchase routing comes from the immutable, hashed mapping snapshot written during local settlement. Later display or mapping changes do not alter a queued request. Legacy automatic supplier-credit payment is disabled by default. After settlement returns an upstream invoice, Kjaiu persists the invoice and host references without calling `/apply_credit`, leaves the local service `Pending`, and moves the operation to `blocked_credit` / `awaiting_manual_supplier_payment` with `legacy_payment_review_required` for operator review. A supplier host ID, an `Active` host, or a local paid invoice is not upstream payment proof and cannot activate that service.

For manual payment, open that exact invoice in the supplier console and verify its invoice ID, product, amount, and currency against the frozen operation and local invoice/service before paying it. After the supplier shows it paid, verify the host belongs to the same invoice, then use **Admin > Supplier Operations > 已在上游人工付款并确认主机** with the host ID, current administrator password, and explicit attestation. This records named human evidence, not cryptographic payment proof; it performs no supplier payment call and does not directly activate the service. Only a later read-only poll returning `Active` may activate it. The host-reconciliation action by itself is evidence-only and cannot confirm payment.

An advanced per-supplier compatibility option can explicitly enable legacy `/apply_credit`. Changing it in either direction requires the current administrator password, uses the shared supplier-sensitive rate limit, and is blocked while the account has nonterminal operations or pending order-item routes. The credential-only rotation exception does not apply. The option change is audited only as its old/new boolean state. This endpoint cannot carry the expected amount, currency, invoice version, or idempotency preconditions. That leaves a time-of-check/time-of-use and unbounded-debit window in which the invoice can change after quote validation, so the frozen quote cannot atomically cap the supplier's amount or currency debit. When opted in, only a structurally valid application status `1001` is durable payment confirmation; status `200` and unknown outcomes remain unconfirmed and are never replayed.

Automatic host polling and local activation require durable payment confirmation. A `running` provision is stale after 15 minutes without an update. Recovery requeues only a validated preflight claim with no mutation evidence, moves a payment-confirmed known host to read-only confirmation polling, and marks every unproven mutation outcome `ambiguous`. Supplier operations marked `blocked_credit` or `ambiguous` require administrator review. Never move them back to `queued` blindly. For `ambiguous`, do not retry purchase, settlement, payment, or any other supplier write; use only supplier-side evidence review and the administration page's evidence-only host reconciliation, which performs a supplier read and persists a local link without confirming payment.

Before `clearCart` starts, supplier client construction, authentication, `setConfig`, quote, DNS/TLS, and temporary read failures are provably mutation-free. Retryable preflight failures use `queued` with `available_at`: the first failure waits 60 seconds, the second waits 120 seconds, and the third total failure becomes `failed` with `preflight_retry_exhausted`. Deterministic snapshot, quote amount, and currency failures fail immediately. Due selectors require `available_at` to be null or in the past, so repeated command runs cannot skip the delay. Once any mutation step starts, no automatic replay is allowed.

An active supplier account may rotate only its saved credentials while operations are nonterminal, after current-administrator password verification. The account ID and frozen base URL identity stay unchanged, cached supplier JWTs are invalidated, and queued operations use the new credentials. Base URL, driver, code, active state, mapping, and the legacy-credit compatibility option remain blocked until affected operations are terminal. The scheduled legacy-renewal reconciler makes pre-release queued `renew` rows terminal with `unsupported_supplier_renewal`; it never charges or calls the supplier.

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
- `routes/console.php`: key generation, invoice expiration, automatic renewal, and supplier operation commands
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

# Kjaiu Deployment

## Production Prerequisites

- Linux or another supported PHP host
- 64-bit PHP 8.3 or newer and the extensions listed in `README.md`
- Composer 2 when building from source
- MySQL 5.7.8+ or 8.x using InnoDB and `utf8mb4`; CI covers 5.7.44 and 8.4
- Node.js `^20.19.0` or `>=22.12.0` when building frontend assets from source
- A TLS-enabled web server with the document root set to `public/`
- A process supervisor for queue workers if asynchronous jobs are added
- Cron or an equivalent scheduler

Laravel 13 is configured to retain PHP session serialization during this upgrade, so existing login sessions remain valid. Cache object unserialization is disabled by default.

SQLite is suitable for fast local feature tests only. It does not validate Kjaiu's MySQL row-lock, deadlock retry, decimal, and concurrent checkout behavior.

## Build And Release

Versioned GitHub releases provide deployment archives in `.tar.gz` and `.zip` formats. They include locked production Composer dependencies and compiled Vite assets, so Composer, Node.js, and npm are not required on the production host. Download one archive together with `SHA256SUMS`, then verify it before extraction:

```bash
sha256sum --check SHA256SUMS
tar -xzf kjaiu-VERSION.tar.gz
```

The release workflow builds these archives only from tags with a committed `composer.lock`. The automatically generated GitHub source archives are not deployment packages.

To build from source instead, use a new release directory and do not build over a live deployment:

```bash
test -f composer.lock
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run lint:php
npm run format:check
npm run build
```

Stop the release if `composer.lock` is absent. Generate and review it in a trusted development or CI environment with PHP and Composer, then commit it before deploying. Do not let a production host resolve the version ranges in `composer.json`.

Create the environment file on first deployment:

```bash
cp .env.example .env
php artisan key:generate
php artisan kjaiu:jwt-key
```

Required production settings include:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://billing.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kjaiu
DB_USERNAME=kjaiu
DB_PASSWORD=replace-me
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true

KJAIU_JWT_SECRET=base64:generated-by-kjaiu-jwt-key
KJAIU_JWT_TTL=7200
```

Do not reuse `APP_KEY` as `KJAIU_JWT_SECRET`. Keep both values stable across releases. Rotating `APP_KEY` invalidates encrypted application data and sessions; rotating `KJAIU_JWT_SECRET` invalidates all API tokens.

Before the first seed, optionally configure `KJAIU_ADMIN_NAME`, `KJAIU_ADMIN_EMAIL`, and `KJAIU_ADMIN_PASSWORD`. Remove the password from the deployed environment after confirming the account exists if automated reseeding is not required.

Run database and cache steps during release activation:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

Give the web user write access only where Laravel requires it:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

The web server document root must be `/path/to/kjaiu/public`, never the repository root.

## Scheduler

Install one cron entry:

```cron
* * * * * cd /var/www/kjaiu && php artisan schedule:run >> /dev/null 2>&1
```

Use `php artisan schedule:list` after every release to verify registration. `withoutOverlapping()` requires a functioning cache store; the default production configuration uses the database cache table. Supplier commands remain foreground processes so scheduler output and failures stay observable and overlap locks are released by the same scheduler process.

## Queue Worker

The current finance mutations execute synchronously. If gateway notifications are moved to jobs, run a supervised worker:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Restart workers after every release:

```bash
php artisan queue:restart
```

## Supplier Operation Outbox

Settling an invoice for a mapped first purchase writes a durable `queued` row to `supplier_operations` in the same database transaction. Settlement does not contact the supplier or dispatch a job. This release does not create supplier renewal operations. `kjaiu:supplier-reconcile-renewals` idempotently marks any pre-release queued `renew` rows `failed` with `unsupported_supplier_renewal`, without charging or supplier HTTP.

The scheduler runs `kjaiu:supplier-reconcile-renewals --limit=500`, `kjaiu:supplier-recover --limit=100`, `kjaiu:supplier-process --limit=20`, and `kjaiu:supplier-poll --limit=50` every minute, in that registration order. They reconcile unsupported legacy renewals, inspect stale claims, process due queued first-purchase provisioning, and perform due host confirmations respectively. All four commands use `withoutOverlapping()` and continue with later records when one operation fails. Manual limits are clamped to `1..1000` for renewal reconciliation, `1..500` for recovery, `1..100` for processing, and `1..200` for polling.

Provisioning mutations are never automatically replayed after an unknown outcome. Purchase parameters and supplier/mapping/catalog/product references are read from the encrypted, hashed `request_payload.mapping` snapshot created during local settlement, not mutable live catalog routing or options. The processor verifies the snapshot hash, duplicated upstream fields, local ownership, referenced record existence, and account consistency before any HTTP request. Snapshot or reference corruption fails closed before supplier I/O. Top-level and `data` invoice/host references must resolve to at most one identical, bounded scalar ID of each type; conflicting IDs, multi-value arrays, control characters, and IDs over 128 bytes fail closed before credit is attempted.

Legacy supplier-credit payment is disabled by default through `supplier_accounts.options.allow_legacy_unbounded_credit_payment`. Missing values and every value other than boolean `true` are disabled. After `clear_cart` recovery or `settle_cart` returns an invoice, the processor first persists the owned invoice and any host reference. With the option disabled it does not call `POST /apply_credit`; it leaves the local service `Pending` and records `status=blocked_credit`, `step=awaiting_manual_supplier_payment`, and `last_error_code=legacy_payment_review_required`. The operation is excluded from both purchase and polling selectors and requires supplier-side manual payment review.

For a default-off `awaiting_manual_supplier_payment` operation, first open the exact existing invoice in the supplier console and match its invoice ID, product, amount, and currency against the frozen operation and local invoice/service. Pay that invoice through the supplier console, verify its paid state there, and identify the host created for that same invoice. In **Admin > Supplier Operations**, open the operation and use **已在上游人工付款并确认主机** only after all of those values and the host ownership match. Enter the bounded opaque host ID and the explicit attestation checkbox. Kjaiu performs only `GET /host/header` outside the database transaction, then locks and revalidates the operation, account, service, immutable route, invoice link, and any service link before recording `payment_confirmed=true`, `payment_confirmation=admin_attested`, the administrator ID, confirmation time, and `Paid` invoice-link status. The operation enters `awaiting_confirmation`; only a subsequent safe-read host result of `Active` can activate the local service.

This action is a durable human attestation based on supplier-side evidence, not cryptographic payment verification and not proof supplied by the Finance API. A readable or even `Active` host alone does not attest payment. **按供应商证据关联主机** remains evidence-only and cannot activate an unpaid/unattested operation. Never use either action as a generic purchase retry, and never paste credentials, JWTs, request/response payloads, correlation tokens, or machine passwords into the host-ID field.

The administration form exposes the option only as an advanced high-risk compatibility control. Enabling or disabling it uses the shared `supplier-sensitive` limiter. It is a connection-policy change, so the account model and controller both reject it while the account has nonterminal operations or order-item routes belonging to `Pending` orders; the credential-only rotation exception does not apply. Audit data represents the option only as its old/new boolean value and never includes raw options or credentials.

Opting in retains the strict legacy path, but does not make it atomic. `/apply_credit` accepts an invoice ID and broad credit-use flags but cannot carry the frozen expected amount, currency, invoice version, or an idempotency precondition. This creates a time-of-check/time-of-use and unbounded-debit risk: the upstream invoice can change after Kjaiu checks the frozen quote but before the supplier applies credit, with no atomic compare-and-pay guard. The supplier can therefore debit an amount or currency that the frozen quote cannot atomically cap. Only a structurally valid application status `1001` with matching durable invoice evidence confirms payment. Authentication, timeout, malformed, status `200`, and otherwise unknown outcomes remain fail-closed and are never replayed.

The **兼容自动扣余额** recovery control is shown and accepted only when the account option is exactly boolean `true`, the operation is the exact `blocked_credit` / `upstream_credit_insufficient` provision state, and the owned supplier invoice ID is present and unpaid. It requires explicit risk confirmation, CSRF protection, and its existing five-attempts-per-minute throttle. One recovery invocation makes at most one `/apply_credit` call for that existing invoice and never repeats cart or settlement calls. An unknown outcome becomes `ambiguous` and must not be replayed.

Client construction, authentication, `setConfig`, quote, DNS/TLS, and temporary response-read failures before the `clearCart` mutation checkpoint are safe preflight failures. Retryable auth/transport/read failures increment `metadata.preflight_failures`. Failure 1 returns to `queued` for 60 seconds, failure 2 for 120 seconds, and failure 3 becomes `failed` with `preflight_retry_exhausted`. `kjaiu:supplier-process` and direct processor claims both require `available_at` to be null or due. Invalid immutable snapshots and deterministic quote amount/currency failures are terminal immediately. After the `clearCart` mutation checkpoint, existing ambiguous/no-replay rules apply unchanged.

The per-supplier-account cart lock has a fixed 900-second lease, safely above the reviewed worst-case sequence of authentication plus clear/add/settle/credit requests at the 30-second request timeout. The lease is not renewed. Operators must keep that sequence below 15 minutes and investigate transport stalls rather than increasing endpoint retries; mutation HTTP calls are never replayed automatically.

A `running` provision is considered stale after 15 minutes without an `updated_at` change. This is deliberately longer than the 30-second supplier HTTP timeout and the normal checkpoint interval. Recovery is local-only and never invokes a supplier write endpoint:

1. `claimed`, `preflight`, or `validation` with a valid snapshot and no response/link/reference evidence returns to `queued`.
2. Any `clear_cart`, `add_to_cart`, `settle_cart`, `apply_credit`, or unknown post-claim step is `ambiguous` unless durable payment confirmation and a safe host identifier are already persisted.
3. A conflict or invalid persisted reference is `ambiguous` and retains existing evidence for review.
4. A valid known host without confirmed payment is observation evidence only. A default-disabled payment operation remains `blocked_credit` / `awaiting_manual_supplier_payment`; other unconfirmed operations remain `blocked_credit` or `ambiguous`. None is automatically polled or activated.
5. A structurally valid `/apply_credit` application status `1001` records `payment_confirmed=true`, application status `1001`, and the matching supplier invoice ID. A paid operation with no known host becomes `ambiguous` with `host_reconciliation_required` and remains excluded from automatic polling until an administrator validates and attaches a host.
6. A payment-confirmed known host is linked locally and moved to `awaiting_confirmation`; only the existing read-only `GET /host/header` poll follows.
7. Repeated recovery is idempotent because only stale `running` provisions are selected.

Selectors otherwise consume only `provision` operations in the exact eligible state: `queued` for purchasing and `awaiting_confirmation` for safe polling. `running`, `succeeded`, `ambiguous`, `blocked_credit`, `failed`, and renewal operations are excluded from purchase sweeps. Repeated command runs therefore cannot repeat a completed or indeterminate purchase sequence.

Administrators may rotate only the credentials of an active supplier account while operations are nonterminal. The update keeps the account and frozen base URL identity unchanged, invalidates JWT cache entries for both credential identities, and records only credential-change booleans in the audit log. Base URL, driver, code, disable/reactivation, mapping, and the legacy-credit compatibility option remain blocked. Existing queued operations resolve the same account at claim time and therefore use the rotated credentials without changing their immutable route.

The reviewed IDCsmart Finance assumptions for this release are:

1. `POST /cart/clear` may return application status `200` for an empty/recovered cart or `400` with an existing `invoiceid` or `hostid` for recovery.
2. `POST /cart/settle` succeeds only when application status `200` or `1001` contains an `invoiceid` or `hostid`.
3. `POST /apply_credit` is not called unless `allow_legacy_unbounded_credit_payment` is exactly boolean `true`. When opted in, it confirms payment only with a structurally valid application status `1001`. Application status `200` is never proof of payment. Status `200` or `400` is `blocked_credit` only when its message explicitly combines a credit/balance term with an insufficient/not-enough term (including the observed Chinese equivalents); every other non-`1001` outcome remains ambiguous.
4. `GET /host/header` succeeds only when `data.host_data` is an object. Only host status `Active` may activate the local service, and only when the operation still has matching durable payment evidence. `Failed`, `Cancelled`/`Canceled`, `Deleted`, and `Suspended` are terminal non-active outcomes. Other states remain pending until the bounded poll count is exhausted.
5. Optional `host_data.regdate` and `host_data.nextduedate` values are accepted only as Unix seconds or exact `Y-m-d`, `Y-m-d H:i:s`, RFC 3339 `Y-m-dTH:i:sP`, or UTC `Y-m-dTH:i:sZ` strings. Values must fall from 2000-01-01 through 2100-01-01, registration cannot be more than five minutes in the future, next due must be future and later than registration, and malformed or nonsensical values prevent activation. On first activation, valid upstream registration aligns local `registered_at` and `activated_at`, and valid upstream next due aligns `next_due_at`; absent fields use the existing local activation calculation. Existing activation terms are preserved on later recovery polls.

Monitor `supplier_operations` for `ambiguous`, `blocked_credit`, `failed`, and `poll_exhausted` outcomes. `blocked_credit` and `ambiguous` always require administrator review. In particular, `awaiting_manual_supplier_payment` means Kjaiu deliberately created or recovered the upstream invoice without attempting legacy credit payment. Reconcile it against the exact supplier-side invoice ID, product, amount, currency, payment state, and host evidence; a host status alone is not payment confirmation. For `ambiguous`, do not retry purchase, settlement, payment, or any other supplier write. Use only evidence review and the administration page's evidence-association/reconciliation control, which reads the supplier host but persists only a local link and does not confirm payment. Do not move operations back to `queued` or invoke write endpoints manually without first proving that no purchase or payment was completed.

## Payment Gateway Release Gate

`BankTransfer` is seeded as an offline channel. No online gateway is complete until all of these are implemented and reviewed:

1. Encrypted channel configuration and secret rotation procedure
2. Server-to-server request signing or provider SDK integration
3. A callback route with gateway-specific signature and replay validation
4. Exact invoice amount and currency comparison
5. Stable external transaction-number extraction
6. Idempotent invocation of `BillingService::recordPayment()`
7. Provider acknowledgement and retry behavior
8. Feature tests for invalid signatures, underpayment, overpayment, wrong currency, duplicate callbacks, and callbacks racing invoice cancellation

Do not change `POST /v1/pay` to mark invoices paid. It only selects a channel and tells the client that a gateway step is required.

## Verification

Run the complete test suite in CI against disposable SQLite and MySQL databases before building the production artifact. Never run `php artisan test` against a deployed environment or a database containing persistent data; feature tests intentionally rebuild the schema.

Run these non-destructive checks in the release environment:

```bash
php artisan about
php artisan route:list
php artisan view:cache
php artisan migrate:status
npm run lint:php
npm run format:check
npm run build
```

Before release, run integration passes against disposable MySQL 5.7 and 8.x databases whose names end in `_test`. At minimum, exercise concurrent checkout against the last unit of stock, reversed product lock order, payment racing expiration, duplicate external callbacks, duplicate credit payments, partial cart checkout, and automatic renewal with insufficient credit. The application refuses to boot in the testing environment if the configured database is not in-memory SQLite or explicitly named as a test database.

For MySQL 5.7, use 5.7.44 where possible and verify `default_storage_engine=InnoDB`. Kjaiu deliberately uses `utf8mb4_unicode_ci` instead of MySQL 8-only collations and limits indexed strings to legacy-safe lengths. Native JSON requires MySQL 5.7.8 or newer. MySQL 5.7 is upstream end-of-life, so deployments should use vendor security support or plan an upgrade to MySQL 8.

Smoke-test:

1. `/up` returns a healthy response.
2. `/login` renders with the Vite manifest present.
3. An active administrator can sign in and a suspended administrator cannot reuse a session.
4. `GET /v1/login` returns the method document.
5. Nested email login returns a JWT and `Authorization: JWT ...` can access `/v1/user`.
6. A product can be added, checked out once with an idempotency key, and paid once from credit.
7. An external gateway selection leaves the invoice unpaid.
8. `php artisan schedule:list` includes invoice expiration, automatic renewal, legacy supplier renewal reconciliation, stale supplier recovery, supplier provisioning, and supplier host polling.

## Backup And Rollback

Back up the MySQL database before migrations. Application rollback must deploy the previous release directory; never use destructive Git commands in the live tree.

Database migrations in this initial release create the complete Kjaiu schema. Once production data exists, do not edit historical migration files. Add forward-only migrations for all later schema changes and prepare a data-compatible rollback plan.

If a release fails after migration, keep the application in maintenance mode, diagnose whether the previous code can read the new schema, and restore from backup only when a forward fix is not safe.

# Kjaiu Deployment

## Production Prerequisites

- Linux or another supported PHP host
- 64-bit PHP 8.2 or newer and the extensions listed in `README.md`
- Composer 2 when building from source
- MySQL 5.7.8+ or 8.x using InnoDB and `utf8mb4`; CI covers 5.7.44 and 8.4
- Node.js `^20.19.0` or `>=22.12.0` when building frontend assets from source
- A TLS-enabled web server with the document root set to `public/`
- A process supervisor for queue workers if asynchronous jobs are added
- Cron or an equivalent scheduler

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

Use `php artisan schedule:list` after every release to verify registration. `withoutOverlapping()` requires a functioning cache store; the default production configuration uses the database cache table.

## Queue Worker

The current finance mutations execute synchronously. If gateway notifications or provisioning are moved to jobs, run a supervised worker:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Restart workers after every release:

```bash
php artisan queue:restart
```

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
8. `php artisan schedule:list` includes invoice expiration and automatic renewal.

## Backup And Rollback

Back up the MySQL database before migrations. Application rollback must deploy the previous release directory; never use destructive Git commands in the live tree.

Database migrations in this initial release create the complete Kjaiu schema. Once production data exists, do not edit historical migration files. Add forward-only migrations for all later schema changes and prepare a data-compatible rollback plan.

If a release fails after migration, keep the application in maintenance mode, diagnose whether the previous code can read the new schema, and restore from backup only when a forward fix is not safe.

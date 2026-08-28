<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRuntimeSettings();
        RateLimiter::for('supplier-sensitive', function (Request $request): array {
            $administrator = $request->user()?->getAuthIdentifier();
            $key = ($administrator === null ? 'guest' : 'admin:'.$administrator)
                .'|ip:'.$request->ip();
            $response = fn (Request $request, array $headers) => response(
                'Too many supplier administration attempts.',
                429,
                $headers,
            );
            $limits = [Limit::perMinute(10)->by($key)->response($response)];

            if ($request->routeIs('admin.suppliers.test-active')) {
                $limits[] = Limit::perMinute(5)->by($key.'|test')->response($response);
            } elseif ($request->routeIs('admin.suppliers.catalog-sync')) {
                $limits[] = Limit::perMinute(2)->by($key.'|catalog-sync')->response($response);
            }

            return $limits;
        });

        $currency = (string) config('kjaiu.currency.code', 'CNY');
        if ($currency === '' || mb_strlen($currency) > 8) {
            throw new RuntimeException('KJAIU_CURRENCY_CODE must contain between 1 and 8 characters.');
        }

        if (! $this->app->environment('testing')) {
            return;
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.$connection.database");
        $safe = ($connection === 'sqlite' && $database === ':memory:')
            || str_ends_with($database, '_test');

        if (! $safe) {
            throw new RuntimeException('Refusing to run tests against a database that is not explicitly disposable.');
        }
    }

    private function loadRuntimeSettings(): void
    {
        try {
            if (! Schema::hasTable('system_settings') || ! ($settings = \App\Models\SystemSetting::query()->find(1))) {
                return;
            }
            config([
                'app.name' => $settings->site_name,
                'app.url' => $settings->site_url ?: config('app.url'),
                'kjaiu.company_name' => $settings->site_name,
                'kjaiu.site.logo_url' => $settings->logo_url,
                'kjaiu.site.favicon_url' => $settings->favicon_url,
            ]);
            $mail = $settings->mail_configuration;
            if (is_array($mail) && filled($mail['host'] ?? null)) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $mail['host'],
                    'mail.mailers.smtp.port' => $mail['port'],
                    'mail.mailers.smtp.scheme' => $mail['scheme'],
                    'mail.mailers.smtp.username' => $mail['username'],
                    'mail.mailers.smtp.password' => $mail['password'],
                    'mail.from.address' => $mail['from_address'],
                    'mail.from.name' => $mail['from_name'],
                ]);
            }
        } catch (Throwable) {
            // Installation and migration commands must work before the settings table exists.
        }
    }
}

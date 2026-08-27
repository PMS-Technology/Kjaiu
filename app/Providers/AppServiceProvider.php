<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
}

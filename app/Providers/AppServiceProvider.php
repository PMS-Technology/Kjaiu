<?php

namespace App\Providers;

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

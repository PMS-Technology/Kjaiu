<?php

namespace App\Providers;

use App\Auth\LegacyHasher;
use App\Auth\LegacyUserProvider;
use App\Models\Client;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The mirrored schema uses md5-based password columns, so the framework
        // hasher is wrapped rather than replaced outright.
        $this->app->extend(Hasher::class, function (Hasher $hasher, $app) {
            return new LegacyHasher($hasher);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Client and administrator accounts both authenticate against legacy
        // credential columns, so both guards use the custom provider.
        Auth::provider('legacy', function ($app, array $config) {
            return new LegacyUserProvider($config['model'], $app->make(Hasher::class));
        });

        Password::defaults(function () {
            return $this->app->isProduction()
                ? Password::min(8)->letters()->numbers()
                : Password::min(8);
        });

        // Client rows carry no remember-token column in the original schema.
        Client::unguard();
    }
}

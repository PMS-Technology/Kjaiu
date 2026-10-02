<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the administrator API surface.
 *
 * The admin routes live in their own file (routes/admin.php) because they are
 * large and maintained separately from the storefront and the public API.
 *
 * They are normally mounted by bootstrap/app.php, which loads
 * routes/admin.php before routes/admin_spa.php so the SPA shell's catch-all
 * cannot shadow the JSON API. This provider re-registers them for callers that
 * build the application without that wiring (test harnesses, ad-hoc console
 * scripts); it is a no-op when the routes are already present.
 */
class AdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! is_file(base_path('routes/admin.php'))) {
            return;
        }

        if ($this->alreadyRegistered()) {
            return;
        }

        Route::middleware('web')
            ->group(base_path('routes/admin.php'));
    }

    /**
     * Whether the administrator routes were registered by bootstrap/app.php.
     */
    protected function alreadyRegistered(): bool
    {
        if (! $this->app->bound('router')) {
            return false;
        }

        $path = '/'.trim((string) config('kjaiu.admin_path', 'admin123'), '/');

        foreach ($this->app['router']->getRoutes() as $route) {
            if ('/'.$route->uri() === $path.'/login_page') {
                return true;
            }
        }

        return false;
    }
}

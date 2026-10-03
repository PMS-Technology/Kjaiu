<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithClientArea;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Route-level smoke coverage for the customer-facing web application.
 *
 * Every GET route the client area exposes is exercised while signed in, so a
 * controller, Blade or query error surfaces as a test failure rather than a
 * blank page in production.
 *
 * The route sweep touches nearly every client-area page, which means it writes
 * through tables the fixture cleanup does not know about; the transaction is
 * what keeps a run from leaving rows in the database.
 */
class ClientAreaRoutesTest extends BaseTestCase
{
    use DatabaseTransactions;
    use InteractsWithClientArea;

    public function createApplication()
    {
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeClient();
    }

    protected function tearDown(): void
    {
        $this->removeClient();

        parent::tearDown();
    }

    /**
     * Every GET route owned by the Web controllers, with path parameters
     * replaced by a placeholder value.
     *
     * @return array<int, array{0:string}>
     */
    protected function webGetRoutes(): array
    {
        $routes = app('router')->getRoutes();
        $targets = [];

        foreach ($routes as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, 'Web\\') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            // Logout mutates the session; framework routes are not ours.
            if (in_array($uri, ['logout', 'logOut', 'up'], true) || str_starts_with($uri, 'storage/')) {
                continue;
            }

            $targets['/' . preg_replace('/\{[^}]+\}/', '1', $uri)] = ['/' . preg_replace('/\{[^}]+\}/', '1', $uri)];
        }

        return $targets;
    }

    public function test_client_area_get_routes_render(): void
    {
        $failures = [];

        foreach ($this->webGetRoutes() as [$uri]) {
            try {
                $response = $this->get($uri);

                if ($response->getStatusCode() >= 500) {
                    $failures[] = "{$uri} -> {$response->getStatusCode()}";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$uri} -> " . get_class($e) . ': ' . $e->getMessage();
            }
        }

        $this->assertSame([], $failures, "Failing client-area routes:\n" . implode("\n", $failures));
    }

    public function test_guest_storefront_routes_render(): void
    {
        foreach (['/', '/cart', '/login', '/register', '/pwreset', '/news', '/knowledgebase', '/downloads'] as $uri) {
            $response = $this->get($uri);

            $this->assertLessThan(500, $response->getStatusCode(), "GET {$uri} returned {$response->getStatusCode()}");
        }
    }

    /**
     * Perform a GET request as the signed-in client.
     *
     * `App\Models\Client` does not implement Authenticatable in the mirrored
     * schema, so the guard cannot hold it; the request-level resolver is bound
     * instead, which is the same hook `AuthenticateApi` uses.
     */
}

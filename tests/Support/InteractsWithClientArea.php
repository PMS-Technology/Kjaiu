<?php

namespace Tests\Support;

use App\Models\Client;
use App\Support\PasswordHasher;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\DB;

/**
 * Client-area test scaffolding.
 *
 * `App\Models\Client` does not implement `Authenticatable` in the mirrored
 * schema, so the framework's `actingAs()` helper and the `client` session guard
 * cannot hold it. This trait swaps the guard for a request guard returning the
 * fixture client — the same mechanism `AuthenticateApi` uses for the public
 * API — so the controllers run against exactly the code path production uses.
 */
trait InteractsWithClientArea
{
    protected Client $client;

    /**
     * Create the fixture client and pin it to the `client` guard.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeClient(array $attributes = []): Client
    {
        $this->client = Client::create(array_merge([
            'username' => 'SmokeUser',
            'email' => 'smoke-' . substr(md5((string) microtime(true)), 0, 6) . '@example.test',
            'phone_code' => 86,
            'phonenumber' => '139' . random_int(10000000, 99999999),
            'password' => PasswordHasher::client('secret123'),
            'status' => 1,
            'credit' => 100,
            'currency' => 1,
            'groupid' => 0,
            'api_password' => 'smokekey12345678',
            'api_open' => 1,
            'api_create_time' => time(),
            'create_time' => time(),
            'update_time' => time(),
        ], $attributes));

        $this->pinClientGuard($this->client);

        return $this->client;
    }

    /**
     * Replace the `client` guard with a request guard for this fixture.
     */
    protected function pinClientGuard(Client $client): void
    {
        $manager = $this->app['auth'];
        $manager->forgetGuards();

        // Rebuild the configured guard first, then overwrite the cached entry.
        $manager->guard('client');

        $reflection = new \ReflectionProperty($manager, 'guards');
        $guards = $reflection->getValue($manager);

        $guards['client'] = new RequestGuard(
            fn () => $client,
            $this->app['request'],
            $manager->createUserProvider('clients') ?? $manager->createUserProvider(),
        );

        $reflection->setValue($manager, $guards);
    }

    /**
     * Remove the fixture client and its rows.
     */
    protected function removeClient(): void
    {
        if (! isset($this->client)) {
            return;
        }

        $id = $this->client->id;

        foreach ([
            'host', 'invoices', 'ticket', 'accounts', 'credit', 'system_message',
        ] as $table) {
            DB::table($table)->where('uid', $id)->delete();
        }

        DB::table('cart_session')->where('uid', (string) $id)->delete();
        DB::table('clients')->where('id', $id)->delete();
    }

    /**
     * Every GET route owned by the Web controllers, with path parameters
     * replaced by a placeholder value.
     *
     * @return array<int, array{0:string}>
     */
    protected function webGetRoutes(): array
    {
        $targets = [];

        foreach (app('router')->getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, 'Web\\') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            // Logout mutates the session; the rest are framework routes.
            if (in_array($uri, ['logout', 'logOut', 'up'], true) || str_starts_with($uri, 'storage/')) {
                continue;
            }

            $path = '/' . preg_replace('/\{[^}]+\}/', '1', $uri);
            $targets[$path] = [$path];
        }

        return $targets;
    }

    /**
     * Send requests the way the client-area JavaScript does.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        return parent::call($method, $uri, $parameters, $cookies, $files, array_merge([
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json',
        ], $server), $content);
    }
}

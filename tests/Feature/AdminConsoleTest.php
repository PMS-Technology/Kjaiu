<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_administrator_can_render_every_console_section(): void
    {
        $this->withoutVite();
        $administrator = User::factory()->create([
            'role' => 'admin',
            'status' => 'Active',
        ]);

        foreach (['/admin', '/admin/customers', '/admin/products', '/admin/invoices', '/admin/services', '/admin/finance'] as $path) {
            $this->actingAs($administrator)
                ->get($path)
                ->assertOk()
                ->assertSee('返回客户门户');
        }
    }

    public function test_user_management_lists_administrators_as_users(): void
    {
        $this->withoutVite();
        $administrator = User::factory()->administrator()->create(['name' => 'Primary Administrator']);

        $this->actingAs($administrator)
            ->get('/admin/customers')
            ->assertOk()
            ->assertSee('用户管理')
            ->assertSee('Primary Administrator')
            ->assertSee('管理员');
    }

    public function test_an_administrator_can_promote_a_user_and_revoke_existing_credentials(): void
    {
        $this->useDatabaseSessions();
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'token_version' => 3,
            'remember_token' => 'old-remember-token',
        ]);
        $sessionId = $this->addSession($user);

        $this->actingAs($administrator)->put('/admin/customers/'.$user->id, $this->customerPayload($user, [
            'role' => User::ROLE_ADMIN,
        ]))->assertRedirect('/admin/customers')->assertSessionHas('success');

        $updated = $user->fresh();
        $this->assertTrue($updated->isAdministrator());
        $this->assertSame(4, $updated->token_version);
        $this->assertNotSame('old-remember-token', $updated->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.updated',
            'subject_id' => $user->id,
        ]);
    }

    public function test_the_last_active_administrator_cannot_be_demoted_or_suspended(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->put('/admin/customers/'.$administrator->id, $this->customerPayload($administrator, [
            'role' => User::ROLE_CLIENT,
        ]))->assertSessionHasErrors('role');
        $this->assertSame(User::ROLE_ADMIN, $administrator->fresh()->role);

        $this->put('/admin/customers/'.$administrator->id, $this->customerPayload($administrator, [
            'status' => 'Suspended',
        ]))->assertSessionHasErrors('role');
        $this->assertSame('Active', $administrator->fresh()->status);
    }

    public function test_an_administrator_can_demote_another_administrator(): void
    {
        $administrator = User::factory()->administrator()->create();
        $otherAdministrator = User::factory()->administrator()->create(['token_version' => 5]);

        $this->actingAs($administrator)->put(
            '/admin/customers/'.$otherAdministrator->id,
            $this->customerPayload($otherAdministrator, ['role' => User::ROLE_CLIENT]),
        )->assertRedirect('/admin/customers');

        $updated = $otherAdministrator->fresh();
        $this->assertSame(User::ROLE_CLIENT, $updated->role);
        $this->assertSame(6, $updated->token_version);
    }

    public function test_admin_password_reset_rotates_credentials_and_revokes_customer_database_sessions(): void
    {
        $this->useDatabaseSessions();
        $administrator = User::factory()->administrator()->create();
        $customer = User::factory()->create([
            'password' => 'old-password',
            'token_version' => 7,
            'remember_token' => 'old-remember-token',
        ]);
        $otherCustomer = User::factory()->create();
        $customerSessions = [$this->addSession($customer), $this->addSession($customer)];
        $otherSession = $this->addSession($otherCustomer);

        $this->actingAs($administrator)->put('/admin/customers/'.$customer->id, $this->customerPayload($customer, [
            'password' => 'new-password',
        ]))->assertRedirect('/admin/customers')->assertSessionHas('success');

        $updated = $customer->fresh();
        $this->assertTrue(Hash::check('new-password', $updated->password));
        $this->assertSame(8, $updated->token_version);
        $this->assertNotSame('old-remember-token', $updated->remember_token);
        foreach ($customerSessions as $sessionId) {
            $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        }
        $this->assertDatabaseHas('sessions', ['id' => $otherSession]);
    }

    public function test_admin_suspension_and_reactivation_each_revoke_stale_customer_sessions(): void
    {
        $this->useDatabaseSessions();
        $administrator = User::factory()->administrator()->create();
        $customer = User::factory()->create([
            'status' => 'Active',
            'token_version' => 4,
            'remember_token' => 'active-remember-token',
        ]);
        $suspendedSession = $this->addSession($customer);

        $this->actingAs($administrator)->put('/admin/customers/'.$customer->id, $this->customerPayload($customer, [
            'status' => 'Suspended',
        ]))->assertRedirect('/admin/customers');
        $suspended = $customer->fresh();
        $this->assertSame('Suspended', $suspended->status);
        $this->assertSame(5, $suspended->token_version);
        $this->assertNotSame('active-remember-token', $suspended->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => $suspendedSession]);

        $reactivationRememberToken = $suspended->remember_token;
        $staleSession = $this->addSession($suspended);
        $this->put('/admin/customers/'.$customer->id, $this->customerPayload($suspended, [
            'status' => 'Active',
        ]))->assertRedirect('/admin/customers');
        $reactivated = $customer->fresh();
        $this->assertSame('Active', $reactivated->status);
        $this->assertSame(6, $reactivated->token_version);
        $this->assertNotSame($reactivationRememberToken, $reactivated->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => $staleSession]);
    }

    public function test_admin_security_change_is_safe_with_a_non_database_session_store(): void
    {
        $administrator = User::factory()->administrator()->create();
        $customer = User::factory()->create(['token_version' => 2]);

        $this->actingAs($administrator)->put('/admin/customers/'.$customer->id, $this->customerPayload($customer, [
            'password' => 'new-password',
        ]))->assertRedirect('/admin/customers');

        $this->assertTrue(Hash::check('new-password', $customer->fresh()->password));
        $this->assertSame(3, $customer->fresh()->token_version);
    }

    private function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->make('auth')->forgetGuards();
    }

    private function addSession(User $user): string
    {
        $sessionId = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);

        return $sessionId;
    }

    private function customerPayload(User $customer, array $overrides = []): array
    {
        $customer = $customer->fresh();

        return array_merge([
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'company_name' => $customer->company_name,
            'role' => $customer->role,
            'status' => $customer->status,
            'password' => '',
        ], $overrides);
    }
}

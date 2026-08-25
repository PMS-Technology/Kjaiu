<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $this->actingAs($administrator)->get($path)->assertOk();
        }
    }
}

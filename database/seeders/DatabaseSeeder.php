<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        PaymentGateway::updateOrCreate(
            ['name' => 'BankTransfer'],
            ['title' => '银行转账', 'icon' => '', 'is_active' => true, 'sort_order' => 10],
        );

        $root = ProductGroup::firstOrCreate(
            ['parent_id' => null, 'name' => '云服务'],
            ['headline' => '稳定可靠的基础资源', 'sort_order' => 10, 'is_active' => true],
        );
        $group = ProductGroup::firstOrCreate(
            ['parent_id' => $root->id, 'name' => '弹性云主机'],
            ['headline' => '按需扩展', 'sort_order' => 10, 'is_active' => true],
        );
        Product::firstOrCreate(
            ['product_group_id' => $group->id, 'name' => '轻量云主机'],
            [
                'type' => 'cloud',
                'description' => "2 vCPU\n4 GB 内存\n80 GB SSD\n5 Mbps 带宽",
                'billing_cycle' => 'monthly',
                'price' => '39.00',
                'setup_fee' => '0.00',
                'stock_control' => false,
                'auto_setup' => false,
                'is_active' => true,
            ],
        );

        $email = env('KJAIU_ADMIN_EMAIL');
        $password = env('KJAIU_ADMIN_PASSWORD');
        if (filled($email) && filled($password)) {
            $name = trim((string) env('KJAIU_ADMIN_NAME')) ?: 'Administrator';
            $admin = Validator::make([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:191'],
                'password' => ['required', 'string'],
            ])->validate();

            User::updateOrCreate(
                ['email' => $admin['email']],
                [
                    'name' => $admin['name'],
                    'password' => $admin['password'],
                    'role' => 'admin',
                    'status' => 'Active',
                ],
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
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

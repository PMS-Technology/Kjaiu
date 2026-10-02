<?php

namespace Database\Seeders;

use App\Models\Configuration;
use App\Models\Currency;
use App\Models\TicketDepartment;
use App\Models\TicketStatus;
use App\Models\User;
use App\Support\PasswordHasher;
use Illuminate\Database\Seeder;

/**
 * Brings a fresh installation to a usable state.
 *
 * The full schema and baseline reference data are imported from the original
 * platform's dump, so this seeder only fills the gaps that are specific to
 * this installation: the first administrator, the default currency flag, and
 * the handful of settings the storefront needs before an administrator has
 * visited the settings screen.
 */
class KjaiuSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCurrency();
        $this->seedSettings();
        $this->seedTicketDefaults();
        $this->seedAdministrator();
    }

    /**
     * Exactly one currency must carry the default flag.
     */
    protected function seedCurrency(): void
    {
        $currency = Currency::query()->orderBy('id')->first();

        if ($currency === null) {
            $currency = Currency::create([
                'code' => 'CNY',
                'prefix' => '¥',
                'suffix' => '元',
                'format' => '2',
                'rate' => 1,
                'default' => 1,
            ]);
        }

        Currency::query()->whereKeyNot($currency->id)->update(['default' => 0]);
        $currency->default = 1;
        $currency->save();
    }

    /**
     * Settings the client area reads before anything is configured.
     */
    protected function seedSettings(): void
    {
        $defaults = [
            'company_name' => config('kjaiu.name', 'Kjaiu'),
            'web_name' => config('kjaiu.name', 'Kjaiu'),
            'system_url' => config('app.url'),
            'allow_login_email' => 1,
            'allow_login_phone' => 1,
            'allow_register_email' => 1,
            'allow_register_phone' => 1,
            'allow_email' => 1,
            'allow_phone' => 1,
            'second_verify' => 0,
            'second_verify_action' => 'on,off,reboot,hardOff,hardReboot,crackPass,rescue,vnc',
            'addfunds_enabled' => 1,
            'addfunds_minimum' => 1,
            'addfunds_maximum' => 50000,
            'affiliate_enabled' => 0,
            'allow_resource_api' => 1,
            'allow_resource_api_phone' => 0,
            'allow_resource_api_realname' => 0,
            'cron_host_suspend' => 1,
            'cron_host_suspend_time' => 3,
            'cron_host_terminate' => 1,
            'cron_host_terminate_time' => 30,
            'cron_host_unsuspend' => 1,
            'cron_invoice_create_default_days' => 7,
        ];

        foreach ($defaults as $setting => $value) {
            if (Configuration::value($setting) === null) {
                Configuration::put($setting, $value);
            }
        }

        Configuration::flushCache();
    }

    /**
     * A default department and the standard ticket statuses, when absent.
     */
    protected function seedTicketDefaults(): void
    {
        if (TicketDepartment::query()->count() === 0) {
            TicketDepartment::create([
                'name' => '技术支持',
                'description' => '技术支持部门',
                'email' => '',
                'hidden' => 0,
                'order' => 1,
            ]);
        }

        if (TicketStatus::query()->count() === 0) {
            $statuses = [
                ['title' => '待处理', 'color' => '#f59e0b', 'order' => 1, 'show_active' => 1, 'show_await' => 0],
                ['title' => '处理中', 'color' => '#3b82f6', 'order' => 2, 'show_active' => 1, 'show_await' => 0],
                ['title' => '等待回复', 'color' => '#8b5cf6', 'order' => 3, 'show_active' => 1, 'show_await' => 1],
                ['title' => '已解决', 'color' => '#10b981', 'order' => 4, 'show_active' => 0, 'show_await' => 0],
                ['title' => '已关闭', 'color' => '#6b7280', 'order' => 5, 'show_active' => 0, 'show_await' => 0, 'auto_close' => 1],
            ];

            foreach ($statuses as $status) {
                TicketStatus::create(array_merge([
                    'title' => '',
                    'color' => '#000000',
                    'order' => 1,
                    'show_active' => 0,
                    'show_await' => 0,
                    'auto_close' => 0,
                ], $status));
            }
        }
    }

    /**
     * The first administrator account.
     *
     * Credentials come from the environment so no default password is ever
     * committed; when unset a random password is generated and printed once.
     */
    protected function seedAdministrator(): void
    {
        if (User::query()->where('user_login', env('KJAIU_ADMIN_USERNAME', 'admin'))->exists()) {
            return;
        }

        $password = (string) env('KJAIU_ADMIN_PASSWORD', '');

        if ($password === '') {
            $password = bin2hex(random_bytes(9));
            $generated = true;
        }

        $admin = new User([
            'user_login' => env('KJAIU_ADMIN_USERNAME', 'admin'),
            'user_nickname' => env('KJAIU_ADMIN_USERNAME', 'admin'),
            'user_email' => env('KJAIU_ADMIN_EMAIL', 'admin@example.com'),
            'user_type' => 1,
            'user_status' => User::STATUS_ENABLED,
            'create_time' => time(),
        ]);

        $admin->setPassword($password);
        $admin->save();

        if (isset($generated)) {
            $this->command?->warn("生成的管理员密码（请立即保存）: {$password}");
        }
    }
}

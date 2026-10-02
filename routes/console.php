<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Kjaiu automation schedule (上下游 / 自动任务)
|--------------------------------------------------------------------------
|
| Mirrors the original panel's 定时任务 (AutomaticTasks) behaviour. The
| per-command guards (`shd_run_croning` window locks, the `cron_*` switches in
| `shd_configuration`) live inside each command, so these entries stay a plain
| description of cadence.
|
| Run `php artisan schedule:run` from the system crontab every minute.
|
*/

// Provisioning queue: drains shd_module_queue for pending module actions.
Schedule::command('kjaiu:module-queue --limit=20')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->description('处理模块任务队列');

// Restore services whose overdue invoice has been settled.
Schedule::command('kjaiu:unsuspend-paid')
    ->hourly()
    ->withoutOverlapping()
    ->description('已付款产品自动解除暂停');

// Pull upstream product data for suppliers with 自动更新 enabled.
Schedule::command('kjaiu:upstream-sync')
    ->hourly()
    ->withoutOverlapping()
    ->description('同步上游商品信息');

// Daily batch. `cron_day_start_time` decides the hour; the commands no-op
// until that hour is reached, so an hourly trigger is safe and keeps the
// schedule observable in `schedule:list`.
Schedule::command('kjaiu:invoice-generation')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->description('生成续费账单');

Schedule::command('kjaiu:suspend-overdue')
    ->dailyAt('00:20')
    ->withoutOverlapping()
    ->description('暂停逾期产品');

Schedule::command('kjaiu:terminate-overdue')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->description('删除长期逾期产品');

Schedule::command('kjaiu:client-care')
    ->dailyAt('00:40')
    ->withoutOverlapping()
    ->description('执行客户关怀规则');


<?php

use App\Http\Controllers\AdminSpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administrator SPA shell
|--------------------------------------------------------------------------
|
| The panel is a hash-routed Vue application, so one entry point serves it.
| This file is loaded after routes/admin.php, which keeps the JSON API safe
| from the catch-all.
|
*/

$adminPath = config('kjaiu.admin_path', 'admin');

Route::get($adminPath, AdminSpaController::class)->name('admin.spa');
Route::get($adminPath.'/', AdminSpaController::class);

<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\HostController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/common_list', [CatalogController::class, 'common']);
Route::get('/cart/index', [CatalogController::class, 'legacyCart']);
Route::get('/cart/all', [CatalogController::class, 'products']);

Route::prefix('v1')->group(function () {
    Route::get('/login', [AuthController::class, 'methods']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/products', [CatalogController::class, 'products']);
    Route::get('/productsconfig', [CatalogController::class, 'productConfig']);
    Route::get('/products/{product}', [CatalogController::class, 'product'])->whereNumber('product');

    Route::middleware('finance.auth')->group(function () {
        Route::get('/user', [UserController::class, 'show']);
        Route::put('/user', [UserController::class, 'update']);
        Route::put('/user/password', [UserController::class, 'password'])->middleware('throttle:5,1');
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/products/total', [CartController::class, 'total']);
        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart', [CartController::class, 'store']);
        Route::post('/cart/products', [CartController::class, 'store']);
        Route::delete('/cart', [CartController::class, 'clear']);
        Route::delete('/cart/products/{position}', [CartController::class, 'destroy'])->whereNumber('position');
        Route::delete('/cart/{position}', [CartController::class, 'destroy'])->whereNumber('position');
        Route::post('/cart/checkout', [CartController::class, 'checkout']);
        Route::post('/cart/settlement', [CartController::class, 'checkout']);

        Route::get('/hosts', [HostController::class, 'index']);
        Route::get('/hosts/{service}', [HostController::class, 'show'])->whereNumber('service');
        Route::get('/hosts/{service}/renew', [HostController::class, 'renewPage'])->whereNumber('service');
        Route::post('/hosts/{service}/renew', [HostController::class, 'renew'])->whereNumber('service');
        Route::put('/hosts/{service}/renew', [HostController::class, 'autoRenew'])->whereNumber('service');
        Route::put('/hosts/{service}/renew/auto', [HostController::class, 'autoRenew'])->whereNumber('service');
        Route::get('/hosts/{service}/module', [HostController::class, 'moduleStatus'])->whereNumber('service');
        Route::get('/hosts/{service}/module/status', [HostController::class, 'moduleStatus'])->whereNumber('service');

        Route::get('/invoices', [FinanceController::class, 'invoices']);
        Route::get('/invoices/{invoice}', [FinanceController::class, 'invoice'])->whereNumber('invoice');
        Route::get('/invoices/{invoice}/status', [FinanceController::class, 'invoiceStatus'])->whereNumber('invoice');
        Route::get('/funds', [FinanceController::class, 'funds']);
        Route::post('/funds', [FinanceController::class, 'createFunds']);
        Route::post('/pay', [FinanceController::class, 'pay']);
        Route::post('/credit', [FinanceController::class, 'payWithCredit']);
        Route::post('/pay/credit', [FinanceController::class, 'payWithCredit']);
        Route::get('/pay/status', [FinanceController::class, 'paymentStatus']);
        Route::get('/transactions', [FinanceController::class, 'transactions']);
        Route::get('/transactions/funds', [FinanceController::class, 'transactions']);
        Route::get('/accounts', [FinanceController::class, 'transactions']);
    });
});

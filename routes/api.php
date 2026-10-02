<?php

use App\Http\Controllers\Api\V1\AffiliateController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\HostController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API (v1) — downstream compatible
|--------------------------------------------------------------------------
|
| Mirrors the 智简魔方财务 (IDCSmart Finance) v1 API so existing downstream
| installations keep working against this platform. Responses always use the
| { status, msg, data } envelope; authenticated routes read the JWT from an
| `authorization: JWT <token>` header.
|
*/

Route::prefix('v1')->group(function () {
    // --- Public: no authentication -------------------------------------
    Route::get('/login', [AuthController::class, 'loginPage']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login_api', [AuthController::class, 'loginApi']);

    Route::get('/register', [AuthController::class, 'registerPage']);
    Route::post('/register', [AuthController::class, 'register']);

    Route::get('/pwreset', [AuthController::class, 'pwresetPage']);
    Route::post('/pwreset', [AuthController::class, 'pwreset']);

    Route::get('/captcha', [PublicController::class, 'captcha']);
    Route::post('/code', [PublicController::class, 'code']);
    Route::post('/second_verify', [PublicController::class, 'secondVerify']);
    Route::get('/gateway', [PublicController::class, 'gateway']);

    // --- Storefront -----------------------------------------------------
    Route::get('/products', [CatalogController::class, 'products']);
    Route::get('/productsconfig', [CatalogController::class, 'productsConfig']);
    Route::post('/products/total', [CatalogController::class, 'productsTotal']);
    Route::get('/products/cates', [CatalogController::class, 'cates']);
    Route::get('/products/cates/{id}', [CatalogController::class, 'productsByCate']);
    Route::get('/products/{id}', [CatalogController::class, 'productDetail']);

    Route::get('/goods/{fgid?}/{gid?}/{pid?}', [CatalogController::class, 'goods']);
    Route::get('/goodsconfig', [CatalogController::class, 'goodsConfig']);
    Route::post('/goods/total', [CatalogController::class, 'goodsTotal']);

    Route::get('/news', [SupportController::class, 'news']);
    Route::get('/news/{id}', [SupportController::class, 'newsContent']);
    Route::get('/knowledgebase', [SupportController::class, 'knowledgebase']);
    Route::get('/knowledgebase/{id}', [SupportController::class, 'knowledgebaseContent']);
    Route::get('/downloads', [SupportController::class, 'downloads']);
    Route::get('/downloads/{id}', [SupportController::class, 'download']);

    // --- Authenticated ---------------------------------------------------
    Route::middleware('api.auth')->group(function () {
        // Member profile
        Route::get('/user', [UserController::class, 'show']);
        Route::post('/user', [UserController::class, 'update']);
        Route::get('/security_info', [UserController::class, 'securityInfo']);
        Route::put('/password', [UserController::class, 'password']);
        Route::put('/phone_bind', [UserController::class, 'phoneBind']);
        Route::put('/email_bind', [UserController::class, 'emailBind']);
        Route::put('/login_notice', [UserController::class, 'loginNotice']);

        Route::get('/real_name_auth', [UserController::class, 'realNameAuth']);
        Route::post('/real_name_auth/person', [UserController::class, 'personRealNameAuth']);
        Route::post('/real_name_auth/company', [UserController::class, 'companyRealNameAuth']);
        Route::get('/real_name_auth/status', [UserController::class, 'realNameAuthStatus']);

        // Cart
        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart/products', [CartController::class, 'addProducts']);
        Route::delete('/cart/products/{position}', [CartController::class, 'remove']);
        Route::get('/cart/products/{position}', [CartController::class, 'editPage']);
        Route::put('/cart/products/{position}', [CartController::class, 'edit']);
        Route::put('/cart/products/{position}/qty', [CartController::class, 'modifyQty']);
        Route::post('/cart/promo', [CartController::class, 'addPromo']);
        Route::delete('/cart/promo', [CartController::class, 'removePromo']);
        Route::delete('/cart/clear', [CartController::class, 'clear']);
        Route::post('/cart/checkout', [CartController::class, 'checkout']);

        // Legacy "goods" aliases
        Route::post('/cart/goods', [CartController::class, 'addProducts']);
        Route::delete('/cart/goods/{position}', [CartController::class, 'remove']);
        Route::get('/cart/goods/{position}', [CartController::class, 'editPage']);
        Route::put('/cart/goods/{position}', [CartController::class, 'edit']);
        Route::put('/cart/goods/{position}/qty', [CartController::class, 'modifyQty']);

        // Hosts / services
        Route::get('/hosts', [HostController::class, 'index']);
        Route::get('/hosts/cates', [HostController::class, 'cates']);
        Route::post('/hosts/renew/batch', [HostController::class, 'renewBatch']);
        Route::get('/hosts/renew/batch', [HostController::class, 'renewBatchPage']);
        Route::get('/hosts/{id}', [HostController::class, 'show']);
        Route::get('/hosts/{id}/logs', [HostController::class, 'logs']);
        Route::get('/hosts/{id}/downloads', [HostController::class, 'downloads']);
        Route::get('/hosts/{id}/downloads/{download}', [HostController::class, 'downloadFile']);

        Route::get('/hosts/{id}/renew', [HostController::class, 'renewPage']);
        Route::post('/hosts/{id}/renew', [HostController::class, 'renew']);
        Route::put('/hosts/{id}/renew', [HostController::class, 'renewAuto']);

        Route::get('/hosts/{id}/cancel', [HostController::class, 'cancelPage']);
        Route::post('/hosts/{id}/cancel', [HostController::class, 'cancel']);
        Route::delete('/hosts/{id}/cancel', [HostController::class, 'cancelDelete']);

        Route::get('/hosts/{id}/actions/upgradeconfig', [HostController::class, 'upgradeConfigPage']);
        Route::post('/hosts/{id}/actions/upgradeconfig', [HostController::class, 'upgradeConfig']);
        Route::post('/hosts/{id}/actions/upgradeconfig/checkout', [HostController::class, 'upgradeConfigCheckout']);
        Route::put('/hosts/{id}/actions/upgradeconfig/promo', [HostController::class, 'upgradeConfigPromo']);
        Route::delete('/hosts/{id}/actions/upgradeconfig/promo', [HostController::class, 'upgradeConfigPromoRemove']);

        Route::get('/hosts/{id}/actions/upgrade', [HostController::class, 'upgradePage']);
        Route::post('/hosts/{id}/actions/upgrade', [HostController::class, 'upgrade']);
        Route::put('/hosts/{id}/actions/upgrade/promo', [HostController::class, 'upgradePromo']);
        Route::delete('/hosts/{id}/actions/upgrade/promo', [HostController::class, 'upgradePromoRemove']);
        Route::post('/hosts/{id}/actions/upgrade/checkout', [HostController::class, 'upgradeCheckout']);

        // Server module actions
        Route::get('/hosts/{id}/module', [HostController::class, 'module']);
        Route::put('/hosts/{id}/module/repassword', [HostController::class, 'repassword']);
        Route::get('/hosts/{id}/module/reinstall', [HostController::class, 'getReinstall']);
        Route::put('/hosts/{id}/module/reinstall', [HostController::class, 'reinstall']);
        Route::post('/hosts/{id}/module/reinstall_buy', [HostController::class, 'reinstallBuy']);
        Route::put('/hosts/{id}/module/on', [HostController::class, 'on']);
        Route::put('/hosts/{id}/module/off', [HostController::class, 'off']);
        Route::put('/hosts/{id}/module/reboot', [HostController::class, 'reboot']);
        Route::put('/hosts/{id}/module/hard_off', [HostController::class, 'hardOff']);
        Route::put('/hosts/{id}/module/hard_reboot', [HostController::class, 'hardReboot']);
        Route::put('/hosts/{id}/module/bmc', [HostController::class, 'bmc']);
        Route::put('/hosts/{id}/module/kvm', [HostController::class, 'kvm']);
        Route::put('/hosts/{id}/module/ikvm', [HostController::class, 'ikvm']);
        Route::put('/hosts/{id}/module/vnc', [HostController::class, 'vnc']);
        Route::put('/hosts/{id}/module/rescue', [HostController::class, 'rescue']);
        Route::get('/hosts/{id}/module/charts', [HostController::class, 'charts']);
        Route::get('/hosts/{id}/module/custom', [HostController::class, 'custom']);
        Route::get('/hosts/{id}/module/status', [HostController::class, 'status']);

        // Finance
        Route::get('/invoices/{id}', [FinanceController::class, 'show']);
        Route::post('/invoices/combines', [FinanceController::class, 'combine']);
        Route::post('/invoices/{id}/fund', [FinanceController::class, 'fund']);
        Route::delete('/invoices/{id}/fund', [FinanceController::class, 'fundDelete']);
        Route::post('/invoices/{id}/credit', [FinanceController::class, 'credit']);
        Route::get('/invoices/{id}/status', [FinanceController::class, 'status']);

        Route::get('/funds', [FinanceController::class, 'fundsInfo']);
        Route::post('/funds', [FinanceController::class, 'funds']);
        Route::get('/transactions/funds', [FinanceController::class, 'accountsRecord']);

        Route::post('/pay', [FinanceController::class, 'pay']);

        // Tickets
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::post('/tickets', [TicketController::class, 'store']);
        Route::get('/tickets/page', [TicketController::class, 'page']);
        Route::get('/tickets/{id}', [TicketController::class, 'show']);
        Route::post('/tickets/{id}/reply', [TicketController::class, 'reply']);

        // Affiliate
        Route::get('/affiliates', [AffiliateController::class, 'show']);
        Route::put('/affiliates', [AffiliateController::class, 'activate']);
        Route::post('/affiliates/withdraw', [AffiliateController::class, 'withdraw']);
        Route::get('/affiliates/withdraw_record', [AffiliateController::class, 'withdrawRecord']);
        Route::get('/affiliates/record', [AffiliateController::class, 'record']);
        Route::get('/affiliates/user', [AffiliateController::class, 'users']);

        // Logs and messages
        Route::get('/log/system', [SupportController::class, 'systemLog']);
        Route::get('/log/login', [SupportController::class, 'loginLog']);
        Route::get('/log/api', [SupportController::class, 'apiLog']);

        Route::get('/message', [SupportController::class, 'message']);
        Route::put('/message/{id}', [SupportController::class, 'readMessage']);
        Route::delete('/message/{id}', [SupportController::class, 'deleteMessage']);
    });
});

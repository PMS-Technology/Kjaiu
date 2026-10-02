<?php

use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BillingController;
use App\Http\Controllers\Web\CartController;
use App\Http\Controllers\Web\ContentController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\ProvisionController;
use App\Http\Controllers\Web\ServiceController;
use App\Http\Controllers\Web\TicketController;
use App\Http\Controllers\Web\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer-facing web application
|--------------------------------------------------------------------------
|
| Mirrors the 智简魔方财务 client area and storefront. The original multiplexes
| most pages on an `?action=` query parameter, so the controllers dispatch on
| it; those URLs are part of the public contract (templates and integrations
| link to them literally) and are reproduced here unchanged.
|
| AJAX endpoints return the platform envelope { status, msg, data } while
| several endpoints deliberately return rendered HTML fragments, because the
| original injects them with jQuery's `.html(data)`.
|
*/

// ---------------------------------------------------------------------
// Public: storefront and content
// ---------------------------------------------------------------------

Route::get('/', [HomeController::class, 'index'])->name('client.home');
Route::get('/index', [HomeController::class, 'dashboardEntry']);
Route::get('/config_general/header', [HomeController::class, 'header']);
Route::get('/common_list', [HomeController::class, 'commonList']);
Route::get('/navindex', [HomeController::class, 'commonList']);
Route::get('/sale_list', [HomeController::class, 'saleList']);

// News / announcements
Route::get('/news', [ContentController::class, 'news'])->name('client.news');
Route::get('/newsview', [ContentController::class, 'newsView'])->name('client.newsview');
Route::get('/newslist', [ContentController::class, 'news'])->name('client.newslist');
Route::get('/notice', [ContentController::class, 'news']);
Route::get('/news/list', [ContentController::class, 'newsList']);
Route::get('/news/notice', [ContentController::class, 'noticeList']);
Route::get('/news/content', [ContentController::class, 'newsContent']);
Route::get('/news/catelist', [ContentController::class, 'newsCates']);
Route::get('/notice/list', [ContentController::class, 'noticeList']);
Route::get('/notice/content', [ContentController::class, 'newsContent']);

// Knowledge base
Route::get('/knowledgebase', [ContentController::class, 'knowledgebase'])->name('client.knowledgebase');
Route::get('/knowledgebaseview', [ContentController::class, 'knowledgebaseView'])->name('client.knowledgebaseview');
Route::get('/knowledgebaselist', [ContentController::class, 'knowledgebase']);
Route::get('/knowledge_base/index', [ContentController::class, 'knowledgebase']);
Route::post('/knowledge_base/search_article', [ContentController::class, 'searchArticle']);
Route::post('/knowledge_base/tags_list', [ContentController::class, 'tagsList']);
Route::get('/knowledge_base/view_article/{id}', [ContentController::class, 'viewArticle'])
    ->whereNumber('id');

// Downloads
Route::get('/downloads', [ContentController::class, 'downloads'])->name('client.downloads');
Route::get('/download/cates', [ContentController::class, 'downloadCates']);
Route::post('/download/search', [ContentController::class, 'downloadSearch']);
Route::get('/download/product_file', [ContentController::class, 'productFile']);

// ---------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------

Route::get('/login', [AuthController::class, 'login'])->name('client.login');
Route::post('/login', [AuthController::class, 'attemptLogin']);
Route::get('/loginAccessToken', [AuthController::class, 'login']);
Route::post('/loginAccessToken', [AuthController::class, 'attemptLogin']);
Route::get('/login/second_verify_page', [AuthController::class, 'secondVerifyPage']);
Route::post('/login/second_verify_send', [AuthController::class, 'secondVerifySend']);
Route::post('/login_send', [AuthController::class, 'loginSend']);

Route::get('/register', [AuthController::class, 'register'])->name('client.register');
Route::post('/register', [AuthController::class, 'attemptRegister']);
Route::post('/register_email_send', [AuthController::class, 'registerSend']);
Route::post('/register_phone_send', [AuthController::class, 'registerSend']);
Route::post('/register_email', [AuthController::class, 'attemptRegister']);
Route::post('/register_phone', [AuthController::class, 'attemptRegister']);

Route::get('/pwreset', [AuthController::class, 'pwreset'])->name('client.pwreset');
Route::post('/pwreset', [AuthController::class, 'attemptPwreset']);
Route::post('/reset_email_send', [AuthController::class, 'resetSend']);
Route::post('/reset_phone_send', [AuthController::class, 'resetSend']);
Route::post('/reset_email', [AuthController::class, 'attemptPwreset']);
Route::post('/reset_phone', [AuthController::class, 'attemptPwreset']);

Route::get('/bind', [AuthController::class, 'bind']);
Route::post('/bind', [AuthController::class, 'bindSubmit']);
Route::post('/oauth/bind_email_send', [AuthController::class, 'bindSend']);
Route::post('/oauth/bind_phone_send', [AuthController::class, 'bindSend']);

Route::get('/logout', [AuthController::class, 'logout'])->name('client.logout');
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/logOut', [AuthController::class, 'logout']);

// Graphic captcha, fetched as bytes and rendered into an <img>.
Route::get('/verify', [AuthController::class, 'verify']);

// ---------------------------------------------------------------------
// Audience landing (logged out) and the storefront cart
// ---------------------------------------------------------------------

Route::get('/cart', [CartController::class, 'index'])->name('client.cart');
Route::post('/cart', [CartController::class, 'index']);

// Cart helpers consumed by the cart theme's JavaScript.
Route::get('/cart/all', [CartController::class, 'allProducts']);
Route::match(['get', 'post'], '/cart/summary', [CartController::class, 'summary']);
Route::match(['get', 'post'], '/cart/credit', [CartController::class, 'getCredit']);
Route::get('/cart/stock_control', [CartController::class, 'stockControl']);
Route::get('/cart/get_product_config', [CartController::class, 'getProductConfig']);
Route::get('/cart/check_promo_code', [CartController::class, 'checkPromo']);
Route::get('/cart/check_page', [CartController::class, 'checkoutPage']);
Route::get('/cart/index', [CartController::class, 'index']);
Route::get('/cart/prolist', [CartController::class, 'allProducts']);
Route::get('/cartgateway', [CartController::class, 'gatewayList']);
Route::get('/getLinkAgeList', [CartController::class, 'linkAgeList']);
Route::get('/link_list', [CartController::class, 'linkAgeList']);

Route::post('/cart/add_to_shop', [CartController::class, 'addToShop']);
Route::post('/cart/edit_to_shop', [CartController::class, 'editToShop']);
Route::post('/cart/createproducts', [CartController::class, 'addToCart']);
Route::post('/cart/remove_product', [CartController::class, 'removeItem']);
Route::match(['get', 'post'], '/cart/clear', [CartController::class, 'clearCart']);
Route::match(['get', 'post'], '/cart/add_promo', [CartController::class, 'addPromo']);
Route::match(['get', 'post'], '/cart/remove_promo', [CartController::class, 'removePromo']);
Route::post('/cart/settle', [CartController::class, 'checkout']);
Route::post('/cart/get_total', [CartController::class, 'summary']);
Route::post('/cart/modify_product_qty', [CartController::class, 'changeQty']);
Route::post('/cart/ordersummary', [CartController::class, 'orderSummary']);

// Vanity order URLs the original serves from the same controller.
Route::get('/store/{alias}', [CartController::class, 'index'])->where('alias', '[A-Za-z0-9_-]+');
Route::get('/buy/{alias}', [CartController::class, 'index'])->where('alias', '[A-Za-z0-9_-]+');

// ---------------------------------------------------------------------
// Authenticated client area
// ---------------------------------------------------------------------

Route::middleware('client.auth')->group(function () {
    // Dashboard
    Route::get('/clientarea', [DashboardController::class, 'index'])->name('client.clientarea');
    Route::match(['get', 'post'], '/user_info', [DashboardController::class, 'userInfo']);
    Route::match(['put', 'post'], '/user_info/update', [AccountController::class, 'update']);
    Route::put('/user_info', [AccountController::class, 'update']);

    // Services
    Route::get('/service', [ServiceController::class, 'index'])->name('client.service');
    Route::get('/servicedetail', [ServiceController::class, 'detail'])->name('client.servicedetail');
    Route::post('/servicedetail', [ServiceController::class, 'detail']);
    Route::post('/servicedetail/renew', [ServiceController::class, 'renewSubmit']);
    Route::get('/mulitrenew', [ServiceController::class, 'multiRenew']);
    Route::post('/mulitrenew', [ServiceController::class, 'multiRenew']);

    // Per-service module actions (the original's catch-all `host/<action>`).
    Route::post('/host/remark', [ServiceController::class, 'remark']);
    Route::post('/host/autorenew', [ServiceController::class, 'autoRenew']);
    Route::delete('/host/cancel', [ServiceController::class, 'cancel']);
    // The detail page posts the cancellation form, so a POST twin exists.
    Route::post('/host/cancel', [ServiceController::class, 'cancel']);
    Route::post('/host/batchrenewpage', [ServiceController::class, 'batchRenewPage']);
    Route::get('/host/hostrecharge', [ServiceController::class, 'hostRecharge']);
    Route::post('/host/hostrecharge', [ServiceController::class, 'hostRecharge']);
    Route::get('/host/trafficusage', [ServiceController::class, 'trafficUsage']);
    Route::post('/host/trafficusage', [ServiceController::class, 'trafficUsage']);

    // Upgrade / downgrade settlement.
    Route::post('/upgrade/checkout_upgrade_product', [ServiceController::class, 'renewSubmit']);
    Route::post('/upgrade/checkout_config_upgrade', [ServiceController::class, 'renewSubmit']);
    Route::post('/upgrade/add_promo_code', [ServiceController::class, 'renewSubmit']);
    Route::post('/upgrade/add_promo_code_product', [ServiceController::class, 'renewSubmit']);
    Route::post('/upgrade/remove_promo_code', [ServiceController::class, 'renewSubmit']);
    Route::post('/upgrade/remove_promo_code_product', [ServiceController::class, 'renewSubmit']);

    // Provisioning
    Route::post('/provision/default', [ProvisionController::class, 'default']);
    Route::post('/provision/custom/{id}', [ProvisionController::class, 'custom'])->whereNumber('id');
    Route::get('/provision/custom/content', [ProvisionController::class, 'customContent']);
    Route::post('/provision/custom/content', [ProvisionController::class, 'customContent']);
    Route::get('/provision/chart/{id}', [ProvisionController::class, 'chart'])->whereNumber('id');
    Route::post('/provision/button', [ProvisionController::class, 'button']);
    Route::post('/provision/sslCertFunc', [ProvisionController::class, 'sslCertFunc']);

    // Invoices
    Route::get('/billing', [BillingController::class, 'index'])->name('client.billing');
    Route::get('/viewbilling', [BillingController::class, 'view'])->name('client.viewbilling');
    Route::match(['get', 'post'], '/combinebilling', [BillingController::class, 'combine']);
    Route::post('/combine_invoices', [BillingController::class, 'combineInvoices']);
    Route::match(['get', 'post'], '/get_combine_invoices', [BillingController::class, 'getCombineInvoices']);
    Route::get('/get_invoices', [BillingController::class, 'listJson']);
    Route::get('/get_invoices_detail', [BillingController::class, 'detailJson']);
    Route::get('/invoices/{id}', [BillingController::class, 'read'])->whereNumber('id');
    Route::delete('/invoices/{id}', [BillingController::class, 'delete'])->whereNumber('id');

    // Payment
    Route::match(['get', 'post'], '/pay', [BillingController::class, 'pay'])->name('client.pay');
    Route::post('/check_order', [BillingController::class, 'checkOrder']);
    Route::post('/change_paymt', [BillingController::class, 'changePaymt']);
    Route::get('/order_list', [BillingController::class, 'orderList']);
    Route::get('/recharge_page', [BillingController::class, 'rechargePage']);
    Route::post('/recharge', [BillingController::class, 'recharge']);
    Route::get('/use_credit_page', [BillingController::class, 'useCreditPage']);
    Route::post('/invoice_page', [BillingController::class, 'invoicePage']);
    Route::post('/apply_credit', [BillingController::class, 'applyCredit']);
    Route::post('/apply_credit_limit', [BillingController::class, 'applyCreditLimit']);
    Route::post('/credit_limit/prepayment', [BillingController::class, 'prepayment']);
    Route::get('/get_gateways/{module?}', [BillingController::class, 'orderList']);
    Route::post('/start_pay', [BillingController::class, 'pay']);

    // Add funds
    Route::get('/addfunds', [BillingController::class, 'addfunds'])->name('client.addfunds');

    // Invoice (tax voucher) list — same page name, billing data.
    Route::get('/invoicelist', [BillingController::class, 'index']);

    // Transactions. Each record type has its own bare URL (the original's
    // route names) and returns the same envelope.
    Route::get('/transaction', [TransactionController::class, 'index'])->name('client.transaction');
    Route::get('/transaction/{action}', [TransactionController::class, 'json'])
        ->whereIn('action', [
            'accounts_record', 'consume_record', 'recharge_record', 'refund_record',
            'withdraw_record', 'credit_record', 'credit_limit',
        ]);
    foreach (['accounts_record', 'consume_record', 'recharge_record', 'refund_record', 'withdraw_record', 'credit_record', 'finance_record'] as $record) {
        Route::get('/' . $record, [TransactionController::class, 'jsonAction'])->defaults('recordAction', $record);
    }
    // `credit_limit/list` is the credit view of the same data.
    Route::get('/credit_limit/list', [TransactionController::class, 'creditLimitList']);

    // Tickets
    Route::get('/supporttickets', [TicketController::class, 'index'])->name('client.supporttickets');
    Route::get('/submitticket', [TicketController::class, 'submit'])->name('client.submitticket');
    Route::post('/submitticket', [TicketController::class, 'submit']);
    Route::get('/viewticket', [TicketController::class, 'view'])->name('client.viewticket');
    Route::post('/viewticket', [TicketController::class, 'reply']);
    Route::get('/ticket/list', [TicketController::class, 'listJson']);
    Route::get('/ticket/detail', [TicketController::class, 'detail']);
    Route::get('/ticket/department', [TicketController::class, 'departmentList']);
    Route::get('/ticket/ticket_page', [TicketController::class, 'ticketPage']);
    Route::get('/ticket/get_custom', [TicketController::class, 'getCustom']);
    Route::post('/ticket/create', [TicketController::class, 'createJson']);
    Route::post('/ticket/reply', [TicketController::class, 'reply']);
    Route::post('/ticket/close', [TicketController::class, 'close']);
    Route::post('/ticket/evaluate', [TicketController::class, 'evaluate']);
    Route::match(['get', 'post'], '/ticket/download', [TicketController::class, 'download']);

    // Account
    Route::match(['get', 'post'], '/details', [AccountController::class, 'details'])->name('client.details');
    Route::get('/security', [AccountController::class, 'security'])->name('client.security');
    Route::get('/apimanage', [AccountController::class, 'apiManage'])->name('client.apimanage');
    Route::get('/systemlog', [AccountController::class, 'systemLog'])->name('client.systemlog');
    Route::get('/loginlog', [AccountController::class, 'loginLog'])->name('client.loginlog');
    Route::get('/apilog', [AccountController::class, 'apiLog'])->name('client.apilog');
    Route::get('/user_logs', [AccountController::class, 'userLogs']);
    Route::match(['get', 'post'], '/user_logdcims', [AccountController::class, 'userLogDcims']);
    Route::get('/get_areas', [AccountController::class, 'getAreas']);
    Route::get('/areas/{pid?}', [AccountController::class, 'getAreas'])->whereNumber('pid');
    Route::get('/country', [AccountController::class, 'country']);

    // Security centre actions
    Route::post('/modify_password', [AccountController::class, 'modifyPassword']);
    Route::post('/bind_phone_handle', [AccountController::class, 'bindPhone']);
    Route::post('/bind_phone_change', [AccountController::class, 'bindPhoneChange']);
    Route::post('/bind_email_handle', [AccountController::class, 'bindEmail']);
    Route::post('/change_email_handle', [AccountController::class, 'changeEmail']);
    Route::post('/login_sms_reminder', [AccountController::class, 'loginSmsReminder']);
    Route::post('/login_email_reminder', [AccountController::class, 'loginEmailReminder']);
    Route::post('/toggle_second_verify', [AccountController::class, 'toggleSecondVerify']);
    Route::get('/second_verify_page', [AccountController::class, 'secondVerifyPage']);
    Route::post('/second_verify_send', [AccountController::class, 'secondVerifySend']);
    Route::post('/get_check_code', [AccountController::class, 'getCheckCode']);

    // API management
    Route::post('/zjmf_finance_api/open', [AccountController::class, 'apiOpen']);
    Route::post('/zjmf_finance_api/reset', [AccountController::class, 'apiReset']);
    Route::get('/zjmf_finance_api/summary', [AccountController::class, 'userInfo']);
    Route::match(['get', 'post'], '/get_api_pwd', [AccountController::class, 'getApiPwd']);
    Route::post('/modify_api_pwd', [AccountController::class, 'modifyApiPwd']);
    Route::match(['get', 'post'], '/auto_api_pwd', [AccountController::class, 'autoApiPwd']);

    // Messages — the double "g" in `messgage` is the original's spelling.
    Route::get('/message', [AccountController::class, 'message'])->name('client.message');
    Route::get('/sys_messgage', [AccountController::class, 'messageList']);
    Route::get('/sys_messgage_unread', [AccountController::class, 'unreadList']);
    Route::match(['get', 'post'], '/read_messgage', [AccountController::class, 'readMessage']);
    Route::match(['get', 'post', 'delete'], '/delete_messgage', [AccountController::class, 'deleteMessage']);

    // Affiliates
    Route::get('/affiliates', [ContentController::class, 'affiliates'])->name('client.affiliates');
    Route::get('/affpage', [ContentController::class, 'affiliates']);
    Route::get('/affindex', [ContentController::class, 'affiliates']);
    Route::match(['get', 'post'], '/activation', [ContentController::class, 'activation']);
    Route::post('/withdraw', [ContentController::class, 'withdraw']);
    Route::get('/withdrawrecord', [ContentController::class, 'affiliates']);
    Route::get('/affbuyrecord', [ContentController::class, 'affiliates']);
    Route::get('/useraffi_list', [ContentController::class, 'affiliates']);

    // Uploads used by the ticket and product forms.
    Route::post('/uploads', [TicketController::class, 'upload']);
    Route::post('/upload_image', [TicketController::class, 'upload']);
    Route::post('/home/upload_file', [TicketController::class, 'upload']);
});

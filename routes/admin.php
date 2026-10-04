<?php

use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ClientCareController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ConfigController;
use App\Http\Controllers\Admin\DcimController;
use App\Http\Controllers\Admin\ConfigOptionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\UpstreamController;
use App\Http\Controllers\Admin\WithdrawController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administrator API
|--------------------------------------------------------------------------
|
| JSON endpoints consumed by the admin SPA. Paths mirror the original
| platform's `/admin/*` surface, so integrations keep working.
|
| Every response uses the { status, msg, data } envelope (plus the SPA's
| `identify` / `action` / `per_page_limit` extras), and everything except the
| login endpoints sits behind the `admin.auth` guard.
|
| Registered by App\Providers\AdminServiceProvider inside the `web` group.
|
*/

Route::prefix(config('kjaiu.admin_path', 'admin'))->group(function () {

    // --- Public: administrator authentication ---------------------------
    Route::get('login_page', [AuthController::class, 'loginPage']);
    // `/login` is the hash route the panel switches to; direct browser hits are
    // forwarded there so they never render the JSON envelope.
    Route::get('login', [AuthController::class, 'loginEntry']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('ad_login', [AuthController::class, 'adLogin']);
    Route::get('ad_login', [AuthController::class, 'adLoginPage']);
    Route::get('get_verify_code', [AuthController::class, 'getVerifyCode']);
    Route::post('second_verify_send', [AuthController::class, 'secondVerifySend']);
    Route::post('second_verify_check', [AuthController::class, 'secondVerifyCheck']);
    // Logout is reachable without a session so an expired panel can still
    // clear its cookie; the handler is a no-op when nobody is signed in.
    Route::get('logout', [AuthController::class, 'logout']);
    Route::post('logout', [AuthController::class, 'logout']);

    // ------------------------------------------------------------------
    // Authenticated administrator surface
    // ------------------------------------------------------------------
    Route::middleware('admin.auth')->group(function () {

        // --- Bootstrap / dictionaries -----------------------------------
        Route::get('common', [AuthController::class, 'common']);
        Route::get('clear_cache', [AuthController::class, 'clearCache']);
        Route::get('common/get_getways', [AuthController::class, 'getGateways']);
        Route::get('common/get_client_groups', [AuthController::class, 'getClientGroups']);
        Route::get('common/get_sms_country', [AuthController::class, 'getSmsCountry']);
        Route::get('common/get_email_tem', [AuthController::class, 'getEmailTemplates']);
        Route::get('common/get_product_list', [AuthController::class, 'getProductList']);
        Route::get('common/host_list', [AuthController::class, 'getHostList']);
        Route::get('common/get_promo_code', [AuthController::class, 'getPromoCodes']);
        Route::get('common/product_config_options', [AuthController::class, 'getProductConfigOptions']);
        Route::get('common/sale_list', [AuthController::class, 'getSaleList']);
        Route::get('common/info_notice', [ConfigController::class, 'infoNotice']);
        Route::get('user/info', [AuthController::class, 'me']);
        Route::get('authorInfo', [AuthController::class, 'me']);
        Route::post('user/edit_self_info', [RbacController::class, 'editSelfInfo']);
        Route::get('config_general/lang/list', [ConfigController::class, 'languageList']);

        // --- Dashboard / statistics -------------------------------------
        Route::get('index', [DashboardController::class, 'index']);
        Route::get('ad_index', [DashboardController::class, 'index']);
        Route::get('common/get_index', [DashboardController::class, 'index']);
        Route::get('sale/sale_statistics', [DashboardController::class, 'saleStatistics']);
        Route::get('sale/statistics', [DashboardController::class, 'saleStatistics']);
        Route::get('sale/sale_users', [DashboardController::class, 'saleUsers']);
        Route::get('annualstatistics', [DashboardController::class, 'annualStatistics']);
        Route::get('year_reports', [DashboardController::class, 'annualStatistics']);
        Route::get('year_reports_chart', [DashboardController::class, 'annualChart']);
        Route::get('newcustomer', [DashboardController::class, 'newCustomer']);
        Route::get('productrevenue', [DashboardController::class, 'productRevenue']);
        Route::get('revenueranking', [DashboardController::class, 'revenueRanking']);
        Route::get('salesstatistics', [DashboardController::class, 'saleStatistics']);
        Route::get('product/income', [DashboardController::class, 'productIncome']);
        Route::get('order/saleorder', [DashboardController::class, 'saleOrders']);
        Route::get('report/base_info', [DashboardController::class, 'reportBaseInfo']);

        // ------------------------------------------------------------------
        // 1. 客户 — clients
        // ------------------------------------------------------------------
        Route::match(['get', 'post'], 'client_list', [ClientController::class, 'list']);
        Route::post('searchfornamelist', [ClientController::class, 'searchForNameList']);
        Route::get('namelist', [ClientController::class, 'searchForNameList']);
        Route::get('searchlist', [ClientController::class, 'searchList']);
        Route::get('searchlist1', [ClientController::class, 'searchList']);
        Route::get('get_user', [ClientController::class, 'getUser']);
        Route::get('getClient', [ClientController::class, 'getClientList']);
        Route::get('summary', [ClientController::class, 'summary']);
        Route::get('profile/<id>', [ClientController::class, 'profile']);
        Route::get('profile/getclients/<id>', [ClientController::class, 'profileForm']);
        Route::post('profile_post', [ClientController::class, 'profilePost']);
        Route::get('create_client', [ClientController::class, 'createPage']);
        Route::get('new_client', [ClientController::class, 'createPage']);
        Route::post('create_client_post', [ClientController::class, 'createClient']);
        Route::get('delete_client/<uid>', [ClientController::class, 'deleteClient']);
        Route::get('close_client/<uid>', [ClientController::class, 'closeClient']);
        Route::match(['get', 'post'], 'close_client/<uid>', [ClientController::class, 'closeClient']);
        Route::get('login_by_user/<uid>', [ClientController::class, 'loginByUser']);
        Route::get('forward_client', [ClientController::class, 'forwardClient']);
        Route::get('user_invoice', [ClientController::class, 'userInvoices']);
        Route::get('user_productinvoice', [ClientController::class, 'userProductInvoices']);
        Route::get('user_productaccounts', [ClientController::class, 'userProductAccounts']);
        Route::post('add_user_invoice', [ClientController::class, 'addUserInvoice']);
        Route::post('add_recharge_invoice/<uid>', [ClientController::class, 'addRechargeInvoice']);
        Route::post('post_client_notes', [ClientController::class, 'postClientNotes']);
        Route::get('get_client_notes', [ClientController::class, 'getClientNotes']);
        Route::post('add_record_log', [ClientController::class, 'addRecordLog']);
        Route::post('add_remark_log', [ClientController::class, 'addRemarkLog']);
        Route::match(['get', 'post'], 'track_record', [ClientController::class, 'trackRecord']);
        Route::get('getTrackRecord', [ClientController::class, 'getTrackRecord']);
        Route::get('clientTrackStatus', [ClientController::class, 'clientTrackStatus']);
        Route::post('client/{uid}/track_status', [ClientController::class, 'clientTrackStatusPost']);
        Route::post('user_remark', [ClientController::class, 'userRemark']);
        Route::get('user_remark', [ClientController::class, 'getUserRemark']);
        Route::get('hostbyuid', [ClientController::class, 'hostByUid']);
        Route::get('client_ticket', [ClientController::class, 'clientTickets']);
        Route::get('client/ticket', [ClientController::class, 'clientTickets']);
        Route::get('get_combine_invoices', [ClientController::class, 'getCombineInvoices']);
        Route::post('combine_invoices', [ClientController::class, 'combineInvoices']);
        Route::get('clients_services', [ClientController::class, 'clientsServices']);

        // 客户分组 / 等级 / 自定义字段
        Route::get('client_group', [ClientController::class, 'groupList']);
        Route::get('config_general/client_group', [ClientController::class, 'groupList']);
        Route::post('client_group/create', [ClientController::class, 'groupSave']);
        Route::post('client_group', [ClientController::class, 'groupSave']);
        Route::put('client_group', [ClientController::class, 'groupSave']);
        Route::delete('client_group/<id>', [ClientController::class, 'groupDelete']);
        Route::get('client_group/<id>', [ClientController::class, 'groupDetail']);

        Route::get('clients_level_rule', [ClientController::class, 'levelRuleList']);
        Route::post('clients_level_rule', [ClientController::class, 'levelRuleSave']);
        Route::put('clients_level_rule/<id>', [ClientController::class, 'levelRuleSave']);
        Route::delete('clients_level_rule/<id>', [ClientController::class, 'levelRuleDelete']);
        Route::get('clients_level_rule/<id>', [ClientController::class, 'levelRuleDetail']);

        Route::get('custom_fields', [ClientController::class, 'customFieldList']);
        Route::post('custom_fields/create', [ClientController::class, 'customFieldCreate']);
        Route::post('custom_fields/update', [ClientController::class, 'customFieldUpdate']);
        Route::delete('custom_fields/<id>', [ClientController::class, 'customFieldDelete']);
        Route::post('custom_fields/sort', [ClientController::class, 'customFieldSort']);

        // 实名认证审核
        Route::get('cerify_list', [ClientController::class, 'certifyList']);
        Route::get('certifi_types', [ClientController::class, 'certifyTypes']);
        Route::get('certifi_person_detail/<id>', [ClientController::class, 'certifyPersonDetail']);
        Route::get('certifi_person/download', [ClientController::class, 'certifyDownload']);
        Route::get('certifi/download', [ClientController::class, 'certifyDownload']);
        Route::post('certifi_status', [ClientController::class, 'certifyStatus']);
        Route::get('cerify_log_list', [ClientController::class, 'certifyLogList']);
        Route::get('cerify_history_log', [ClientController::class, 'certifyHistoryLog']);
        Route::get('certifi/type', [ClientController::class, 'certifyTypes']);
        Route::get('certifi/three_type', [ClientController::class, 'certifyTypes']);
        Route::get('cerify/list', [ClientController::class, 'certifyList']);
        Route::get('cerify/log/list', [ClientController::class, 'certifyLogList']);

        // 客户资源池 / 跟进
        Route::get('client_list/resource', [ClientController::class, 'resourceList']);
        Route::get('clients_level_rule/page', [ClientController::class, 'levelRuleList']);
        Route::match(['get', 'post'], 'clients_track_record', [ClientController::class, 'trackRecord']);

        // ------------------------------------------------------------------
        // 2. 商品 — products
        // ------------------------------------------------------------------
        Route::get('product_list_page', [ProductController::class, 'listPage']);
        Route::get('productlist', [ProductController::class, 'listPage']);
        Route::get('add_product_page', [ProductController::class, 'addPage']);
        Route::post('create_product', [ProductController::class, 'create']);
        Route::get('edit_product_page/<id>', [ProductController::class, 'editPage']);
        Route::post('edit_product', [ProductController::class, 'update']);
        Route::get('del_product', [ProductController::class, 'delete']);
        Route::post('product_duplicate', [ProductController::class, 'duplicate']);
        Route::get('product_duplicate_page', [ProductController::class, 'duplicatePage']);
        Route::post('update_productsort', [ProductController::class, 'updateSort']);
        Route::post('update_groupsort', [ProductController::class, 'updateGroupSort']);
        Route::post('update_firstgroupsort', [ProductController::class, 'updateFirstGroupSort']);
        Route::post('edit_stock', [ProductController::class, 'editStock']);
        Route::post('check_product_as', [ProductController::class, 'checkAlias']);
        Route::get('getApiList', [ProductController::class, 'apiList']);
        Route::get('get_upstream_products', [ProductController::class, 'upstreamProducts']);
        Route::get('getUpstreamProducts', [ProductController::class, 'upstreamProducts']);
        Route::get('product/get_upstream_price', [ProductController::class, 'upstreamPrice']);
        Route::post('product/sync_product_info', [ProductController::class, 'syncProductInfo']);
        Route::get('product/select_type', [ProductController::class, 'selectType']);
        Route::get('product/productgroup', [ProductController::class, 'productGroupTree']);
        Route::get('product/add_productgrouppage', [ProductController::class, 'productGroupTree']);
        Route::get('provision/list', [ProductController::class, 'provisionList']);
        Route::get('provision/metadata', [ProductController::class, 'provisionMetadata']);
        Route::get('provision/<id>', [ProductController::class, 'provisionMetadata']);

        // 商品组
        Route::get('edit_product_group_page', [ProductController::class, 'editGroupPage']);
        Route::get('edit_product_first_group_page', [ProductController::class, 'editFirstGroupPage']);
        Route::post('save_product_group', [ProductController::class, 'saveGroup']);
        Route::post('save_product_first_group', [ProductController::class, 'saveFirstGroup']);
        Route::get('del_product_group', [ProductController::class, 'deleteGroup']);
        Route::get('del_product_first_group', [ProductController::class, 'deleteFirstGroup']);
        Route::get('product/edit_productgrouppage', [ProductController::class, 'productGroupTree']);

        // 文件下载
        Route::get('product_selectcates', [ProductController::class, 'selectCates']);
        Route::get('product_downloadcates', [ProductController::class, 'downloadCates']);
        Route::post('product_downloadcats', [ProductController::class, 'downloadCats']);
        Route::post('product_manage_downloads', [ProductController::class, 'manageDownloads']);
        Route::post('product_del_custom', [ProductController::class, 'deleteCustomField']);
        Route::get('product/zklist_page', [ProductController::class, 'discountListPage']);

        // 全局可配置项
        Route::get('options/groups_list', [ConfigOptionController::class, 'groupsList']);
        Route::get('options/search_page', [ConfigOptionController::class, 'searchPage']);
        Route::get('options/duplicate_groups', [ConfigOptionController::class, 'duplicateGroupsPage']);
        Route::post('options/duplicate_groups_post', [ConfigOptionController::class, 'duplicateGroups']);
        Route::get('options/delete_groups/<id>', [ConfigOptionController::class, 'deleteGroup']);
        Route::get('options/edit_groups/<id>', [ConfigOptionController::class, 'editGroup']);
        Route::post('options/create_groups_post', [ConfigOptionController::class, 'createGroup']);
        Route::post('options/edit_groups_post', [ConfigOptionController::class, 'updateGroup']);
        Route::get('options/add_options_page', [ConfigOptionController::class, 'addOptionPage']);
        Route::post('options/add_options', [ConfigOptionController::class, 'addOption']);
        Route::get('options/edit_config/<id>', [ConfigOptionController::class, 'editOptionPage']);
        Route::post('options/edit_config_post', [ConfigOptionController::class, 'updateOption']);
        Route::get('options/delete_options/<id>', [ConfigOptionController::class, 'deleteOption']);
        Route::get('options/config_options_check_os', [ConfigOptionController::class, 'checkOs']);
        Route::post('options/config_options_check_os', [ConfigOptionController::class, 'checkOs']);
        Route::post('options/saveLinkAgeLevel', [ConfigOptionController::class, 'saveLinkAgeLevel']);
        Route::post('options/saveLinkAgeOrder', [ConfigOptionController::class, 'saveLinkAgeOrder']);
        Route::get('options/getNextLinkAgeList', [ConfigOptionController::class, 'getNextLinkAgeList']);
        Route::get('adminGetLinkAgeList', [ConfigOptionController::class, 'adminGetLinkAgeList']);

        // 通用接口 / 服务器
        Route::get('servers_list', [ServerController::class, 'list']);
        Route::get('groups_list', [ServerController::class, 'groupList']);
        Route::get('servers_add', [ServerController::class, 'addPage']);
        Route::post('servers_add_post', [ServerController::class, 'create']);
        Route::get('edit_servers/<id>', [ServerController::class, 'editPage']);
        Route::post('edit_servers_post', [ServerController::class, 'update']);
        Route::get('delete_servers/<id>', [ServerController::class, 'delete']);
        Route::post('get_modules_group', [ServerController::class, 'moduleGroup']);
        Route::get('get_modules_group', [ServerController::class, 'moduleGroup']);
        Route::get('server_test_link/<id>', [ServerController::class, 'testConnection']);
        Route::get('create_groups', [ServerController::class, 'createGroupPage']);
        Route::post('create_groups_post', [ServerController::class, 'createGroup']);
        Route::get('edit_server_groups/<id>', [ServerController::class, 'editGroup']);
        Route::post('edit_server_groups_post', [ServerController::class, 'updateGroup']);
        Route::get('delete_server_groups/<id>', [ServerController::class, 'deleteGroup']);

        // 支付接口 / 插件
        Route::get('plugins', [ServerController::class, 'pluginList']);
        Route::get('pl_index/<module>', [ServerController::class, 'pluginIndex']);
        Route::post('pl_install', [ServerController::class, 'pluginInstall']);
        Route::post('pl_uninstall', [ServerController::class, 'pluginUninstall']);
        Route::post('pl_toggle', [ServerController::class, 'pluginToggle']);
        Route::post('pl_copy', [ServerController::class, 'pluginCopy']);
        Route::get('pl_setting/<type>/<id>', [ServerController::class, 'pluginSetting']);
        Route::post('pl_setting_post', [ServerController::class, 'pluginSettingPost']);

        // ------------------------------------------------------------------
        // 3. 订单 — orders
        // ------------------------------------------------------------------
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('order/search_page', [OrderController::class, 'searchPage']);
        Route::get('order/search', [OrderController::class, 'search']);
        Route::get('orderdetail', [OrderController::class, 'detail']);
        Route::get('order/<id>', [OrderController::class, 'detail'])->whereNumber('id');
        Route::get('order/getclients', [OrderController::class, 'getClients']);
        Route::post('order/order_commission', [OrderController::class, 'commission']);
        Route::post('order/order_commission_list', [OrderController::class, 'commission']);
        Route::get('order/check', [OrderController::class, 'check']);
        Route::match(['get', 'post'], 'order/active', [OrderController::class, 'check']);
        Route::get('order/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/change_status', [OrderController::class, 'changeStatus']);
        Route::delete('orders/delete', [OrderController::class, 'delete']);
        Route::match(['get', 'post'], 'order/getTotal', [OrderController::class, 'getTotal']);
        Route::get('get_total', [OrderController::class, 'getTotal']);
        Route::post('get_total', [OrderController::class, 'getTotal']);
        Route::get('order/create_page', [OrderController::class, 'createPage']);
        Route::post('order/create', [OrderController::class, 'create']);
        Route::get('ordersadd', [OrderController::class, 'createPage']);
        Route::get('order/promo_code_page', [OrderController::class, 'promoCodePage']);
        Route::post('order/save_promo_code', [OrderController::class, 'savePromoCode']);
        Route::get('auto_promo_code', [OrderController::class, 'autoPromoCode']);
        Route::get('orders/set_config', [OrderController::class, 'setConfig']);
        Route::get('order/set_config', [OrderController::class, 'setConfig']);
        Route::get('trafficorder', [OrderController::class, 'trafficOrder']);
        Route::get('order/saleorder', [DashboardController::class, 'saleOrders']);
        Route::get('request_cancel_list', [OrderController::class, 'cancelRequestList']);
        Route::delete('request_cancel_list/<id>', [OrderController::class, 'cancelRequestDelete']);
        Route::get('request_cancel_reason', [OrderController::class, 'cancelReasons']);
        Route::post('request_cancel_reason', [OrderController::class, 'cancelReasonSave']);
        Route::match(['post'], 'request_cancel_reason_post', [OrderController::class, 'cancelReasonUpdate']);
        Route::delete('request_cancel_reason/<id>', [OrderController::class, 'cancelReasonDelete']);

        // ------------------------------------------------------------------
        // 4. 业务 — services / hosts
        // ------------------------------------------------------------------
        Route::get('clients_services/list', [ServiceController::class, 'list']);
        Route::post('clients_services/info', [ServiceController::class, 'update']);
        Route::post('clients_services/transfer', [ServiceController::class, 'transfer']);
        Route::delete('clients_services/host', [ServiceController::class, 'delete']);
        Route::get('clients_services/host_renew', [ServiceController::class, 'renewPage']);
        Route::post('clients_services/host_renew', [ServiceController::class, 'renew']);
        Route::get('host_renew', [ServiceController::class, 'renewPage']);
        Route::get('clients_services/host_batch_renew_page', [ServiceController::class, 'batchRenewPage']);
        Route::post('clients_services/host_batch_renew_page', [ServiceController::class, 'batchRenewPage']);
        Route::get('clients_services/host_batch_renew', [ServiceController::class, 'batchRenew']);
        Route::post('clients_services/host_batch_renew', [ServiceController::class, 'batchRenew']);
        Route::get('host_batch_renew_page', [ServiceController::class, 'batchRenewPage']);
        Route::post('host_batch_renew', [ServiceController::class, 'batchRenew']);
        Route::get('clients_services/host_suspend', [ServiceController::class, 'suspendPage']);
        Route::post('clients_services/host_suspend', [ServiceController::class, 'suspend']);
        Route::get('host_suspend', [ServiceController::class, 'suspendPage']);
        Route::post('clients_services/unsuspend', [ServiceController::class, 'unsuspend']);
        Route::get('clients_services/refund_page', [ServiceController::class, 'refundPage']);
        Route::post('clients_services/refund', [ServiceController::class, 'refund']);
        Route::get('clients_services/apply_credit_page', [ServiceController::class, 'applyCreditPage']);
        Route::post('clients_services/apply_credit', [ServiceController::class, 'applyCredit']);
        Route::post('clients_services/upgrade_config', [ServiceController::class, 'upgradeConfig']);
        Route::get('clients_services/get_product_list', [ServiceController::class, 'getProductList']);
        Route::get('clients_services/host_get_timetype', [ServiceController::class, 'getTimeType']);
        Route::get('host_get_timetype', [ServiceController::class, 'getTimeType']);

        // 模块操作
        Route::post('provision/default', [ServiceController::class, 'provisionDefault']);
        Route::post('provision/custom', [ServiceController::class, 'provisionCustom']);

        // ------------------------------------------------------------------
        // 5. 财务 — invoices, transactions, credit, withdrawals
        // ------------------------------------------------------------------
        Route::get('invoice/index', [InvoiceController::class, 'index']);
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('viewinvoices', [InvoiceController::class, 'index']);
        Route::get('invoice/search_page', [InvoiceController::class, 'searchPage']);
        Route::get('invoice/paid', [InvoiceController::class, 'markPaid']);
        Route::get('invoice/unpaid', [InvoiceController::class, 'markUnpaid']);
        Route::get('invoice/cancelled', [InvoiceController::class, 'markCancelled']);
        Route::get('invoice/duplicate', [InvoiceController::class, 'duplicate']);
        Route::get('invoice/summary/<id>', [InvoiceController::class, 'summary']);
        Route::get('invoice/<id>', [InvoiceController::class, 'detail'])->whereNumber('id');
        Route::delete('invoice/delete', [InvoiceController::class, 'delete']);
        Route::post('invoice/email', [InvoiceController::class, 'sendEmail']);
        Route::get('invoice/addpay_page/<id>', [InvoiceController::class, 'addPayPage']);
        Route::post('invoice/addpay', [InvoiceController::class, 'addPay']);
        Route::get('invoice/add_pay_invoice_page/<id>', [InvoiceController::class, 'addPayInvoicePage']);
        Route::post('invoice/add_pay_invoice', [InvoiceController::class, 'addPayInvoice']);
        Route::post('invoice/delete_pay_invoice', [InvoiceController::class, 'deletePayInvoice']);
        Route::get('invoice/option_page/<id>', [InvoiceController::class, 'optionPage']);
        Route::post('invoice/option', [InvoiceController::class, 'option']);
        Route::post('invoice/apply_credit_limit', [InvoiceController::class, 'applyCreditLimit']);
        Route::get('invoice/refund_page', [InvoiceController::class, 'refundPage']);
        Route::post('invoice/refund', [InvoiceController::class, 'refund']);
        Route::get('invoice/notes_page', [InvoiceController::class, 'notesPage']);
        Route::post('invoice/notes', [InvoiceController::class, 'notes']);
        Route::delete('invoice/delete_item', [InvoiceController::class, 'deleteItem']);
        Route::post('invoice/edit_item', [InvoiceController::class, 'editItem']);
        Route::delete('invoice/delete_account/<id>', [InvoiceController::class, 'deleteAccount']);
        Route::get('invoice/log_list', [InvoiceController::class, 'logList']);
        Route::get('invoice/renew', [InvoiceController::class, 'renewList']);
        Route::get('invoice/type/list', [InvoiceController::class, 'typeList']);

        // 交易流水
        Route::get('accounts', [InvoiceController::class, 'accounts']);
        Route::post('accounts', [InvoiceController::class, 'accountSave']);
        Route::get('accounts/create', [InvoiceController::class, 'accountCreate']);
        Route::get('accounts/createinvoice', [InvoiceController::class, 'accountCreateInvoice']);
        Route::get('accounts/<id>', [InvoiceController::class, 'accountRead'])->whereNumber('id');
        Route::put('accounts/<id>', [InvoiceController::class, 'accountUpdate'])->whereNumber('id');
        Route::delete('accounts/<id>', [InvoiceController::class, 'accountDelete'])->whereNumber('id');
        Route::get('accounts_search', [InvoiceController::class, 'accountSearch']);
        Route::get('search_page', [InvoiceController::class, 'searchPage']);

        // 信用额
        Route::get('credit_limit', [InvoiceController::class, 'creditLimit']);
        Route::post('credit_limit', [InvoiceController::class, 'creditLimitCreate']);
        Route::put('credit_limit', [InvoiceController::class, 'creditLimitUpdate']);
        Route::delete('credit_limit', [InvoiceController::class, 'creditLimitDelete']);
        Route::get('credit_limit/list', [InvoiceController::class, 'creditLimitList']);
        Route::get('credit_limit/client_list', [InvoiceController::class, 'creditLimitClientList']);
        Route::get('credit_limit/user_invoice', [InvoiceController::class, 'creditLimitUserInvoice']);
        Route::get('credit_limit/user_invoice_detail', [InvoiceController::class, 'creditLimitUserInvoiceDetail']);
        Route::get('credit_limit/log', [InvoiceController::class, 'creditLimitLog']);
        Route::get('credit_limit/config', [InvoiceController::class, 'creditLimitConfig']);
        Route::post('credit_limit/config', [InvoiceController::class, 'creditLimitConfigSave']);
        Route::get('credit', [InvoiceController::class, 'creditList']);
        Route::post('credit/create', [InvoiceController::class, 'creditCreate']);
        Route::delete('credit/<id>', [InvoiceController::class, 'creditDelete']);

        // 提现审核
        Route::get('withdraw/withdraw', [WithdrawController::class, 'index']);
        Route::post('withdraw/withdraw', [WithdrawController::class, 'operate']);
        Route::get('withdrawdeposits', [WithdrawController::class, 'index']);
        Route::post('aff/affiwithdraw_record', [WithdrawController::class, 'affiliateRecords']);
        Route::get('aff/affiwithdraw_record', [WithdrawController::class, 'affiliateRecords']);
        Route::post('aff/affiwithdrawsh', [WithdrawController::class, 'affiliateOperate']);
        Route::get('aff/gateway_list', [WithdrawController::class, 'gatewayList']);
        Route::get('aff/withdraw_method', [WithdrawController::class, 'gatewayList']);
        Route::post('aff/withdraw_method', [WithdrawController::class, 'gatewaySave']);
        Route::delete('aff/withdraw_method/<id>', [WithdrawController::class, 'gatewayDelete']);

        // 优惠码
        Route::get('list_promo_code', [SettingController::class, 'promoList']);
        Route::post('expired_promo_code', [SettingController::class, 'promoExpire']);
        Route::post('delete_promo_code', [SettingController::class, 'promoDelete']);
        Route::get('add_promo_code/page', [SettingController::class, 'promoAddPage']);
        Route::get('save_promo_code/page', [SettingController::class, 'promoEditPage']);
        Route::post('add_promo_code', [SettingController::class, 'promoCreate']);
        Route::post('save_promo_code', [SettingController::class, 'promoUpdate']);

        // 货币
        Route::get('currency/currency_list', [SettingController::class, 'currencyList']);
        Route::post('currency/create', [SettingController::class, 'currencyCreate']);
        Route::post('currency/update', [SettingController::class, 'currencyUpdate']);
        Route::post('currency/update_rate', [SettingController::class, 'currencyUpdateRate']);
        Route::post('currency/update_price', [SettingController::class, 'currencyUpdatePrice']);
        Route::post('currency/default', [SettingController::class, 'currencyDefault']);
        Route::delete('currency/<id>', [SettingController::class, 'currencyDelete']);

        // ------------------------------------------------------------------
        // 6. 工单 — tickets
        // ------------------------------------------------------------------
        Route::get('list_ticket', [TicketController::class, 'list']);
        Route::get('list_ticket/<id>', [TicketController::class, 'detail'])->whereNumber('id');
        Route::get('list_ticket_status', [TicketController::class, 'statusList']);
        Route::get('list_ticket_status/<id>', [TicketController::class, 'statusDetail'])->whereNumber('id');
        Route::post('add_ticket_status', [TicketController::class, 'statusCreate']);
        Route::post('save_ticket_status', [TicketController::class, 'statusUpdate']);
        Route::post('delete_ticket_status', [TicketController::class, 'statusDelete']);
        Route::get('list_ticket_department', [TicketController::class, 'departmentList']);
        Route::post('add_ticket_department', [TicketController::class, 'departmentCreate']);
        Route::post('save_ticket_department', [TicketController::class, 'departmentUpdate']);
        Route::post('delete_ticket_department', [TicketController::class, 'departmentDelete']);
        Route::post('moveup_ticket_department', [TicketController::class, 'departmentMoveUp']);
        Route::post('movedown_ticket_department', [TicketController::class, 'departmentMoveDown']);
        Route::get('getTicketDepartment', [TicketController::class, 'departmentList']);
        Route::get('get_ticket_department', [TicketController::class, 'departmentList']);
        Route::get('download_ticket_attachment', [TicketController::class, 'downloadAttachment']);
        Route::get('add_ticket_page', [TicketController::class, 'createPage']);
        Route::post('add_ticket', [TicketController::class, 'create']);
        Route::post('save_ticket', [TicketController::class, 'save']);
        Route::post('reply_ticket', [TicketController::class, 'reply']);
        Route::post('save_ticket_reply', [TicketController::class, 'saveReply']);
        Route::post('delete_ticket_reply', [TicketController::class, 'deleteReply']);
        Route::post('add_ticket_note', [TicketController::class, 'addNote']);
        Route::post('delete_ticket_note', [TicketController::class, 'deleteNote']);
        Route::post('close_ticket', [TicketController::class, 'close']);
        Route::post('delete_ticket', [TicketController::class, 'delete']);
        Route::post('merge_ticket', [TicketController::class, 'merge']);
        Route::put('ticket_receive', [TicketController::class, 'receive']);
        Route::put('ticket_transfer', [TicketController::class, 'transfer']);
        Route::get('ticket_transfer_list', [TicketController::class, 'transferList']);
        Route::get('ticket_detail_host', [TicketController::class, 'detailHost']);
        Route::get('ticket/statistics', [TicketController::class, 'statistics']);
        Route::get('get_ticket_deliver', [TicketController::class, 'deliverList']);
        Route::get('list_ticket_deliver', [TicketController::class, 'deliverList']);
        Route::post('add_ticket_deliver', [TicketController::class, 'deliverCreate']);
        Route::get('ticket_prereply_list', [TicketController::class, 'prereplyList']);
        Route::post('add_ticket_prereply_category', [TicketController::class, 'prereplyCategoryCreate']);
        Route::post('save_ticket_prereply_category', [TicketController::class, 'prereplyCategoryUpdate']);
        Route::delete('delete_ticket_prereply_category/<id>', [TicketController::class, 'prereplyCategoryDelete']);
        Route::get('add_ticket_prereply_category/page', [TicketController::class, 'prereplyCategoryPage']);
        Route::get('add_ticket_prereply/page', [TicketController::class, 'prereplyPage']);
        Route::get('save_ticket_prereply/page', [TicketController::class, 'prereplyPage']);
        Route::post('add_ticket_prereply', [TicketController::class, 'prereplyCreate']);
        Route::post('save_ticket_prereply', [TicketController::class, 'prereplyUpdate']);
        Route::post('search_ticket_prereply', [TicketController::class, 'prereplyList']);
        Route::delete('ticket_prereply/<id>', [TicketController::class, 'prereplyDelete']);
        Route::post('ticket_prereply/<id>', [TicketController::class, 'prereplyUpdate']);
        Route::get('add_ticket_custom_param', [TicketController::class, 'customParamList']);
        Route::post('add_ticket_custom_param', [TicketController::class, 'customParamCreate']);
        Route::post('edit_ticket_custom_param', [TicketController::class, 'customParamUpdate']);
        Route::get('edit_ticket_custom_param', [TicketController::class, 'customParamList']);
        Route::get('get_custom_param_type', [TicketController::class, 'customParamTypes']);
        Route::get('get_ticket_param_val', [TicketController::class, 'customParamValues']);
        Route::post('tastes/editUserTanstes', [TicketController::class, 'tastes']);

        // ------------------------------------------------------------------
        // 7. 设置 — settings
        // ------------------------------------------------------------------
        Route::match(['get', 'post'], 'config_general/general', [SettingController::class, 'general']);
        Route::match(['get', 'post'], 'config_general/local', [SettingController::class, 'local']);
        Route::match(['get', 'post'], 'config_general/support', [SettingController::class, 'support']);
        Route::match(['get', 'post'], 'config_general/invoice', [SettingController::class, 'invoice']);
        Route::match(['get', 'post'], 'config_general/recharge', [SettingController::class, 'recharge']);
        Route::match(['get', 'post'], 'config_general/affiliate', [SettingController::class, 'affiliate']);
        Route::match(['get', 'post'], 'config_general/safe', [SettingController::class, 'safe']);
        Route::match(['get', 'post'], 'config_general/other', [SettingController::class, 'other']);
        Route::match(['get', 'post'], 'config_general/apiconfig', [SettingController::class, 'apiConfig']);
        Route::match(['get', 'post'], 'config_general/register_login', [SettingController::class, 'registerLogin']);
        Route::get('config_general/register_login_page', [SettingController::class, 'registerLogin']);
        Route::get('config_general/invoice_page', [SettingController::class, 'invoicePage']);
        Route::post('config_general/invoice_post', [SettingController::class, 'invoicePost']);
        Route::get('config_general/productgroup_page', [SettingController::class, 'productGroupPage']);
        Route::post('config_general/productgroup', [SettingController::class, 'productGroup']);
        Route::get('config_general/productgroup', [SettingController::class, 'productGroupPage']);
        Route::match(['get', 'post'], 'config_general/secondverify', [SettingController::class, 'secondVerify']);
        Route::get('config_general/captcha_page', [SettingController::class, 'captchaPage']);
        Route::post('config_general/register_login_captcha', [SettingController::class, 'captchaPost']);
        Route::post('config_general/captcha', [SettingController::class, 'captchaPost']);
        Route::get('config_general/buy_product_page', [SettingController::class, 'buyProductPage']);
        Route::post('config_general/buy_product', [SettingController::class, 'buyProduct']);
        Route::get('config_general/buy_product', [SettingController::class, 'buyProductPage']);
        Route::post('config_general/navgrouporder', [SettingController::class, 'navGroupOrder']);
        Route::post('config_general/newGeneral', [SettingController::class, 'newGeneral']);
        Route::post('config_general/getConfig', [SettingController::class, 'getConfig']);
        Route::post('config_general/getConfigOption', [SettingController::class, 'getConfigOption']);
        Route::match(['get', 'post'], 'config_general/email/index', [SettingController::class, 'emailConfig']);
        Route::match(['get', 'post'], 'config_general/mobile/index', [SettingController::class, 'mobileConfig']);
        Route::match(['get', 'post'], 'config_general/certifi/index', [SettingController::class, 'certifyConfig']);
        Route::match(['get', 'post'], 'config_general/header', [SettingController::class, 'headerConfig']);
        Route::get('config_general/new_login', [SettingController::class, 'loginSetting']);
        Route::get('config_general/affiliate/config', [SettingController::class, 'affiliate']);

        // 定时任务
        Route::get('cron_page', [SettingController::class, 'cronPage']);
        Route::post('save_cron', [SettingController::class, 'cronSave']);
        Route::get('run_cron/list', [SettingController::class, 'cronRunList']);
        Route::get('run_cron/trend', [SettingController::class, 'cronRunTrend']);
        Route::get('cron/exec', [SettingController::class, 'cronExec']);

        // 员工 / 权限
        Route::get('adminuser', [RbacController::class, 'adminList']);
        Route::post('adminuser', [RbacController::class, 'adminCreate']);
        Route::post('adminuser/update', [RbacController::class, 'adminUpdate']);
        Route::get('adminuser/<id>', [RbacController::class, 'adminDetail'])->whereNumber('id');
        Route::delete('adminuser/<id>', [RbacController::class, 'adminDelete'])->whereNumber('id');
        Route::get('create_page', [RbacController::class, 'adminCreatePage']);
        Route::get('rbac', [RbacController::class, 'roleList']);
        Route::post('rbac', [RbacController::class, 'roleCreate']);
        Route::post('rbac/edit', [RbacController::class, 'roleUpdate']);
        Route::post('rbac/copyRole', [RbacController::class, 'roleCopy']);
        Route::get('rbac/role_page', [RbacController::class, 'rolePage']);
        Route::get('rbac/<id>', [RbacController::class, 'roleDetail'])->whereNumber('id');
        Route::delete('rbac/<id>', [RbacController::class, 'roleDelete'])->whereNumber('id');
        Route::get('rbacpage', [RbacController::class, 'roleList']);
        Route::get('rbacPage', [RbacController::class, 'roleList']);
        Route::get('permissionsmanagment', [RbacController::class, 'roleList']);
        Route::get('sale/adminlist', [RbacController::class, 'saleAdminList']);
        Route::get('salegroup', [RbacController::class, 'saleGroupList']);
        Route::post('salegroup', [RbacController::class, 'saleGroupSave']);
        Route::delete('salegroup/<id>', [RbacController::class, 'saleGroupDelete']);
        Route::get('sale/add_salegrouppage', [RbacController::class, 'saleGroupPage']);
        Route::get('sale/edit_salegrouppage', [RbacController::class, 'saleGroupPage']);
        Route::get('saleladder', [RbacController::class, 'saleLadderList']);
        Route::post('saleladder', [RbacController::class, 'saleLadderSave']);
        Route::delete('saleladder/<id>', [RbacController::class, 'saleLadderDelete']);
        Route::get('sale/edit_saleladderpage', [RbacController::class, 'saleLadderPage']);
        Route::get('sale/edit_delproduct', [RbacController::class, 'saleProducts']);

        // 日志
        Route::get('log_record/systemlog', [LogController::class, 'systemLog']);
        Route::get('log_record/adminlog', [LogController::class, 'adminLog']);
        Route::get('log_record/smslog', [LogController::class, 'smsLog']);
        Route::get('log_record/emaillog', [LogController::class, 'emailLog']);
        Route::get('log_record/api_log', [LogController::class, 'apiLog']);
        Route::get('log_record/cronsystemlog', [LogController::class, 'cronLog']);
        Route::get('log_record/systemmessagelog', [LogController::class, 'messageLog']);
        Route::get('log_record/notifylog', [LogController::class, 'notifyLog']);
        Route::get('log_record/userlog', [LogController::class, 'userLog']);
        Route::get('log_record', [LogController::class, 'systemLog']);
        Route::delete('log_record/delete_log', [LogController::class, 'deleteLog']);

        // 邮件 / 短信模板
        Route::get('email_template/email_list', [SettingController::class, 'emailTemplateList']);
        Route::get('email_template/emailtemplate_list', [SettingController::class, 'emailTemplateList']);
        Route::get('emailtemplate/list', [SettingController::class, 'emailTemplateList']);
        Route::get('email_template/create_template', [SettingController::class, 'emailTemplateCreatePage']);
        Route::post('email_template/create_template_post', [SettingController::class, 'emailTemplateCreate']);
        Route::get('email_template/edit_template/<id>', [SettingController::class, 'emailTemplateEdit']);
        Route::post('email_template/edit_template_post', [SettingController::class, 'emailTemplateUpdate']);
        Route::post('email_template/disabled_template', [SettingController::class, 'emailTemplateDisable']);
        Route::get('email_template/delete_template/<id>', [SettingController::class, 'emailTemplateDelete']);
        Route::get('email_template/manage_language', [SettingController::class, 'emailTemplateLanguages']);
        Route::post('email_template/manage_language_post', [SettingController::class, 'emailTemplateLanguageSave']);
        Route::post('email_template/disabled', [SettingController::class, 'emailTemplateDisable']);
        Route::post('email_template/operator_switch', [SettingController::class, 'emailTemplateDisable']);
        Route::get('email_template/params', [SettingController::class, 'emailTemplateParams']);
        Route::post('email_template/send_email', [SettingController::class, 'sendTestEmail']);
        Route::get('edit_template', [SettingController::class, 'emailTemplateEdit']);
        Route::get('config_message/template_list', [SettingController::class, 'messageTemplateList']);
        Route::get('config_message/mobiletemplate/list', [SettingController::class, 'messageTemplateMobile']);
        Route::get('config_message/update_tem_status', [SettingController::class, 'messageTemplateStatus']);
        Route::get('config_message/config_mobile', [SettingController::class, 'messageMobileConfig']);
        Route::get('config_message/delete_template', [SettingController::class, 'messageTemplateDelete']);
        Route::post('config_message/check_post', [SettingController::class, 'messageTemplateCheck']);
        Route::get('config_message/test_message_template_page', [SettingController::class, 'messageTemplateTestPage']);
        Route::post('config_message/update_template_post', [SettingController::class, 'messageTemplateUpdate']);
        Route::post('config_message/test_message_template', [SettingController::class, 'messageTemplateTest']);
        Route::get('config_message/create_template_page', [SettingController::class, 'messageTemplateCreatePage']);
        Route::post('config_message/set_sms', [SettingController::class, 'messageSetSms']);
        Route::post('config_message/sendmessage_post', [SettingController::class, 'sendMessage']);
        Route::post('config_message/send_email', [SettingController::class, 'sendTestEmail']);
        Route::post('config_message/SetSmsTemplate', [SettingController::class, 'messageSetSms']);

        // 站务：导航 / 友情链接 / 新闻 / 帮助 / 知识库 / 下载
        Route::get('menus/allLinks', [SiteController::class, 'menuLinks']);
        Route::get('menus/getCreateWebData', [SiteController::class, 'menuCreateData']);
        Route::get('menu_setting_page', [SiteController::class, 'menuSettingPage']);
        Route::get('menu_position_page', [SiteController::class, 'menuPositionPage']);
        Route::post('menus/save', [SiteController::class, 'menuSave']);
        Route::delete('menus/<id>', [SiteController::class, 'menuDelete']);
        Route::get('nav/list', [SiteController::class, 'navList']);
        Route::post('nav/save', [SiteController::class, 'navSave']);
        Route::delete('nav/<id>', [SiteController::class, 'navDelete']);
        Route::get('nav_group/list', [SiteController::class, 'navGroupList']);
        Route::post('nav_group/save', [SiteController::class, 'navGroupSave']);
        Route::delete('nav_group/<id>', [SiteController::class, 'navGroupDelete']);
        Route::get('config_general/navgrouporder/list', [SiteController::class, 'navGroupList']);

        Route::get('friendly_link/list', [SiteController::class, 'friendlyLinkList']);
        Route::post('friendly_link/save', [SiteController::class, 'friendlyLinkSave']);
        Route::delete('friendly_link/<id>', [SiteController::class, 'friendlyLinkDelete']);
        Route::get('link_cause/list', [SiteController::class, 'friendlyLinkList']);

        Route::get('news/list', [SiteController::class, 'newsList']);
        Route::post('news/save', [SiteController::class, 'newsSave']);
        Route::delete('news/content', [SiteController::class, 'newsDelete']);
        Route::get('news/type', [SiteController::class, 'newsTypeList']);
        Route::post('news/type', [SiteController::class, 'newsTypeSave']);
        Route::delete('news/type/<id>', [SiteController::class, 'newsTypeDelete']);
        Route::get('link_knowledge/list', [SiteController::class, 'newsList']);

        Route::get('knowledge_base/index', [SiteController::class, 'knowledgeList']);
        Route::post('knowledge_base/save', [SiteController::class, 'knowledgeSave']);
        Route::delete('knowledge_base/<id>', [SiteController::class, 'knowledgeDelete']);
        Route::get('knowledge_base/cats', [SiteController::class, 'knowledgeCats']);
        Route::post('knowledge_base/cats', [SiteController::class, 'knowledgeCatSave']);
        Route::delete('knowledge_base/cats/<id>', [SiteController::class, 'knowledgeCatDelete']);

        Route::get('downloads/cat', [SiteController::class, 'downloadCatList']);
        Route::post('downloads/cat', [SiteController::class, 'downloadCatSave']);
        Route::delete('downloads/cat/<id>', [SiteController::class, 'downloadCatDelete']);
        Route::get('downloads/file', [SiteController::class, 'downloadFileList']);
        Route::post('downloads/file', [SiteController::class, 'downloadFileSave']);
        Route::delete('downloads/file/<id>', [SiteController::class, 'downloadFileDelete']);

        // 二次验证 / 黑名单 / 系统信息
        Route::match(['get', 'post'], 'twice_confirm/setting', [SettingController::class, 'secondVerify']);
        Route::get('user/get_black_list', [SiteController::class, 'blackList']);
        Route::post('user/black_list', [SiteController::class, 'blackListSave']);
        Route::delete('user/black_list/<id>', [SiteController::class, 'blackListDelete']);
        Route::get('database/backup', [SiteController::class, 'databaseBackup']);
        Route::get('upgrade/version', [SiteController::class, 'systemVersion']);
        Route::get('upgrade/checkautoupdate', [SiteController::class, 'checkUpdate']);
        Route::get('upgrade/autoupdate', [SiteController::class, 'checkUpdate']);
        Route::get('upgrade/sqlupdate', [SiteController::class, 'checkUpdate']);
        Route::get('system/systemAuthRuleLanguage', [RbacController::class, 'authRuleLanguage']);

        // ------------------------------------------------------------------
        // 菜单管理 / 合同管理 / 高级选项
        Route::post('menus/getMenuList', [MenuController::class, 'getMenuList']);
        Route::post('menus/getMenu', [MenuController::class, 'getMenu']);
        Route::post('menus/getMenuType', [MenuController::class, 'getMenuType']);
        Route::post('menus/getNavType', [MenuController::class, 'getMenuType']);
        Route::post('menus/getTypeAllMenu', [MenuController::class, 'getTypeAllMenu']);
        Route::post('menus/getProductList', [MenuController::class, 'getProductList']);
        Route::post('menus/getLang', [MenuController::class, 'getLang']);
        Route::post('menus/getSystemNav', [MenuController::class, 'getSystemNav']);
        Route::post('menus/getOtherMenu', [MenuController::class, 'getOtherMenu']);
        Route::post('menus/getDefaultSenior', [MenuController::class, 'getDefaultSenior']);
        Route::post('menus/addMenu', [MenuController::class, 'addMenu']);
        Route::post('menus/editMenu', [MenuController::class, 'editMenu']);
        Route::post('menus/delMenu', [MenuController::class, 'delMenu']);
        Route::post('menus/delTwoMenu', [MenuController::class, 'delTwoMenu']);
        Route::post('menus/addCustomPage', [MenuController::class, 'addCustomPage']);
        Route::post('menus/addProductPage', [MenuController::class, 'addProductPage']);
        Route::post('menus/createWebPage', [MenuController::class, 'createWebPage']);
        Route::post('menus/setNavList', [MenuController::class, 'setNavList']);
        Route::post('menus/setWebNavList', [MenuController::class, 'setWebNavList']);
        Route::post('menus/editMenuActive', [MenuController::class, 'editMenuActive']);
        Route::post('menus/saveLinks', [MenuController::class, 'saveLinks']);
        Route::post('menus/deleteLinks', [MenuController::class, 'deleteLinks']);
        Route::match(['get', 'post'], 'menu/setting_page', [SiteController::class, 'menuSettingPage']);
        Route::match(['get', 'post'], 'menu/position_page', [SiteController::class, 'menuPositionPage']);
        Route::post('menu/create', [MenuController::class, 'addMenu']);
        Route::post('menu/create_nav', [MenuController::class, 'addMenu']);
        Route::post('menu/edit', [MenuController::class, 'editMenu']);
        Route::post('menu/delete', [MenuController::class, 'delMenu']);
        Route::post('menu/delete_nav', [MenuController::class, 'delMenu']);
        Route::post('menu/save_position', [SettingController::class, 'navGroupOrder']);

        Route::match(['get', 'post'], 'contract/setting', [MenuController::class, 'contractSetting']);
        Route::get('contract/tpl', [MenuController::class, 'contractTpl']);
        Route::delete('contract/tpl/<id>', [MenuController::class, 'contractDeleteTpl']);
        Route::match(['get', 'post'], 'contract/detail', [MenuController::class, 'contractDetail']);
        Route::match(['get', 'post'], 'contract/detail/<id>', [MenuController::class, 'contractDetail']);
        Route::get('contract/contract', [MenuController::class, 'contractList']);
        Route::post('contract/check', [MenuController::class, 'contractCheck']);
        Route::post('contract/cancel', [MenuController::class, 'contractCancel']);
        Route::post('contract/cancel_post/<id>', [MenuController::class, 'contractCancelPost']);
        Route::match(['get', 'post'], 'contract/contract_page', [MenuController::class, 'contractPage']);
        Route::post('contract/contract_page/<id>', [MenuController::class, 'contractPage']);
        Route::get('contract/download/<id>', [MenuController::class, 'contractDownload']);
        Route::post('contract/delete', [MenuController::class, 'contractDelete']);

        Route::match(['get', 'post'], 'advanced_options', [MenuController::class, 'advancedOptionsSave']);
        Route::get('advanced_options/page', [MenuController::class, 'advancedOptionsPage']);
        Route::match(['get', 'post'], 'advanced_options/create', [MenuController::class, 'advancedOptionsCreate']);
        Route::post('advanced_options/addcondition', [MenuController::class, 'advancedOptionsAddCondition']);
        Route::post('advanced_options/addresult', [MenuController::class, 'advancedOptionsAddResult']);
        Route::delete('advanced_options/deletecondition', [MenuController::class, 'advancedOptionsDeleteCondition']);
        Route::delete('advanced_options/deleteresult', [MenuController::class, 'advancedOptionsDeleteResult']);
        Route::get('advanced_options/<id>/edit', [MenuController::class, 'advancedOptionsEdit'])->whereNumber('id');
        Route::get('advanced_options/<id>', [MenuController::class, 'advancedOptionsRead'])->whereNumber('id');
        Route::put('advanced_options/<id>', [MenuController::class, 'advancedOptionsUpdate'])->whereNumber('id');
        Route::delete('advanced_options/<id>', [MenuController::class, 'advancedOptionsDelete'])->whereNumber('id');

        // 订单 — legacy aliases
        Route::post('orders_search', [OrderController::class, 'search']);
        Route::post('orders/active', [OrderController::class, 'check']);
        Route::post('orders/notes', [OrderController::class, 'notes']);
        Route::post('orders/change_status', [OrderController::class, 'changeStatus']);

        // 发票 — extra bill actions
        Route::post('invoices_createnew', [InvoiceController::class, 'createRenew']);
        Route::get('transactions', [InvoiceController::class, 'accounts']);

        // 配置别名
        Route::post('config_general/postaffiliate', [SettingController::class, 'affiliate']);
        Route::match(['get', 'post'], 'config_general/email_index', [SettingController::class, 'emailConfig']);
        Route::post('config_general/email_index_post', [SettingController::class, 'emailConfig']);
        Route::post('config_general/send_email', [SettingController::class, 'sendTestEmail']);
        Route::post('config_general/batch_send_email', [SettingController::class, 'sendMessage']);
        Route::match(['get', 'post'], 'config_general/certifi_index', [SettingController::class, 'certifyConfig']);
        Route::post('config_general/certifi_index_post', [SettingController::class, 'certifyConfig']);
        Route::match(['get', 'post'], 'config_general/mobile_index', [SettingController::class, 'mobileConfig']);
        Route::post('config_general/mobile_index_post', [SettingController::class, 'mobileConfig']);
        Route::get('config_general/lang_list', [SettingController::class, 'languageList']);
        Route::post('config_general/set_admin_lang', [RbacController::class, 'editSelfInfo']);
        Route::get('config_general/productgroup_list', [SettingController::class, 'productGroupPage']);
        Route::post('config_general/new_login', [SettingController::class, 'loginSetting']);
        Route::match(['get', 'post'], 'config_general/support_indeuploadFilex', [SettingController::class, 'support']);
        Route::match(['get', 'post'], 'config_certifi/setting', [SettingController::class, 'certifyConfig']);
        Route::get('config_certifi/authorDown', [SettingController::class, 'certifyConfig']);
        Route::get('config_certifi/authorDel', [SettingController::class, 'certifyConfig']);

        // 邮件 / 短信模板别名
        Route::get('emailtemplate_list', [SettingController::class, 'emailTemplateList']);
        Route::get('email_template_params', [SettingController::class, 'emailTemplateParams']);
        Route::post('config_message/config_mobile_post', [SettingController::class, 'mobileConfig']);
        Route::post('config_message/send_sms', [SettingController::class, 'messageTemplateTest']);
        Route::post('config_message/set_sms_post', [SettingController::class, 'messageSetSms']);
        Route::get('config_message/get_template_desc', [SettingController::class, 'messageTemplateList']);
        Route::post('config_message/update_template/<id>', [SettingController::class, 'messageTemplateUpdate']);

        // 工单别名
        Route::get('ticket_statistics', [TicketController::class, 'statistics']);
        Route::post('save_ticket_deliver', [TicketController::class, 'deliverCreate']);
        Route::post('delete_ticket_deliver', [TicketController::class, 'deliverCreate']);
        Route::post('del_ticket_custom_param', [TicketController::class, 'customParamUpdate']);

        // 客户 / 业务别名
        Route::post('track_status', [ClientController::class, 'clientTrackStatusPost']);
        Route::match(['get', 'post'], 'client_list_resource', [ClientController::class, 'resourceList']);
        Route::get('host/get_timetype', [ServiceController::class, 'getTimeType']);
        Route::get('host/userInfo', [ClientController::class, 'hostByUid']);
        Route::post('clients_services/upgrade_product', [ServiceController::class, 'upgradeConfig']);
        Route::get('get_api_list', [ProductController::class, 'apiList']);
        Route::post('product_income', [DashboardController::class, 'productIncome']);

        // 插件
        Route::post('pl_sort/<module>', [ServerController::class, 'pluginSort']);
        Route::post('pl_update', [ServerController::class, 'pluginInstall']);
        Route::get('pl_index/<module>/', [ServerController::class, 'pluginIndex']);

        // 日志别名
        Route::get('log_record/smslogm', [LogController::class, 'smsLog']);
        Route::get('log_record/system_message_log', [LogController::class, 'messageLog']);
        Route::get('log_record/delete_log_page', [LogController::class, 'systemLog']);

        // 货币别名
        Route::post('currency/add_currency', [SettingController::class, 'currencyCreate']);
        Route::post('currency/edit_currency_post', [SettingController::class, 'currencyUpdate']);
        Route::get('currency/edit_currency/<id>', [SettingController::class, 'currencyList']);
        Route::get('currency/default_currency/<id>', [SettingController::class, 'currencyDefault']);
        Route::get('currency/delete_currency/<id>', [SettingController::class, 'currencyDelete']);

        // 知识库别名
        Route::post('knowledge_base/add_article', [SiteController::class, 'knowledgeSave']);
        Route::post('knowledge_base/edit_article_post', [SiteController::class, 'knowledgeSave']);
        Route::get('knowledge_base/edit_article/<id>', [SiteController::class, 'knowledgeList']);
        Route::get('knowledge_base/delete_article/<id>', [SiteController::class, 'knowledgeDelete']);
        Route::get('knowledge_base/category_list/<id>', [SiteController::class, 'knowledgeCats']);
        Route::post('knowledge_base/add_category', [SiteController::class, 'knowledgeCatSave']);
        Route::post('knowledge_base/edit_category_post', [SiteController::class, 'knowledgeCatSave']);
        Route::get('knowledge_base/delete_category/<id>', [SiteController::class, 'knowledgeCatDelete']);
        Route::get('knowledge_base/tags_list', [SiteController::class, 'knowledgeList']);

        // 友情链接别名
        Route::post('link_cause/create', [SiteController::class, 'friendlyLinkSave']);
        Route::post('link_cause/edit', [SiteController::class, 'friendlyLinkSave']);
        Route::post('link_cause/add', [SiteController::class, 'friendlyLinkSave']);
        Route::post('link_cause/save', [SiteController::class, 'friendlyLinkSave']);
        Route::post('link_cause/delete', [SiteController::class, 'friendlyLinkDelete']);
        Route::match(['get', 'post'], 'link_knowledge/create', [SiteController::class, 'newsSave']);
        Route::match(['get', 'post'], 'link_knowledge/edit', [SiteController::class, 'newsSave']);
        Route::post('link_knowledge/save', [SiteController::class, 'newsSave']);
        Route::post('link_knowledge/delete', [SiteController::class, 'newsDelete']);

        // 系统信息
        Route::get('tablelist', [SiteController::class, 'databaseBackup']);
        Route::get('database_backup', [SiteController::class, 'databaseBackup']);
        Route::match(['get', 'post'], 'report/get_base_module', [DashboardController::class, 'reportBaseInfo']);
        Route::post('report/update_base_module', [DashboardController::class, 'reportBaseInfo']);
        Route::get('upgrade/checkupdatecopy', [SiteController::class, 'checkUpdate']);
        Route::get('upgrade/checkupdateunzip', [SiteController::class, 'checkUpdate']);
        Route::post('user/remove_black_list', [SiteController::class, 'blackListDelete']);
        Route::get('user/edit_self_info_page', [RbacController::class, 'adminDetail']);

        // 客户关怀 (client care)
        Route::get('client_care/search_condition', [ClientCareController::class, 'searchCondition']);
        Route::get('client_care/care_list', [ClientCareController::class, 'careList']);
        Route::get('client_care/create_care', [ClientCareController::class, 'createPage']);
        Route::post('client_care/create_care_post', [ClientCareController::class, 'create']);
        Route::get('client_care/edit_care/<id>', [ClientCareController::class, 'editPage']);
        Route::post('client_care/edit_care_post', [ClientCareController::class, 'update']);
        Route::get('client_care/delete_care/<id>', [ClientCareController::class, 'delete']);
        Route::get('client_care/test', [ClientCareController::class, 'test']);

        // 推介计划 (affiliate)
        Route::get('aff', [AffiliateController::class, 'index']);
        Route::get('affiliates', [AffiliateController::class, 'index']);
        Route::get('affladder', [AffiliateController::class, 'ladderList']);
        Route::post('aff/add_affladder', [AffiliateController::class, 'ladderSave']);
        Route::get('aff/edit_affladderpage', [AffiliateController::class, 'ladderPage']);
        Route::post('aff/edit_affladder', [AffiliateController::class, 'ladderSave']);
        Route::get('aff/del_affladder', [AffiliateController::class, 'ladderDelete']);
        Route::get('aff/get_timetype', [AffiliateController::class, 'timeType']);
        Route::get('aff/useraffi_page', [AffiliateController::class, 'userPage']);
        Route::get('aff/useraffi_list', [AffiliateController::class, 'userList']);
        Route::get('aff/useraffi_record', [AffiliateController::class, 'userRecord']);
        Route::get('aff/useraffibuy_record', [AffiliateController::class, 'userBuyRecord']);
        Route::post('aff/useraffi_post', [AffiliateController::class, 'userSave']);
        Route::post('aff/useraffi_balance', [AffiliateController::class, 'userBalance']);
        Route::get('aff/productaffi_page', [AffiliateController::class, 'productPage']);
        Route::post('aff/productaffi_post', [AffiliateController::class, 'productSave']);
        Route::get('aff/test', [AffiliateController::class, 'test']);

        // 第三方登录 (OAuth)
        Route::get('oauth', [ServerController::class, 'oauthList']);
        Route::post('oauth/active', [ServerController::class, 'pluginInstall']);
        Route::get('oauth/config', [ServerController::class, 'oauthConfig']);
        Route::post('oauth/config_post', [ServerController::class, 'pluginSettingPost']);
        Route::post('oauth/suspend', [ServerController::class, 'pluginUninstall']);

        // DCIM — direct (non-proxied) server management
        Route::get('dcim/server', [DcimController::class, 'serverList']);
        Route::post('dcim/server', [DcimController::class, 'serverSave']);
        Route::put('dcim/server', [DcimController::class, 'serverSave']);
        Route::delete('dcim/server', [DcimController::class, 'serverDelete']);
        Route::get('dcim/server/status', [DcimController::class, 'refreshAllStatus']);
        Route::get('dcim/server/<id>', [DcimController::class, 'serverDetail'])->whereNumber('id');
        Route::get('dcim/server/<id>/status', [DcimController::class, 'refreshStatus'])->whereNumber('id');
        Route::post('dcim/assign', [DcimController::class, 'assignServer']);
        Route::delete('dcim/delete', [DcimController::class, 'delete']);
        Route::get('dcim/detail', [DcimController::class, 'detail']);
        Route::get('dcim/sales', [DcimController::class, 'sales']);
        Route::post('dcim/refresh_power_status', [DcimController::class, 'refreshPowerStatus']);
        Route::get('dcim/traffic_usage', [DcimController::class, 'trafficUsage']);
        Route::post('dcim/traffic', [DcimController::class, 'traffic']);
        Route::get('dcim/download', [DcimController::class, 'download']);
        Route::get('dcim/resintall_status', [DcimController::class, 'reinstallStatus']);
        Route::post('dcim/cancel_task', [DcimController::class, 'cancelTask']);
        Route::post('dcim/unsuspend_reinstall', [DcimController::class, 'unsuspendReinstall']);
        Route::get('dcim/novnc', [DcimController::class, 'novncPage']);
        Route::post('dcim/novnc', [DcimController::class, 'powerAction']);
        Route::post('dcim/on', [DcimController::class, 'powerAction']);
        Route::post('dcim/off', [DcimController::class, 'powerAction']);
        Route::post('dcim/reboot', [DcimController::class, 'powerAction']);
        Route::post('dcim/bmc', [DcimController::class, 'powerAction']);
        Route::post('dcim/kvm', [DcimController::class, 'powerAction']);
        Route::post('dcim/ikvm', [DcimController::class, 'powerAction']);
        Route::post('dcim/reinstall', [DcimController::class, 'powerAction']);
        Route::post('dcim/rescue', [DcimController::class, 'powerAction']);
        Route::post('dcim/crack_pass', [DcimController::class, 'powerAction']);
        Route::get('dcim/flowpacket', [DcimController::class, 'flowPacketList']);
        Route::post('dcim/flowpacket', [DcimController::class, 'flowPacketSave']);
        Route::put('dcim/flowpacket', [DcimController::class, 'flowPacketSave']);
        Route::delete('dcim/flowpacket', [DcimController::class, 'flowPacketDelete']);
        Route::get('dcim/flowpacket_page', [DcimController::class, 'flowPacketPage']);
        Route::get('dcim/flowpacket_page/<id>', [DcimController::class, 'flowPacketPage'])->whereNumber('id');
        Route::get('dcim/buy_record', [DcimController::class, 'buyRecordList']);
        Route::delete('dcim/buy_record', [DcimController::class, 'buyRecordDelete']);

        // 8. 上下游 — upstream suppliers & downstream
        // ------------------------------------------------------------------
        Route::get('zjmf_finance_api', [UpstreamController::class, 'index']);
        Route::post('zjmf_finance_api', [UpstreamController::class, 'create']);
        Route::put('zjmf_finance_api', [UpstreamController::class, 'update']);
        Route::get('zjmf_finance_api/summary', [UpstreamController::class, 'summary']);
        Route::post('zjmf_finance_api/reset', [UpstreamController::class, 'reset']);
        Route::post('zjmf_finance_api/toggle', [UpstreamController::class, 'toggle']);
        Route::get('zjmf_finance_api/freepage', [UpstreamController::class, 'freePage']);
        Route::post('zjmf_finance_api/freepage', [UpstreamController::class, 'freePost']);
        Route::delete('zjmf_finance_api/freepage', [UpstreamController::class, 'freeDelete']);
        Route::get('zjmf_finance_api/products', [UpstreamController::class, 'products']);
        Route::get('zjmf_finance_api/order', [UpstreamController::class, 'orders']);
        Route::post('zjmf_finance_api/order_commission', [UpstreamController::class, 'orderCommission']);
        Route::get('zjmf_finance_api/renew', [UpstreamController::class, 'renew']);
        Route::get('zjmf_finance_api/host', [UpstreamController::class, 'hosts']);
        Route::get('zjmf_finance_api/downstream_summary', [UpstreamController::class, 'downstreamSummary']);
        Route::get('zjmf_finance_api/logs', [UpstreamController::class, 'logs']);
        Route::post('zjmf_finance_api/open', [UpstreamController::class, 'open']);
        Route::get('zjmf_finance_api/addpage', [UpstreamController::class, 'addPage']);
        Route::post('zjmf_finance_api/inputproduct', [UpstreamController::class, 'inputProduct']);
        Route::post('zjmf_finance_api/upstreamhost', [UpstreamController::class, 'upstreamHost']);
        Route::get('zjmf_finance_api/manualhost', [UpstreamController::class, 'manualHostList']);
        Route::post('zjmf_finance_api/manualhost', [UpstreamController::class, 'manualHostSave']);
        Route::get('zjmf_finance_api/upstreamcredit', [UpstreamController::class, 'upstreamCredit']);
        Route::get('zjmf_finance_api/<id>', [UpstreamController::class, 'detail'])->whereNumber('id');
        Route::put('zjmf_finance_api/<id>', [UpstreamController::class, 'update'])->whereNumber('id');
        Route::delete('zjmf_finance_api/<id>', [UpstreamController::class, 'delete'])->whereNumber('id');
        Route::get('zjmf_finance_api/<id>/status', [UpstreamController::class, 'refreshStatus'])->whereNumber('id');

        Route::get('zjmfapi', [UpstreamController::class, 'index']);
        Route::get('upStreamedit', [UpstreamController::class, 'index']);
        Route::get('munualresource', [UpstreamController::class, 'resourceList']);

        // 任务队列
        Route::get('run_map/list', [UpstreamController::class, 'taskQueue']);
        Route::get('run_map/repeat_task', [UpstreamController::class, 'taskQueue']);
        Route::post('run_map/repeat_task', [UpstreamController::class, 'repeatTask']);
        Route::post('task_queue/clear', [UpstreamController::class, 'taskQueueClear']);

        // 下游 API 用户
        Route::get('api', [UpstreamController::class, 'apiList']);
        Route::get('api/create', [UpstreamController::class, 'apiCreatePage']);
        Route::post('api', [UpstreamController::class, 'apiCreate']);
        Route::put('api', [UpstreamController::class, 'apiUpdate']);
        Route::get('api/<id>', [UpstreamController::class, 'apiDetail'])->whereNumber('id');
        Route::delete('api/<id>', [UpstreamController::class, 'apiDelete'])->whereNumber('id');
        Route::get('api_user_product/list', [UpstreamController::class, 'apiUserProductList']);

        // 上游资源（供货方手动资源）
        Route::get('upper/index', [UpstreamController::class, 'upperIndex']);
        Route::get('upper/upperindex', [UpstreamController::class, 'upperIndex']);
        Route::get('upper/addupperpage', [UpstreamController::class, 'upperAddPage']);
        Route::post('upper/addpost', [UpstreamController::class, 'upperAdd']);
        Route::post('upper/addupperpost', [UpstreamController::class, 'upperAdd']);
        Route::get('upper/editupperpage', [UpstreamController::class, 'upperEditPage']);
        Route::post('upper/edituppost', [UpstreamController::class, 'upperUpdate']);
        Route::post('upper/editupperpost', [UpstreamController::class, 'upperUpdate']);
        Route::post('upper/del', [UpstreamController::class, 'upperDelete']);
        Route::post('upper/delupper', [UpstreamController::class, 'upperDelete']);
        Route::post('upper/allotupper', [UpstreamController::class, 'upperAllot']);
        Route::post('upper/emptyupper', [UpstreamController::class, 'upperEmpty']);
        Route::get('upper/dcim_client/status', [UpstreamController::class, 'dcimStatus']);
        Route::post('upper/dcim_client/on', [UpstreamController::class, 'dcimOn']);
        Route::post('upper/dcim_client/off', [UpstreamController::class, 'dcimOff']);
        Route::post('upper/dcim_client/reboot', [UpstreamController::class, 'dcimReboot']);
        Route::post('upper/dcim_client/vnc', [UpstreamController::class, 'dcimVnc']);
        Route::post('upper/dcim_client/reinstall', [UpstreamController::class, 'dcimReinstall']);
        Route::post('upper/dcim_client/get_os', [UpstreamController::class, 'dcimGetOs']);
        Route::post('upper/dcim_client/crack_pass', [UpstreamController::class, 'dcimCrackPass']);
        Route::post('upper/dcim_client/resintall_status', [UpstreamController::class, 'dcimReinstallStatus']);
        Route::post('upper/dcim_client/cancel_task', [UpstreamController::class, 'dcimCancelTask']);
        Route::get('upper/ipmi/status', [UpstreamController::class, 'ipmiStatus']);
        Route::post('upper/ipmi/on', [UpstreamController::class, 'ipmiOn']);
        Route::post('upper/ipmi/off', [UpstreamController::class, 'ipmiOff']);
        Route::post('upper/ipmi/reboot', [UpstreamController::class, 'ipmiReboot']);
        Route::post('upper/ipmi/vnc', [UpstreamController::class, 'ipmiVnc']);
    });
});

<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\FinanceController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\Portal\CartController as PortalCartController;
use App\Http\Controllers\Web\Portal\DashboardController as PortalDashboardController;
use App\Http\Controllers\Web\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Web\Portal\OrderController as PortalOrderController;
use App\Http\Controllers\Web\Portal\ProductController as PortalProductController;
use App\Http\Controllers\Web\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\Web\Portal\ServiceController as PortalServiceController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\ServiceController;
use App\Http\Controllers\Web\SupplierController;
use App\Http\Controllers\Web\SupplierOperationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/portal');

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])
    ->middleware(['guest', 'throttle:5,1'])
    ->name('login.store');

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::prefix('portal')->name('portal.')->middleware(['auth', 'active'])->group(function () {
    Route::get('/', PortalDashboardController::class)->name('dashboard');

    Route::get('/products', [PortalProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [PortalProductController::class, 'show'])->whereNumber('product')->name('products.show');
    Route::post('/products/{product}/cart', [PortalProductController::class, 'addToCart'])->whereNumber('product')->name('products.cart.store');

    Route::get('/cart', [PortalCartController::class, 'index'])->name('cart.index');
    Route::patch('/cart/{cartItem}', [PortalCartController::class, 'update'])->whereNumber('cartItem')->name('cart.update');
    Route::delete('/cart/{cartItem}', [PortalCartController::class, 'destroy'])->whereNumber('cartItem')->name('cart.destroy');
    Route::post('/cart/checkout', [PortalCartController::class, 'checkout'])->middleware('throttle:5,1')->name('cart.checkout');

    Route::get('/orders', [PortalOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [PortalOrderController::class, 'show'])->whereNumber('order')->name('orders.show');

    Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [PortalInvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
    Route::post('/invoices/{invoice}/payment', [PortalInvoiceController::class, 'pay'])->whereNumber('invoice')->middleware('throttle:10,1')->name('invoices.payment');
    Route::post('/invoices/{invoice}/cancel-renewal', [PortalInvoiceController::class, 'cancelRenewal'])->whereNumber('invoice')->middleware('throttle:5,1')->name('invoices.renewal.cancel');

    Route::get('/services', [PortalServiceController::class, 'index'])->name('services.index');
    Route::get('/services/{service}', [PortalServiceController::class, 'show'])->whereNumber('service')->name('services.show');
    Route::post('/services/{service}/renewal', [PortalServiceController::class, 'renew'])->whereNumber('service')->middleware('throttle:5,1')->name('services.renewal');
    Route::patch('/services/{service}/auto-renew', [PortalServiceController::class, 'updateAutoRenew'])->whereNumber('service')->name('services.auto-renew');

    Route::get('/profile', [PortalProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [PortalProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [PortalProfileController::class, 'updatePassword'])->middleware('throttle:5,1')->name('profile.password');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'admin'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::patch('/products/{product}/toggle', [ProductController::class, 'toggle'])->name('products.toggle');
    Route::post('/product-groups', [ProductController::class, 'storeGroup'])->name('product-groups.store');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->name('invoices.pay');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');

    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('throttle:supplier-sensitive')->name('suppliers.store');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->middleware('throttle:supplier-sensitive')->name('suppliers.update');
    Route::post('/suppliers/{supplier}/test', [SupplierController::class, 'test'])->whereNumber('supplier')->middleware('throttle:supplier-sensitive')->name('suppliers.test');
    Route::post('/suppliers/{supplier}/catalog-sync', [SupplierController::class, 'sync'])->whereNumber('supplier')->middleware('throttle:supplier-sensitive')->name('suppliers.catalog-sync');
    Route::get('/suppliers/{supplier}/catalog', [SupplierController::class, 'catalog'])->whereNumber('supplier')->name('suppliers.catalog');
    Route::post('/suppliers/{supplier}/catalog-import', [SupplierController::class, 'importCatalog'])->whereNumber('supplier')->middleware('throttle:supplier-sensitive')->name('suppliers.catalog-import');
    Route::put('/suppliers/{supplier}/mappings', [SupplierController::class, 'mappings'])->whereNumber('supplier')->middleware('throttle:supplier-sensitive')->name('suppliers.mappings');

    Route::get('/supplier-operations', [SupplierOperationController::class, 'index'])->name('supplier-operations.index');
    Route::post('/supplier-operations/{supplierOperation}/resume-credit', [SupplierOperationController::class, 'resumeCredit'])->whereNumber('supplierOperation')->middleware('throttle:5,1')->name('supplier-operations.resume-credit');
    Route::post('/supplier-operations/{supplierOperation}/manual-attestation', [SupplierOperationController::class, 'manualAttestation'])->whereNumber('supplierOperation')->middleware('throttle:supplier-sensitive')->name('supplier-operations.attest-payment');
    Route::post('/supplier-operations/{supplierOperation}/recover-poll', [SupplierOperationController::class, 'recoverPoll'])->whereNumber('supplierOperation')->middleware('throttle:5,1')->name('supplier-operations.recover-poll');
    Route::post('/supplier-operations/{supplierOperation}/reconcile-host', [SupplierOperationController::class, 'reconcileHost'])->whereNumber('supplierOperation')->middleware('throttle:5,1')->name('supplier-operations.reconcile-host');

    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('/finance/adjust', [FinanceController::class, 'adjust'])->name('finance.adjust');
});

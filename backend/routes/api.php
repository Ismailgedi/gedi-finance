<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessReportController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialYearCloseController;
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReportExcelController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupportContactController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::get('/me', [AuthController::class, 'user'])->middleware(['web', 'auth:web']);
Route::get('/user', [AuthController::class, 'user'])->middleware(['web', 'auth:web']);
Route::post('/login', [AuthController::class, 'login'])->middleware('web');
// Read-only, unauthenticated - the login page's "Forgot Password" recovery
// screen's "Contact your Administrator" option. Never a password-reset
// capability of any kind (see SupportContactController's own doc comment).
Route::get('/support-contact', [SupportContactController::class, 'show'])->middleware('web');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('web');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('web');
Route::post('/logout', [AuthController::class, 'logout'])->middleware(['web', 'auth:web']);
Route::put('/profile', [AuthController::class, 'updateProfile'])->middleware(['web', 'auth:web']);
Route::put('/password', [AuthController::class, 'updatePassword'])->middleware(['web', 'auth:web']);

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
|
| Every route below is also gated by a Spatie permission - Super Admin
| always has every permission that exists (see DatabaseSeeder), so
| nothing here is Super-Admin-specific; "permission:a|b" means "either
| permission a or b grants access" (Spatie's own OR syntax). A request
| that fails a permission check gets a 403 (Spatie\Permission's
| UnauthorizedException - routes/web.php's bootstrap/app.php config
| already renders exceptions as JSON for api/* requests).
|
| A handful of routes share a controller with another, differently-
| scoped action (e.g. PersonController::index() vs ::show(), which
| exposes financial balances index() doesn't) - those are gated
| per-action here rather than per-controller, intentionally.
*/
Route::middleware(['web', 'auth:web', 'password.changed'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->middleware('permission:view_dashboard');

    Route::get('/people', [PersonController::class, 'index'])->middleware('permission:view_customers|view_suppliers');
    Route::post('/people', [PersonController::class, 'store'])->middleware('permission:manage_customers|manage_suppliers');
    // show() includes each person's financial balances (receivable/loan
    // given/loan received/other) - unlike the plain contact list above,
    // this is financial information, not just a directory lookup.
    Route::get('/people/{person}', [PersonController::class, 'show'])->middleware('permission:manage_transactions');
    Route::put('/people/{person}', [PersonController::class, 'update'])->middleware('permission:manage_customers|manage_suppliers');
    Route::post('/people/{person}/payments', [PersonController::class, 'receivePayment'])->middleware('permission:manage_transactions');

    // Also reachable by create_sales/create_purchases - see
    // AccountController::index()'s own doc comment for why (completing a
    // cash sale/purchase needs an account to pick from; current_balance
    // is still only attached for a real view_accounts holder).
    Route::get('/accounts', [AccountController::class, 'index'])->middleware('permission:view_accounts|create_sales|create_purchases');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->middleware('permission:manage_accounts');

    Route::get('/transactions', [TransactionController::class, 'index'])->middleware('permission:view_transactions');
    Route::post('/transactions', [TransactionController::class, 'store'])->middleware('permission:manage_transactions');
    Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt'])->middleware('permission:view_transactions');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->middleware('permission:view_transactions');

    Route::get('/sales', [SaleController::class, 'index'])->middleware('permission:view_sales');
    Route::post('/sales', [SaleController::class, 'store'])->middleware('permission:create_sales');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->middleware('permission:view_sales');
    Route::post('/sales/{sale}/payments', [SaleController::class, 'payment'])->middleware('permission:manage_transactions');
    Route::post('/sales/{sale}/void', [SaleController::class, 'void'])->middleware('permission:void_sales');

    // Routine returns stay open to whoever can create a sale, unlike void -
    // see SaleReturnService's own doc comment for why they're a different,
    // bounded operation.
    Route::post('/sale-returns', [SaleReturnController::class, 'store'])->middleware('permission:create_sales');

    Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('permission:view_purchases');
    Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('permission:create_purchases');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('permission:view_purchases');
    Route::post('/purchases/{purchase}/payments', [PurchaseController::class, 'payment'])->middleware('permission:manage_transactions');
    Route::post('/purchases/{purchase}/void', [PurchaseController::class, 'void'])->middleware('permission:void_purchases');

    Route::post('/purchase-returns', [PurchaseReturnController::class, 'store'])->middleware('permission:create_purchases');

    Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:view_suppliers');
    Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('permission:manage_suppliers');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:view_suppliers');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:manage_suppliers');
    Route::post('/suppliers/{supplier}/payments', [SupplierController::class, 'pay'])->middleware('permission:manage_transactions');

    Route::get('/products', [ProductController::class, 'index'])->middleware('permission:view_products');
    Route::post('/products', [ProductController::class, 'store'])->middleware('permission:manage_products');
    Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('permission:view_products');
    Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('permission:manage_products');
    Route::post('/products/{product}/units', [ProductController::class, 'storeUnit'])->middleware('permission:manage_products');

    Route::get('/inventory-adjustments', [InventoryAdjustmentController::class, 'index'])->middleware('permission:view_inventory');
    Route::post('/inventory-adjustments', [InventoryAdjustmentController::class, 'store'])->middleware('permission:manage_inventory');

    Route::get('/product-categories', [ProductCategoryController::class, 'index'])->middleware('permission:view_products');
    Route::post('/product-categories', [ProductCategoryController::class, 'store'])->middleware('permission:manage_products');

    Route::get('/categories', [CategoryController::class, 'index'])->middleware('permission:view_transactions');
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:manage_transactions');

    Route::get('/units', [UnitController::class, 'index'])->middleware('permission:view_products');
    Route::post('/units', [UnitController::class, 'store'])->middleware('permission:manage_products');

    Route::get('/reports/business-summary', [BusinessReportController::class, 'summary'])->middleware('permission:view_financial_reports');
    Route::get('/reports/sales', [BusinessReportController::class, 'sales'])->middleware('permission:view_sales_reports');
    Route::get('/reports/purchases', [BusinessReportController::class, 'purchases'])->middleware('permission:view_purchase_reports');
    Route::get('/reports/profit', [BusinessReportController::class, 'profit'])->middleware('permission:view_financial_reports');
    Route::get('/reports/customer-receivables', [BusinessReportController::class, 'customerReceivables'])->middleware('permission:view_financial_reports');
    Route::get('/reports/supplier-payables', [BusinessReportController::class, 'supplierPayables'])->middleware('permission:view_financial_reports');
    // Shared by the Products/Inventory page (view_inventory - everyone who
    // can see stock) and the Business Reports hub's Inventory tab
    // (view_inventory_reports - the $-valued report) - same underlying
    // data, two different legitimate reasons to read it.
    Route::get('/reports/inventory', [BusinessReportController::class, 'inventory'])->middleware('permission:view_inventory|view_inventory_reports');
    Route::get('/reports/business-position', [BusinessReportController::class, 'businessPosition'])->middleware('permission:view_financial_reports');

    Route::get('/reports/transactions/excel', [ReportExcelController::class, 'transactions'])->middleware('permission:view_financial_reports');
    Route::get('/reports/business-position/excel', [ReportExcelController::class, 'businessPosition'])->middleware('permission:view_financial_reports');
    Route::get('/reports/sales/excel', [ReportExcelController::class, 'sales'])->middleware('permission:view_sales_reports');
    Route::get('/reports/purchases/excel', [ReportExcelController::class, 'purchases'])->middleware('permission:view_purchase_reports');
});

Route::middleware(['web', 'auth:web', 'password.changed', 'permission:manage_users'])->prefix('admin')->group(function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
});

Route::middleware(['web', 'auth:web', 'password.changed', 'permission:manage_financial_year'])->prefix('admin')->group(function (): void {
    Route::post('/financial-years/{year}/close', [FinancialYearCloseController::class, 'close']);
    Route::post('/financial-years/{year}/reopen', [FinancialYearCloseController::class, 'reopen']);
});

Route::middleware(['web', 'auth:web', 'password.changed', 'permission:manage_opening_balances'])->prefix('admin')->group(function (): void {
    Route::get('/opening-balance', [OpeningBalanceController::class, 'show']);
    Route::post('/opening-balance', [OpeningBalanceController::class, 'save']);
    Route::post('/opening-balance/lock', [OpeningBalanceController::class, 'lock']);
    Route::post('/opening-balance/reopen', [OpeningBalanceController::class, 'reopen']);
});

Route::middleware(['web', 'auth:web', 'password.changed', 'permission:view_audit_logs'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});

Route::middleware(['web'])->get('/csrf-token', function () {
    $token = csrf_token();

    return response()->json(['message' => 'CSRF cookie set'])
        ->withCookie(cookie(
            'XSRF-TOKEN',
            $token,
            120,
            '/',
            null,
            false,
            false,
            false,
            'lax'
        ));
});

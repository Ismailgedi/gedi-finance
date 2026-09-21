<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportExcelController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::get('/me', [AuthController::class, 'user'])->middleware(['web', 'auth:web']);
Route::get('/user', [AuthController::class, 'user'])->middleware(['web', 'auth:web']);
Route::post('/login', [AuthController::class, 'login'])->middleware('web');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('web');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('web');
Route::post('/logout', [AuthController::class, 'logout'])->middleware(['web', 'auth:web']);
Route::put('/profile', [AuthController::class, 'updateProfile'])->middleware(['web', 'auth:web']);
Route::put('/password', [AuthController::class, 'updatePassword'])->middleware(['web', 'auth:web']);

Route::middleware(['web', 'auth:web', 'password.changed'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class);

    Route::get('/people', [PersonController::class, 'index']);
    Route::post('/people', [PersonController::class, 'store']);
    Route::get('/people/{person}', [PersonController::class, 'show']);
    Route::put('/people/{person}', [PersonController::class, 'update']);
    Route::post('/people/{person}/payments', [PersonController::class, 'receivePayment']);

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::put('/accounts/{account}', [AccountController::class, 'update']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);

    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::post('/sales/{sale}/payments', [SaleController::class, 'payment']);
    Route::post('/sales/{sale}/void', [SaleController::class, 'void']);

    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
    Route::post('/purchases/{purchase}/payments', [PurchaseController::class, 'payment']);
    Route::post('/purchases/{purchase}/void', [PurchaseController::class, 'void']);

    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::post('/suppliers/{supplier}/payments', [SupplierController::class, 'pay']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::post('/products/{product}/units', [ProductController::class, 'storeUnit']);

    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);

    Route::get('/units', [UnitController::class, 'index']);
    Route::post('/units', [UnitController::class, 'store']);

    Route::get('/loans', [LoanController::class, 'index']);

    Route::get('/reports/business-summary', [BusinessReportController::class, 'summary']);
    Route::get('/reports/sales', [BusinessReportController::class, 'sales']);
    Route::get('/reports/purchases', [BusinessReportController::class, 'purchases']);
    Route::get('/reports/profit', [BusinessReportController::class, 'profit']);
    Route::get('/reports/customer-receivables', [BusinessReportController::class, 'customerReceivables']);
    Route::get('/reports/supplier-payables', [BusinessReportController::class, 'supplierPayables']);
    Route::get('/reports/inventory', [BusinessReportController::class, 'inventory']);

    Route::get('/reports/transactions/excel', [ReportExcelController::class, 'transactions']);
    Route::get('/reports/sales/excel', [ReportExcelController::class, 'sales']);
    Route::get('/reports/purchases/excel', [ReportExcelController::class, 'purchases']);
});

Route::middleware(['web', 'auth:web', 'password.changed', 'role:Super Admin'])->prefix('admin')->group(function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
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

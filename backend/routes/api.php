<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\Admin\UserController;
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

    Route::get('/accounts', [AccountController::class, 'index']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);

    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::post('/sales/{sale}/payments', [SaleController::class, 'payment']);

    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
    Route::post('/purchases/{purchase}/payments', [PurchaseController::class, 'payment']);

    Route::get('/loans', [LoanController::class, 'index']);

    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/purchases', [ReportController::class, 'purchases']);
    Route::get('/reports/inventory', [ReportController::class, 'inventory']);
    Route::get('/reports/receivables', [ReportController::class, 'receivables']);
    Route::get('/reports/payables', [ReportController::class, 'payables']);
    Route::get('/reports/income-expenses', [ReportController::class, 'incomeExpenses']);
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

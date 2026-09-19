<?php

namespace App\Http\Controllers;

use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(BalanceService $balances): JsonResponse
    {
        $start = now()->startOfDay();
        $end = now()->endOfDay();

        $today = DB::table('transactions')
            ->where('status', 'posted')
            ->whereBetween('transaction_date', [$start, $end])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'customer_payment' THEN amount ELSE 0 END), 0) AS customer_payments")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'cash_sale' THEN amount ELSE 0 END), 0) AS cash_sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit_sale' THEN amount ELSE 0 END), 0) AS credit_sales")
            ->first();

        return response()->json([
            'today' => [
                'income' => number_format((float) $today->income, 2, '.', ''),
                'expense' => number_format((float) $today->expense, 2, '.', ''),
                'customer_payments' => number_format((float) $today->customer_payments, 2, '.', ''),
                'cash_sales' => number_format((float) $today->cash_sales, 2, '.', ''),
                'credit_sales' => number_format((float) $today->credit_sales, 2, '.', ''),
            ],
            'receivables' => $balances->receivablesSummary(),
        ]);
    }
}

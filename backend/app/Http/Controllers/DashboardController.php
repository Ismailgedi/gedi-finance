<?php

namespace App\Http\Controllers;

use App\Services\BalanceService;
use App\Services\BusinessReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(BalanceService $balances, BusinessReportService $businessReports): JsonResponse
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

        // Reuses BusinessReportService rather than re-deriving sales/purchase/
        // profit totals here, so the dashboard's "today" figures can never
        // drift from what the Business Reports hub shows for the same day.
        // Its receivables/payables are balance snapshots (not date-filtered),
        // so passing range=today only scopes the flow figures below.
        $todaySummary = $businessReports->summary('today', null, null);

        // Loans are tracked separately from customer/supplier credit (a
        // person can owe money either because they bought on credit or
        // because they were lent money - these must not be summed
        // together). Computed here as a single grouped aggregate over ALL
        // posted loan transactions so the figure doesn't depend on how many
        // pages of /api/transactions the frontend happens to have fetched.
        $loanBalances = DB::table('transactions')
            ->where('status', 'posted')
            ->whereIn('type', ['loan_given', 'loan_repayment', 'loan_received', 'loan_payment'])
            ->whereNotNull('person_id')
            ->selectRaw('person_id')
            ->selectRaw("SUM(CASE
                    WHEN type = 'loan_given' THEN amount
                    WHEN type = 'loan_repayment' THEN -amount
                    WHEN type = 'loan_received' THEN -amount
                    WHEN type = 'loan_payment' THEN amount
                    ELSE 0
                END) AS net_balance")
            ->groupBy('person_id')
            ->get();

        $outstandingLoansGiven = $loanBalances->sum(fn ($row) => max(0, (float) $row->net_balance));
        $outstandingLoansReceived = $loanBalances->sum(fn ($row) => max(0, -(float) $row->net_balance));

        return response()->json([
            'today' => [
                'income' => number_format((float) $today->income, 2, '.', ''),
                'expense' => number_format((float) $today->expense, 2, '.', ''),
                'customer_payments' => number_format((float) $today->customer_payments, 2, '.', ''),
                'cash_sales' => number_format((float) $today->cash_sales, 2, '.', ''),
                'credit_sales' => number_format((float) $today->credit_sales, 2, '.', ''),
                'sales_total' => $todaySummary['sales']['today_sales'],
                'purchases_total' => $todaySummary['purchases']['purchase_value'],
                'gross_profit' => $todaySummary['financial']['gross_profit'],
            ],
            'receivables' => $balances->receivablesSummary() + [
                // Customer-only (credit_sale/customer_payment), excluding
                // loans/debts - what the business is actually owed for
                // goods sold on credit. See BalanceService::receivablesBreakdown().
                'customer_outstanding' => $todaySummary['credit']['accounts_receivable'],
            ],
            'payables' => [
                // Supplier-only (purchases not yet paid for) - not to be
                // confused with loans the business has borrowed.
                'outstanding' => $todaySummary['credit']['accounts_payable'],
            ],
            'loans' => [
                'outstanding_given' => number_format((float) $outstandingLoansGiven, 2, '.', ''),
                'outstanding_received' => number_format((float) $outstandingLoansReceived, 2, '.', ''),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\BusinessCapitalService;
use App\Services\BusinessReportService;
use App\Services\OpeningBalanceService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(
        BusinessReportService $businessReports,
        BusinessCapitalService $capital,
        OpeningBalanceService $openingBalance,
    ): JsonResponse {
        // Reuses BusinessReportService rather than re-deriving sales/purchase/
        // profit totals here, so the dashboard's "today" figures can never
        // drift from what the Business Reports hub shows for the same day.
        $todaySummary = $businessReports->summary('today', null, null);

        // The broader "current period" (whatever BusinessReportService's own
        // default range resolves to - see resolveRange()) - deliberately a
        // SEPARATE object from `today` above so the frontend never mixes a
        // same-day figure with a period-to-date one under one label. Reuses
        // the exact same summary() call (and therefore the exact same
        // profit() definition - gross profit - operating expenses + Other
        // Income) rather than a second profit calculation.
        $currentPeriodSummary = $businessReports->summary(null, null, null);

        // The Business Position report's own default snapshot (see
        // BusinessReportController::businessPosition()) - the single
        // authoritative source for every asset/liability bucket below
        // (customer receivables, other receivables, loans given/received,
        // supplier payables/credits, customer credits), including opening
        // balances, which the dashboard's own loan aggregation used to
        // compute by hand and silently omit. Never recomputed here with a
        // second query shape.
        [$positionFrom, $positionTo] = $businessReports->resolveRange('this_year', null, null);
        $position = $capital->position($positionFrom, $positionTo);

        // Opening Balance's own status (draft/locked, and as_of_date) -
        // reused directly from the one record OpeningBalanceService already
        // reads (see OpeningBalanceController::show()), never a second query
        // against the same table.
        $openingBalanceRecord = $openingBalance->current();

        return response()->json([
            'today' => [
                'sales_total' => $todaySummary['sales']['today_sales'],
                'purchases_total' => $todaySummary['purchases']['purchase_value'],
                'gross_profit' => $todaySummary['financial']['gross_profit'],
            ],
            // Customer-only (credit_sale/customer_payment), excluding loans/
            // debts - what the business is actually owed for goods sold on
            // credit. See BalanceService::receivablesBreakdown(). Unlike
            // before, nothing else is exposed here: the old increases/
            // decreases/outstanding trio mixed loans, other receivables and
            // customer receivables into one figure and is gone for good -
            // see `money_position` below for the correctly-scoped buckets.
            'receivables' => [
                'customer_outstanding' => $todaySummary['credit']['accounts_receivable'],
            ],
            'payables' => [
                // Supplier-only (purchases not yet paid for) - not to be
                // confused with loans the business has borrowed.
                'outstanding' => $todaySummary['credit']['accounts_payable'],
            ],
            // Sourced from the same Business Position snapshot as
            // money_position below (BusinessCapitalService::position(),
            // which already includes opening_balance_loan_given/
            // opening_balance_loan_received) - no longer a hand-written SQL
            // aggregate that silently excluded them.
            'loans' => [
                'outstanding_given' => $position['assets']['loans_given'],
                'outstanding_received' => $position['liabilities']['loans_received'],
            ],
            // MONEY OWED TO GEDI / MONEY GEDI OWES - every bucket sourced
            // from the one Business Position snapshot above, never a second
            // calculation. `as_of` is that snapshot's own end date. Cash/
            // Bank/EVC/eDahab/JEEB accounts are deliberately NOT repeated
            // here - the frontend already fetches GET /api/accounts (backed
            // by BalanceService::accountBalance()) for that.
            'money_position' => [
                'as_of' => $position['period']['to'],
                'owed_to_gedi' => [
                    'customer_receivables' => $position['assets']['customer_receivables'],
                    'other_receivables' => $position['assets']['other_receivables'],
                    'loans_given' => $position['assets']['loans_given'],
                    'supplier_credits' => $position['assets']['supplier_credits'],
                ],
                'owed_by_gedi' => [
                    'supplier_payables' => $position['liabilities']['supplier_payables'],
                    'loans_received' => $position['liabilities']['loans_received'],
                    'customer_credits' => $position['liabilities']['customer_credits'],
                ],
            ],
            // INVENTORY - the exact same figures BusinessReportService's own
            // summary() already computes (via InventoryService), never a
            // second valuation method.
            'inventory' => $todaySummary['inventory'],
            // PERFORMANCE - `today` reuses the block above; `current_period`
            // is a distinctly-labeled, separate figure (see its own `period`
            // sub-key) so the two are never mixed on screen.
            'performance' => [
                'today' => [
                    'sales' => $todaySummary['sales']['today_sales'],
                    'purchases' => $todaySummary['purchases']['purchase_value'],
                    'gross_profit' => $todaySummary['financial']['gross_profit'],
                ],
                'current_period' => [
                    'period' => $currentPeriodSummary['period'],
                    'gross_profit' => $currentPeriodSummary['financial']['gross_profit'],
                    'operating_expenses' => $currentPeriodSummary['financial']['total_expenses'],
                    'other_income' => $currentPeriodSummary['financial']['other_income'],
                    'net_profit' => $currentPeriodSummary['financial']['net_profit'],
                ],
            ],
            // WHAT NEEDS ATTENTION - every value here is read from a service
            // that already computes it elsewhere (summary()'s inventory/
            // credit sections, the Business Position snapshot's own
            // is_closed/closure, and OpeningBalanceService's current()) -
            // no new alert logic.
            'attention' => [
                'low_stock_products' => $todaySummary['inventory']['low_stock_products'],
                'stock_requiring_attention' => $todaySummary['inventory']['stock_requiring_attention'],
                'overdue_customer_balance' => $todaySummary['credit']['overdue_customer_balance'],
                'financial_year' => [
                    'is_closed' => $position['is_closed'],
                    'closure' => $position['closure'],
                ],
                'opening_balance' => [
                    'configured' => $openingBalanceRecord !== null,
                    'status' => $openingBalanceRecord?->status,
                    'as_of_date' => $openingBalanceRecord?->as_of_date?->toDateString(),
                ],
            ],
        ]);
    }
}

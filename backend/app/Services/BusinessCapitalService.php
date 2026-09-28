<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FinancialYearClose;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Business Position / Year-End report: a point-in-time balance sheet
 * (Assets = Liabilities + Equity) built entirely on top of the existing
 * transaction ledger, sale/purchase totals and inventory movements -
 * nothing here is a second bookkeeping system.
 *
 * Every balance figure the rest of the app already exposes (BalanceService,
 * BusinessReportService) is a *current* snapshot with no date bound, because
 * nothing before this needed one. A year-end report is inherently a
 * point-in-time question ("what did we own/owe as of 31 Dec"), so this
 * class adds date-bounded variants of the same query shapes (same columns,
 * same signed-effect logic, same tables) rather than changing those
 * existing methods and risking today's dashboard/reports. The one genuinely
 * shared calculation - period profit/loss - is NOT duplicated: it calls
 * BusinessReportService::profit() directly.
 */
class BusinessCapitalService
{
    /**
     * Before any transaction in this system could possibly exist - used as
     * the lower bound for "since the business began" cumulative figures.
     */
    private const INCEPTION = '1970-01-01';

    public function __construct(
        private readonly BusinessReportService $businessReports,
    ) {
    }

    /**
     * $startDate/$endDate are already-resolved YYYY-MM-DD dates - callers
     * resolve the requested range via BusinessReportService::resolveRange()
     * first (the same range/from/to logic every other business report
     * uses), so this class does not duplicate date-range parsing.
     *
     * If [$startDate, $endDate] exactly matches a CLOSED financial year, the
     * frozen snapshot recorded at close time is returned as-is instead of
     * being recomputed - that snapshot IS the historical record, and must
     * not silently change just because later data entry (a backdated
     * transaction, a void) altered what a live recompute would now produce.
     * See FinancialYearCloseService::close().
     */
    public function position(string $startDate, string $endDate): array
    {
        $closedForPeriod = FinancialYearClose::query()
            ->where('status', 'closed')
            ->whereDate('period_start_date', $startDate)
            ->whereDate('period_end_date', $endDate)
            ->first();

        if ($closedForPeriod) {
            return $this->fromClosedSnapshot($closedForPeriod);
        }

        $dayBeforeStart = date('Y-m-d', strtotime($startDate . ' -1 day'));

        // Opening equity's fallback chain, in order: (1) a closed year
        // ending exactly the day before this period starts - its frozen
        // closing_equity IS this period's opening equity, full stop, never
        // recomputed; (2) failing that, the live cumulative-since-inception
        // formula, which itself now folds in the locked OpeningBalance's
        // own validated `opening_equity` figure from its as_of_date onward
        // (see cumulativeEquityAsOf()/openingEquityAsOf()) - so a business
        // that starts using Gedi Finance mid-history still gets a correct
        // opening equity for its very first (and every later, until a year
        // is actually closed) period, without that figure ever being
        // computed twice.
        $precedingClose = FinancialYearClose::query()
            ->where('status', 'closed')
            ->whereDate('period_end_date', $dayBeforeStart)
            ->first();

        $openingEquity = $precedingClose
            ? (float) $precedingClose->closing_equity
            : $this->cumulativeEquityAsOf($dayBeforeStart);

        $periodProfitReport = $this->businessReports->profit(null, $startDate, $endDate);
        $periodProfit = (float) $periodProfitReport['net_profit'];
        // Breakdown only - already included in $periodProfit above via
        // BusinessReportService::profit(), never added a second time here.
        $periodOtherIncome = (float) $periodProfitReport['other_income'];
        $contributions = $this->capitalMovement('owner_contribution', $startDate, $endDate);
        $withdrawals = $this->capitalMovement('owner_withdrawal', $startDate, $endDate);

        // The one period whose own as_of_date falls WITHIN [startDate,
        // endDate] (rather than before it) is a special case: its assets
        // already include the opening balance's postings (dated on
        // as_of_date, so whereDate('<=', endDate) picks them up), but
        // $openingEquity above is deliberately 0 for it (nothing existed
        // BEFORE as_of_date) and profit()/capitalMovement() never touch
        // opening_balance_* types by design - so without this, that one
        // period's own reconciliation would be short by exactly its
        // opening_equity. Every later period never hits this branch: by
        // then as_of_date is in the past, already folded into
        // $openingEquity via cumulativeEquityAsOf()/openingEquityAsOf().
        $openingBalanceContribution = $this->openingBalanceContributionWithinPeriod($startDate, $endDate);

        $closingEquity = round($openingEquity + $periodProfit + $contributions - $withdrawals + $openingBalanceContribution, 2);

        // Computed once and reused for both the detailed sections
        // (cash_accounts/inventory_breakdown, for the "what do we
        // actually have" view) and the summary totals below - the summary
        // figures are sums of these same rows, never a second query.
        $cashAccounts = $this->cashAccountsAsOf($endDate);
        $inventoryBreakdown = $this->inventoryBreakdown($endDate);
        $inventoryAtCost = round(
            array_sum(array_map(fn (array $row) => (float) $row['inventory_value'], $inventoryBreakdown)),
            2,
        );

        // Computed once per entity (never netted across different
        // customers/suppliers - see customerBuckets()/supplierBuckets()'s
        // own doc comment) and shared between assetsAsOf() and
        // liabilitiesAsOf() below - a customer with a negative balance is a
        // real Customer Credit (liability) even while another customer
        // still owes money, and a supplier with a negative balance is a
        // real Supplier Credit (asset) even while another supplier is still
        // owed - see SaleReturnService/PurchaseReturnService.
        $customerBuckets = $this->customerBuckets($endDate);
        $supplierBuckets = $this->supplierBuckets($endDate);

        $assets = $this->assetsAsOf($endDate, (float) $cashAccounts['total'], $inventoryAtCost, $customerBuckets, $supplierBuckets);
        $liabilities = $this->liabilitiesAsOf($endDate, $supplierBuckets, $customerBuckets);

        $assetsTotal = round(array_sum($assets), 2);
        $liabilitiesTotal = round(array_sum($liabilities), 2);
        $assetsMinusLiabilities = round($assetsTotal - $liabilitiesTotal, 2);

        return [
            'period' => [
                'from' => $startDate,
                'to' => $endDate,
                'note' => "Figures reflect the business position as of {$endDate}, for the financial year {$startDate} to {$endDate}.",
            ],
            'is_closed' => false,
            'closure' => null,
            'equity' => [
                'opening_equity' => $this->money($openingEquity),
                'profit_or_loss' => $this->money($periodProfit),
                'other_income' => $this->money($periodOtherIncome),
                'owner_contributions' => $this->money($contributions),
                'owner_withdrawals' => $this->money($withdrawals),
                'closing_equity' => $this->money($closingEquity),
            ],
            'cash_accounts' => $cashAccounts['accounts'],
            'inventory_breakdown' => $inventoryBreakdown,
            'assets' => [
                'cash_and_bank' => $this->money($assets['cash_and_bank']),
                'customer_receivables' => $this->money($assets['customer_receivables']),
                'other_receivables' => $this->money($assets['other_receivables']),
                'inventory_at_cost' => $this->money($assets['inventory_at_cost']),
                'loans_given' => $this->money($assets['loans_given']),
                'supplier_credits' => $this->money($assets['supplier_credits']),
                'total' => $this->money($assetsTotal),
            ],
            'liabilities' => [
                'supplier_payables' => $this->money($liabilities['supplier_payables']),
                'loans_received' => $this->money($liabilities['loans_received']),
                'customer_credits' => $this->money($liabilities['customer_credits']),
                'total' => $this->money($liabilitiesTotal),
            ],
            'check' => [
                'assets_minus_liabilities' => $this->money($assetsMinusLiabilities),
                'closing_equity' => $this->money($closingEquity),
                'matches' => abs($assetsMinusLiabilities - $closingEquity) < 0.01,
            ],
        ];
    }

    /**
     * Reconstructs the exact position() response shape from a frozen
     * FinancialYearClose row, so the frontend never needs to know whether a
     * given period was live-computed or read from a close - only the
     * top-level is_closed flag differs.
     */
    private function fromClosedSnapshot(FinancialYearClose $record): array
    {
        $assets = $record->assets;
        $liabilities = $record->liabilities;

        // A year closed before Other Receivables/Supplier Credits/Customer
        // Credits existed has no such key in its frozen snapshot - shown as
        // zero rather than recomputed, since the frozen total above was
        // correctly computed without it at the time and must not silently
        // change now.
        $assets['other_receivables'] ??= '0.00';
        $assets['supplier_credits'] ??= '0.00';
        $liabilities['customer_credits'] ??= '0.00';

        $latestClosedYear = (int) FinancialYearClose::query()->where('status', 'closed')->max('financial_year');

        $closingEquity = (float) $record->closing_equity;
        $assetsTotal = (float) ($assets['total'] ?? 0);
        $liabilitiesTotal = (float) ($liabilities['total'] ?? 0);

        return [
            'period' => [
                'from' => $record->period_start_date->toDateString(),
                'to' => $record->period_end_date->toDateString(),
                'note' => "Financial year {$record->financial_year} is closed - figures are the frozen historical "
                    . "record from {$record->closed_at?->toDateString()}, not a live recalculation.",
            ],
            'is_closed' => true,
            'closure' => [
                'financial_year' => $record->financial_year,
                'status' => $record->status,
                'closed_by' => $record->closedBy?->name,
                'closed_at' => $record->closed_at?->toIso8601String(),
                'reopened_by' => $record->reopenedBy?->name,
                'reopened_at' => $record->reopened_at?->toIso8601String(),
                'is_latest_closed_year' => $latestClosedYear === $record->financial_year,
            ],
            'equity' => [
                'opening_equity' => $this->money((float) $record->opening_equity),
                'profit_or_loss' => $this->money((float) $record->profit_loss),
                'other_income' => $this->money((float) ($record->other_income ?? 0)),
                'owner_contributions' => $this->money((float) $record->owner_contributions),
                'owner_withdrawals' => $this->money((float) $record->owner_withdrawals),
                'closing_equity' => $this->money($closingEquity),
            ],
            // Frozen at close time (see FinancialYearCloseService::close())
            // - empty for a year closed before these were captured, rather
            // than falling back to a live recompute that could disagree
            // with the frozen totals above.
            'cash_accounts' => $record->cash_accounts ?? [],
            'inventory_breakdown' => $record->inventory_breakdown ?? [],
            'assets' => $assets,
            'liabilities' => $liabilities,
            'check' => [
                'assets_minus_liabilities' => $this->money($assetsTotal - $liabilitiesTotal),
                'closing_equity' => $this->money($closingEquity),
                'matches' => abs(($assetsTotal - $liabilitiesTotal) - $closingEquity) < 0.01,
            ],
        ];
    }

    /**
     * Cumulative owner equity from business inception through $date
     * (inclusive): all profit ever made plus all capital ever contributed,
     * minus all capital ever withdrawn. There is no separate "retained
     * earnings" ledger - this is computed fresh from the same posted
     * sales/expense/capital records everywhere else in the app already
     * reads from.
     */
    private function cumulativeEquityAsOf(string $date): float
    {
        if ($date < self::INCEPTION) {
            return 0.0;
        }

        $cumulativeProfit = (float) $this->businessReports->profit(null, self::INCEPTION, $date)['net_profit'];
        $contributions = $this->capitalMovement('owner_contribution', self::INCEPTION, $date);
        $withdrawals = $this->capitalMovement('owner_withdrawal', self::INCEPTION, $date);
        $openingEquity = $this->openingEquityAsOf($date);

        return round($openingEquity + $cumulativeProfit + $contributions - $withdrawals, 2);
    }

    /**
     * The locked OpeningBalance's own validated `opening_equity` figure,
     * folded straight into cumulative equity from its as_of_date onward -
     * see OpeningBalanceService's own doc comment. Returns 0 when no
     * OpeningBalance is locked, or when $date is before its as_of_date
     * (nothing to fold in yet). Never re-derives this from
     * opening_balance_* transactions/movements a second time: those are
     * already excluded from profit()/capitalMovement() above by type, and
     * summing them again here would double count exactly what this single
     * stored figure already represents.
     */
    private function openingEquityAsOf(string $date): float
    {
        $locked = DB::table('opening_balances')->where('status', 'locked')->first();

        if (!$locked) {
            return 0.0;
        }

        // Eloquent's date cast serializes as_of_date with the connection's
        // full datetime format when saved (e.g. "2026-01-01 00:00:00" on
        // SQLite), so a raw string comparison against a plain "Y-m-d" date
        // would wrongly treat the same calendar day as "earlier" - both
        // sides are normalized to Y-m-d before comparing.
        $asOfDate = Carbon::parse($locked->as_of_date)->format('Y-m-d');

        if ($date < $asOfDate) {
            return 0.0;
        }

        return (float) $locked->opening_equity;
    }

    /**
     * The locked OpeningBalance's own opening_equity, but only when its
     * as_of_date falls inside [$startDate, $endDate] - see position()'s
     * own comment for why this is a separate term from openingEquityAsOf().
     */
    private function openingBalanceContributionWithinPeriod(string $startDate, string $endDate): float
    {
        $locked = DB::table('opening_balances')->where('status', 'locked')->first();

        if (!$locked) {
            return 0.0;
        }

        $asOfDate = Carbon::parse($locked->as_of_date)->format('Y-m-d');

        if ($asOfDate >= $startDate && $asOfDate <= $endDate) {
            return (float) $locked->opening_equity;
        }

        return 0.0;
    }

    private function capitalMovement(string $type, string $from, string $to): float
    {
        return (float) DB::table('transactions')
            ->where('status', 'posted')
            ->where('type', $type)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->sum('amount');
    }

    /**
     * $cashAndBank/$inventoryAtCost are passed in already computed - by
     * cashAccountsAsOf()/inventoryBreakdown() respectively, both of which
     * position() also uses directly for the detailed per-account/
     * per-product sections - so this never re-derives them with a second
     * query shape that could disagree with the breakdown shown.
     *
     * $customerBuckets/$supplierBuckets are customerBuckets($asOf)/
     * supplierBuckets($asOf)'s own already-per-entity-clamped output -
     * never re-clamped here, since clamping a sum that's already been
     * clamped per entity is a no-op, and re-deriving it from a raw sum
     * would silently reintroduce the C6 netting bug this replaced.
     *
     * @return array{cash_and_bank: float, customer_receivables: float, other_receivables: float, inventory_at_cost: float, loans_given: float, supplier_credits: float}
     */
    private function assetsAsOf(
        string $asOf,
        float $cashAndBank,
        float $inventoryAtCost,
        array $customerBuckets,
        array $supplierBuckets,
    ): array {
        // debt_created/debt_payment: a receivable created and settled with
        // no sale, no inventory, and (for debt_created) no account effect -
        // see the Loans & Debts UI's own "Other Receivable" workflow. Kept
        // as its own bucket, never merged into customer_receivables (which
        // is strictly credit_sale/customer_payment) or loans_given (strictly
        // the loan_* types) - the three type-string sets are disjoint, so
        // summing this separately can never double-count either of them.
        $otherReceivables = (float) DB::table('transactions')
            ->where('status', 'posted')
            ->whereIn('type', ['debt_created', 'debt_payment', 'opening_balance_other_receivable'])
            ->whereDate('transaction_date', '<=', $asOf)
            ->sum('person_balance_effect');

        return [
            'cash_and_bank' => round($cashAndBank, 2),
            'customer_receivables' => round($customerBuckets['receivable'], 2),
            'other_receivables' => round(max(0.0, $otherReceivables), 2),
            'inventory_at_cost' => round($inventoryAtCost, 2),
            'loans_given' => round($this->loanBuckets($asOf)['given'], 2),
            // A purchase return that exceeds what's still owed to a
            // supplier leaves that supplier owing US - a real asset, the
            // mirror of Customer Credits below. See PurchaseReturnService.
            'supplier_credits' => round($supplierBuckets['credit'], 2),
        ];
    }

    /**
     * Every active account's balance as of $asOf - the "Cash & Money
     * Available" section (Cash/Bank/EVC/eDahab/JEEB are just individual
     * Account rows in this app; there's no separate provider concept to
     * special-case). Same date-bounded shape as accountBalanceAsOf(), just
     * returned per-account instead of summed away.
     *
     * @return array{accounts: array<int, array{id:int,name:string,type:string,balance:string}>, total: string}
     */
    private function cashAccountsAsOf(string $asOf): array
    {
        $accounts = Account::query()->where('is_active', true)->orderBy('name')->get();

        $rows = $accounts->map(fn (Account $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'balance' => $this->money($this->accountBalanceAsOf($account, $asOf)),
        ])->all();

        $total = array_sum(array_map(fn (array $row) => (float) $row['balance'], $rows));

        return ['accounts' => $rows, 'total' => $this->money($total)];
    }

    /**
     * Per-product inventory as of $asOf - the same cost-basis figures
     * assetsAsOf()'s inventory_at_cost is summed from, plus the most
     * recent physical stock count on or before $asOf (if any) shown
     * alongside as supporting evidence. The physical count is never fed
     * back into system_quantity/inventory_value here - only the count's
     * OWN resulting InventoryMovement (recorded once, when the count was
     * made - see InventoryAdjustmentService) ever does that, so a count
     * never silently overrides the accounting figures shown.
     *
     * @return array<int, array{product_id:int,product:string,sku:?string,unit:?string,system_quantity:string,physical_quantity:?string,physical_count_date:?string,difference:?string,unit_cost:?string,inventory_value:string}>
     */
    public function inventoryBreakdown(string $asOf): array
    {
        $products = Product::query()->where('is_active', true)->with('baseUnit')->orderBy('name')->get();

        $movementTotals = DB::table('inventory_movements')
            ->whereDate('occurred_at', '<=', $asOf)
            ->selectRaw('product_id')
            ->selectRaw('COALESCE(SUM(base_quantity), 0) AS quantity')
            ->selectRaw('COALESCE(SUM(total_cost), 0) AS value')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $latestCounts = InventoryAdjustment::query()
            ->whereDate('adjustment_date', '<=', $asOf)
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->get()
            ->unique('product_id')
            ->keyBy('product_id');

        $rows = $products->map(function (Product $product) use ($movementTotals, $latestCounts) {
            $movement = $movementTotals->get($product->id);
            $systemQuantity = (float) ($movement->quantity ?? 0);
            $value = round((float) ($movement->value ?? 0), 2);
            $unitCost = abs($systemQuantity) > 0.00005 ? round($value / $systemQuantity, 4) : null;

            $count = $latestCounts->get($product->id);

            return [
                'product_id' => $product->id,
                'product' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->baseUnit?->abbreviation ?: $product->baseUnit?->name,
                'system_quantity' => number_format($systemQuantity, 4, '.', ''),
                'physical_quantity' => $count ? number_format((float) $count->physical_quantity, 4, '.', '') : null,
                'physical_count_date' => $count?->adjustment_date?->toDateString(),
                'difference' => $count ? number_format((float) $count->quantity_difference, 4, '.', '') : null,
                'unit_cost' => $unitCost !== null ? number_format($unitCost, 4, '.', '') : null,
                'inventory_value' => $this->money($value),
            ];
        });

        // Only products with recorded inventory activity or count history -
        // a catalog entry that's never been purchased/counted has nothing
        // to show in a year-end inventory reconciliation.
        return $rows
            ->filter(fn (array $row) => abs((float) $row['system_quantity']) > 0.00005 || $row['physical_quantity'] !== null)
            ->values()
            ->all();
    }

    /**
     * @return array{supplier_payables: float, loans_received: float, customer_credits: float}
     */
    private function liabilitiesAsOf(string $asOf, array $supplierBuckets, array $customerBuckets): array
    {
        return [
            'supplier_payables' => round($supplierBuckets['payable'], 2),
            'loans_received' => round($this->loanBuckets($asOf)['received'], 2),
            // A sale return that exceeds what a customer still owes leaves
            // the business owing THEM - a real liability until it's spent
            // against a future sale or refunded. See SaleReturnService.
            'customer_credits' => round($customerBuckets['credit'], 2),
        ];
    }

    /**
     * Per-customer receivable/credit split, grouped and clamped ONE
     * CUSTOMER AT A TIME before summing - never netting one customer's
     * credit against another customer's receivable. Mirrors
     * BalanceService::receivablesBreakdown()'s own per-entity grouping
     * (the reference pattern) and loanBuckets() just above, which already
     * did this correctly.
     *
     * This replaced a version that summed every customer's
     * person_balance_effect together BEFORE clamping the single result -
     * which silently hid a real customer credit whenever another
     * customer's receivable outweighed it, and disagreed with
     * BalanceService::receivablesBreakdown() (used by BusinessReportService
     * ::summary(), and therefore the Dashboard's own receivables.
     * customer_outstanding) on the very same figure. See the accounting
     * audit's C6 finding.
     *
     * @return array{receivable: float, credit: float}
     */
    private function customerBuckets(string $asOf): array
    {
        $rows = DB::table('transactions')
            ->where('status', 'posted')
            ->whereIn('type', BalanceService::RECEIVABLE_TYPES)
            ->whereDate('transaction_date', '<=', $asOf)
            ->whereNotNull('person_id')
            ->selectRaw('person_id')
            ->selectRaw('SUM(person_balance_effect) AS balance')
            ->groupBy('person_id')
            ->get();

        return [
            'receivable' => (float) $rows->sum(fn ($row) => max(0.0, (float) $row->balance)),
            'credit' => (float) $rows->sum(fn ($row) => max(0.0, -(float) $row->balance)),
        ];
    }

    /**
     * Per-supplier payable/credit split - the same per-entity
     * clamp-then-sum pattern as customerBuckets(), for the same reason. No
     * type filter, exactly like BalanceService::supplierBalance() - every
     * type that moves supplier_balance_effect (Purchase/SupplierPayment/
     * PurchaseReturn/PurchaseReturnRefund) already contributes here with no
     * enum-list changes needed.
     *
     * @return array{payable: float, credit: float}
     */
    private function supplierBuckets(string $asOf): array
    {
        $rows = DB::table('transactions')
            ->where('status', 'posted')
            ->whereDate('transaction_date', '<=', $asOf)
            ->whereNotNull('supplier_id')
            ->selectRaw('supplier_id')
            ->selectRaw('SUM(supplier_balance_effect) AS balance')
            ->groupBy('supplier_id')
            ->get();

        return [
            'payable' => (float) $rows->sum(fn ($row) => max(0.0, (float) $row->balance)),
            'credit' => (float) $rows->sum(fn ($row) => max(0.0, -(float) $row->balance)),
        ];
    }

    /**
     * Mirrors DashboardController's loan-balance aggregation exactly (same
     * CASE expression, same given-vs-received bucketing), bounded to
     * transactions posted on or before $asOf.
     *
     * @return array{given: float, received: float}
     */
    private function loanBuckets(string $asOf): array
    {
        $rows = DB::table('transactions')
            ->where('status', 'posted')
            ->whereIn('type', ['loan_given', 'loan_repayment', 'loan_received', 'loan_payment', 'opening_balance_loan_given', 'opening_balance_loan_received'])
            ->whereNotNull('person_id')
            ->whereDate('transaction_date', '<=', $asOf)
            ->selectRaw('person_id')
            ->selectRaw("SUM(CASE
                    WHEN type = 'loan_given' THEN amount
                    WHEN type = 'loan_repayment' THEN -amount
                    WHEN type = 'loan_received' THEN -amount
                    WHEN type = 'loan_payment' THEN amount
                    WHEN type = 'opening_balance_loan_given' THEN amount
                    WHEN type = 'opening_balance_loan_received' THEN -amount
                    ELSE 0
                END) AS net_balance")
            ->groupBy('person_id')
            ->get();

        return [
            'given' => (float) $rows->sum(fn ($row) => max(0, (float) $row->net_balance)),
            'received' => (float) $rows->sum(fn ($row) => max(0, -(float) $row->net_balance)),
        ];
    }

    /**
     * Date-bounded variant of BalanceService::accountBalance() - identical
     * shape, with a transaction_date <= $asOf bound added.
     */
    private function accountBalanceAsOf(Account $account, string $asOf): float
    {
        $movement = (float) DB::table('transactions')
            ->where('account_id', $account->id)
            ->where('status', 'posted')
            ->whereDate('transaction_date', '<=', $asOf)
            ->sum('account_balance_effect');

        $destinationMovement = (float) DB::table('transactions')
            ->where('destination_account_id', $account->id)
            ->where('status', 'posted')
            ->whereDate('transaction_date', '<=', $asOf)
            ->sum('destination_account_effect');

        return (float) $account->opening_balance + $movement + $destinationMovement;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

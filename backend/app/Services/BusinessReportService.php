<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns the existing transaction/sale/purchase/inventory data into
 * management reports. Deliberately does not recompute figures another
 * service already owns (BalanceService for person/account/supplier
 * balances, InventoryService for stock/cost) - it queries posted records
 * and formats them.
 *
 * Two kinds of numbers appear throughout:
 *  - Period figures (sales, expenses, profit, collections) are flows and
 *    respect the requested date range.
 *  - Balance figures (account balances, receivables, payables, inventory
 *    value) are a snapshot of where things stand right now - a receivable
 *    or a stock level isn't "for last month", so these are not filtered by
 *    the date range, matching how the rest of the app already treats them
 *    (BalanceService/InventoryService take no date argument either).
 */
class BusinessReportService
{
    private const RANGES = ['today', 'this_week', 'this_month', 'last_month', 'this_year', 'custom'];

    public function __construct(
        private readonly BalanceService $balances,
        private readonly InventoryService $inventory,
    ) {
    }

    /**
     * Resolve a named range (or an explicit from/to pair for "custom")
     * into concrete dates. Never hardcodes a date - "today" always means
     * the day the request is made.
     *
     * @return array{0: string, 1: string, 2: string} [from, to, rangeUsed]
     */
    public function resolveRange(?string $range, ?string $from, ?string $to): array
    {
        $range = $range ?: (($from || $to) ? 'custom' : 'this_month');

        if (!in_array($range, self::RANGES, true)) {
            throw new InvalidArgumentException(
                'range must be one of: ' . implode(', ', self::RANGES) . '.'
            );
        }

        [$resolvedFrom, $resolvedTo] = match ($range) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'this_week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
            'this_month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            'last_month' => [
                now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'this_year' => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
            'custom' => $this->validateCustomRange($from, $to),
        };

        return [$resolvedFrom, $resolvedTo, $range];
    }

    private function validateCustomRange(?string $from, ?string $to): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->endOfMonth()->toDateString();

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new InvalidArgumentException('from/to must use YYYY-MM-DD.');
        }

        if ($from > $to) {
            throw new InvalidArgumentException('from must be on or before to.');
        }

        return [$from, $to];
    }

    /**
     * The management dashboard: FINANCIAL, CREDIT, INVENTORY, SALES and
     * PURCHASES sections. Period figures use [from, to]; balance figures
     * (accounts, receivables, payables, inventory) are current snapshots.
     */
    public function summary(?string $range, ?string $from, ?string $to): array
    {
        [$from, $to, $rangeUsed] = $this->resolveRange($range, $from, $to);

        $salesTotals = DB::table('sales')
            ->where('status', 'posted')
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(total), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(cost_of_goods_sold), 0) AS cogs')
            ->selectRaw('COALESCE(SUM(gross_profit), 0) AS gross_profit')
            ->first();

        $cashSales = $this->salesByOriginalType('cash_sale', $from, $to);
        $creditSales = $this->salesByOriginalType('credit_sale', $from, $to);

        $collections = (float) DB::table('transactions')
            ->where('status', 'posted')
            ->whereIn('type', ['cash_sale', 'customer_payment'])
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->sum('amount');

        $expenses = (float) DB::table('transactions')
            ->where('status', 'posted')
            ->where('type', 'expense')
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->sum('amount');

        $grossProfit = (float) $salesTotals->gross_profit;
        $netProfit = $grossProfit - $expenses;

        $purchaseTotals = DB::table('purchases')
            ->where('status', 'posted')
            ->whereDate('purchase_date', '>=', $from)
            ->whereDate('purchase_date', '<=', $to)
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(total), 0) AS total')
            ->selectRaw('COALESCE(SUM(amount_paid), 0) AS amount_paid')
            ->first();

        $accountsBalance = Account::query()
            ->where('is_active', true)
            ->get()
            ->reduce(fn (float $sum, Account $account) => $sum + (float) $this->balances->accountBalance($account), 0.0);

        $receivables = $this->balances->receivablesBreakdown();
        $payables = $this->balances->payablesBreakdown();

        $overdueReceivables = (float) DB::table('sales')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->sum('balance_due');

        $products = Product::query()->where('is_active', true)->get();
        $inventoryRows = $this->inventoryRowsFor($products);
        $lowStock = $inventoryRows->filter(fn (array $row) => $row['status'] === 'low_stock');
        $outOfStock = $inventoryRows->filter(fn (array $row) => $row['status'] === 'out_of_stock');

        $todaySales = (float) DB::table('sales')
            ->where('status', 'posted')
            ->whereDate('sale_date', now()->toDateString())
            ->sum('total');

        $monthSales = (float) DB::table('sales')
            ->where('status', 'posted')
            ->whereDate('sale_date', '>=', now()->startOfMonth()->toDateString())
            ->whereDate('sale_date', '<=', now()->endOfMonth()->toDateString())
            ->sum('total');

        return [
            'period' => ['from' => $from, 'to' => $to, 'range' => $rangeUsed],
            'financial' => [
                'total_sales' => $this->money($salesTotals->revenue),
                'cash_sales' => $this->money($cashSales),
                'credit_sales' => $this->money($creditSales),
                'customer_collections' => $this->money($collections),
                'total_expenses' => $this->money($expenses),
                'gross_profit' => $this->money($grossProfit),
                'net_profit' => $this->money($netProfit),
                'accounts_balance' => $this->money($accountsBalance),
            ],
            'credit' => [
                'accounts_receivable' => $this->money($receivables->sum('balance')),
                'accounts_payable' => $this->money($payables->sum('balance')),
                'overdue_customer_balance' => $this->money($overdueReceivables),
                'supplier_balances' => $this->money($payables->sum('balance')),
            ],
            'inventory' => [
                'inventory_value' => $this->money($inventoryRows->sum('value')),
                'current_stock_quantity' => (float) $inventoryRows->sum('stock'),
                'low_stock_products' => $lowStock->count(),
                'active_products' => $products->count(),
                'stock_requiring_attention' => $lowStock->count() + $outOfStock->count(),
            ],
            'sales' => [
                'invoices' => (int) $salesTotals->invoices,
                'average_sale_value' => $salesTotals->invoices > 0
                    ? $this->money($salesTotals->revenue / $salesTotals->invoices)
                    : $this->money(0),
                'today_sales' => $this->money($todaySales),
                'this_month_sales' => $this->money($monthSales),
            ],
            'purchases' => [
                'count' => (int) $purchaseTotals->invoices,
                'purchase_value' => $this->money($purchaseTotals->total),
                'amount_paid' => $this->money($purchaseTotals->amount_paid),
                'outstanding_supplier_balance' => $this->money($payables->sum('balance')),
            ],
        ];
    }

    /**
     * Total value of posted sales whose ORIGINAL transaction type (recorded
     * once, at sale time, by SaleService) was $type. sales.amount_paid can
     * change later as customer payments come in, so comparing amount_paid
     * to total at query time would reclassify a credit sale as a cash sale
     * once it happens to be fully paid off - this reads the classification
     * that was actually made at the time of the sale instead.
     */
    private function salesByOriginalType(string $type, string $from, string $to): float
    {
        return (float) DB::table('sales')
            ->join('transactions', function ($join) use ($type): void {
                $join->on('transactions.sale_id', '=', 'sales.id')
                    ->where('transactions.type', $type)
                    ->where('transactions.status', 'posted');
            })
            ->where('sales.status', 'posted')
            ->whereDate('sales.sale_date', '>=', $from)
            ->whereDate('sales.sale_date', '<=', $to)
            ->sum('sales.total');
    }

    /**
     * @return array{data: array, meta: array, summary: array}
     */
    public function sales(array $filters): array
    {
        [$from, $to, $rangeUsed] = $this->resolveRange(
            $filters['range'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );

        $query = DB::table('sales')
            ->leftJoin('people', 'people.id', '=', 'sales.customer_id')
            ->where('sales.status', 'posted')
            ->whereDate('sales.sale_date', '>=', $from)
            ->whereDate('sales.sale_date', '<=', $to);

        if (!empty($filters['customer_id'])) {
            $query->where('sales.customer_id', $filters['customer_id']);
        }

        if (!empty($filters['payment_status'])) {
            $query->where('sales.payment_status', $filters['payment_status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('sales.invoice_number', 'like', "%{$search}%")
                    ->orWhere('people.name', 'like', "%{$search}%");
            });
        }

        $summary = (clone $query)
            ->selectRaw('COALESCE(SUM(sales.total), 0) AS total_sales')
            ->selectRaw('COALESCE(SUM(sales.cost_of_goods_sold), 0) AS total_cogs')
            ->selectRaw('COALESCE(SUM(sales.gross_profit), 0) AS gross_profit')
            ->selectRaw('COALESCE(SUM(sales.amount_paid), 0) AS collected')
            ->selectRaw('COALESCE(SUM(sales.balance_due), 0) AS outstanding')
            ->first();

        $perPage = min((int) ($filters['per_page'] ?? 25), 100) ?: 25;

        $paginator = $query
            ->select([
                'sales.id',
                'sales.invoice_number',
                'sales.sale_date',
                'people.name as customer_name',
                'sales.total',
                'sales.amount_paid',
                'sales.balance_due',
                'sales.cost_of_goods_sold',
                'sales.gross_profit',
                'sales.payment_status',
            ])
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'period' => ['from' => $from, 'to' => $to, 'range' => $rangeUsed],
            'data' => collect($paginator->items())->map(fn ($row) => [
                'id' => (int) $row->id,
                'invoice_number' => $row->invoice_number,
                'date' => $row->sale_date,
                'customer' => $row->customer_name,
                'total' => $this->money($row->total),
                'amount_paid' => $this->money($row->amount_paid),
                'balance_due' => $this->money($row->balance_due),
                'cogs' => $this->money($row->cost_of_goods_sold),
                'gross_profit' => $this->money($row->gross_profit),
                'payment_status' => $row->payment_status,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'total_sales' => $this->money($summary->total_sales),
                'total_cogs' => $this->money($summary->total_cogs),
                'gross_profit' => $this->money($summary->gross_profit),
                'amount_collected' => $this->money($summary->collected),
                'outstanding' => $this->money($summary->outstanding),
            ],
        ];
    }

    /**
     * @return array{data: array, meta: array, summary: array}
     */
    public function purchases(array $filters): array
    {
        [$from, $to, $rangeUsed] = $this->resolveRange(
            $filters['range'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );

        $query = DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', 'posted')
            ->whereDate('purchases.purchase_date', '>=', $from)
            ->whereDate('purchases.purchase_date', '<=', $to);

        if (!empty($filters['supplier_id'])) {
            $query->where('purchases.supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['payment_status'])) {
            $query->where('purchases.payment_status', $filters['payment_status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('purchases.purchase_number', 'like', "%{$search}%")
                    ->orWhere('suppliers.name', 'like', "%{$search}%");
            });
        }

        $summary = (clone $query)
            ->selectRaw('COALESCE(SUM(purchases.total), 0) AS total_purchases')
            ->selectRaw('COALESCE(SUM(purchases.amount_paid), 0) AS amount_paid')
            ->selectRaw('COALESCE(SUM(purchases.balance_due), 0) AS outstanding')
            ->first();

        $perPage = min((int) ($filters['per_page'] ?? 25), 100) ?: 25;

        $paginator = $query
            ->select([
                'purchases.id',
                'purchases.purchase_number',
                'purchases.purchase_date',
                'suppliers.name as supplier_name',
                'purchases.total',
                'purchases.amount_paid',
                'purchases.balance_due',
                'purchases.payment_status',
            ])
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchases.id')
            ->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'period' => ['from' => $from, 'to' => $to, 'range' => $rangeUsed],
            'data' => collect($paginator->items())->map(fn ($row) => [
                'id' => (int) $row->id,
                'purchase_number' => $row->purchase_number,
                'date' => $row->purchase_date,
                'supplier' => $row->supplier_name,
                'total' => $this->money($row->total),
                'amount_paid' => $this->money($row->amount_paid),
                'balance_due' => $this->money($row->balance_due),
                'payment_status' => $row->payment_status,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'total_purchases' => $this->money($summary->total_purchases),
                'amount_paid' => $this->money($summary->amount_paid),
                'outstanding_supplier_balance' => $this->money($summary->outstanding),
            ],
        ];
    }

    /**
     * A transparent Profit & Loss for the period. Revenue is the full
     * invoice value of posted sales (credit sales included even though
     * not yet collected) - never the cash collected, which is a different
     * number shown separately in summary().
     */
    public function profit(?string $range, ?string $from, ?string $to): array
    {
        [$from, $to, $rangeUsed] = $this->resolveRange($range, $from, $to);

        $sales = DB::table('sales')
            ->where('status', 'posted')
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(total), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(cost_of_goods_sold), 0) AS cogs')
            ->first();

        $revenue = (float) $sales->revenue;
        $cogs = (float) $sales->cogs;
        $grossProfit = round($revenue - $cogs, 2);

        $expenseRows = DB::table('transactions')
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.status', 'posted')
            ->where('transactions.type', 'expense')
            ->whereDate('transactions.transaction_date', '>=', $from)
            ->whereDate('transactions.transaction_date', '<=', $to)
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') AS category")
            ->selectRaw('COALESCE(SUM(transactions.amount), 0) AS total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $totalExpenses = (float) $expenseRows->sum('total');
        $netProfit = round($grossProfit - $totalExpenses, 2);

        return [
            'period' => ['from' => $from, 'to' => $to, 'range' => $rangeUsed],
            'revenue' => [
                'sales_revenue' => $this->money($revenue),
            ],
            'cost_of_goods_sold' => [
                'cogs' => $this->money($cogs),
            ],
            'gross_profit' => $this->money($grossProfit),
            'operating_expenses' => [
                'by_category' => $expenseRows->map(fn ($row) => [
                    'category' => $row->category,
                    'amount' => $this->money($row->total),
                ])->all(),
                'total' => $this->money($totalExpenses),
            ],
            'net_profit' => $this->money($netProfit),
        ];
    }

    public function customerReceivables(): array
    {
        return $this->balances->receivablesBreakdown()
            ->map(fn (array $row) => [
                ...$row,
                'available_credit' => $row['credit_limit'] !== null
                    ? $this->money(max(0, (float) $row['credit_limit'] - (float) $row['balance']))
                    : null,
            ])
            ->all();
    }

    public function supplierPayables(): array
    {
        return $this->balances->payablesBreakdown()
            ->map(fn (array $row) => [
                ...$row,
                'available_credit' => $row['credit_limit'] !== null
                    ? $this->money(max(0, (float) $row['credit_limit'] - (float) $row['balance']))
                    : null,
            ])
            ->all();
    }

    /**
     * Ages OUTSTANDING customer receivables by sale_date - the simplest
     * methodology the current data model actually supports (there is no
     * mature due-date tracking to age against instead). Only the current
     * balance_due of each posted, non-voided sale contributes; a fully
     * paid sale (balance_due = 0) does not appear in any bucket. Ages are
     * computed per sale rather than per transaction so a partially paid
     * sale ages as one thing, matching how a real invoice would age.
     */
    public function receivablesAging(): array
    {
        return $this->agingBuckets('sales', 'sale_date');
    }

    /**
     * Mirrors receivablesAging() for outstanding supplier payables, aged
     * by purchase_date.
     */
    public function payablesAging(): array
    {
        return $this->agingBuckets('purchases', 'purchase_date');
    }

    /**
     * @return array{buckets: array<int, array{label: string, outstanding: string}>, total: string}
     */
    private function agingBuckets(string $table, string $dateColumn): array
    {
        $today = now()->startOfDay();
        $labels = ['0-30', '31-60', '61-90', '90+'];
        $buckets = array_fill_keys($labels, 0.0);

        DB::table($table)
            ->where('status', 'posted')
            ->where('balance_due', '>', 0)
            ->select(['id', $dateColumn, 'balance_due'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$buckets, $today, $dateColumn): void {
                foreach ($rows as $row) {
                    $days = now()->parse($row->{$dateColumn})->startOfDay()->diffInDays($today);
                    $label = match (true) {
                        $days <= 30 => '0-30',
                        $days <= 60 => '31-60',
                        $days <= 90 => '61-90',
                        default => '90+',
                    };
                    $buckets[$label] += (float) $row->balance_due;
                }
            });

        return [
            'buckets' => array_map(
                fn (string $label) => ['label' => $label, 'outstanding' => $this->money($buckets[$label])],
                $labels,
            ),
            'total' => $this->money(array_sum($buckets)),
        ];
    }

    public function inventory(): array
    {
        $products = Product::query()
            ->with(['category', 'baseUnit'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->inventoryRowsFor($products)->values()->all();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, array>
     */
    private function inventoryRowsFor(Collection $products): Collection
    {
        return $products->map(function (Product $product) {
            $stock = (float) $this->inventory->currentStock($product);
            $value = (float) $this->inventory->inventoryValue($product);
            $costPerUnit = $stock > 0.00005 ? round($value / $stock, 4) : null;
            $minimumStock = (float) $product->minimum_stock;

            $status = match (true) {
                $stock <= 0.00005 => 'out_of_stock',
                $stock <= $minimumStock => 'low_stock',
                default => 'in_stock',
            };

            return [
                'id' => $product->id,
                'product' => $product->name,
                'sku' => $product->sku,
                'stock' => $stock,
                'minimum_stock' => $minimumStock,
                'unit' => $product->relationLoaded('baseUnit') ? $product->baseUnit?->abbreviation : null,
                'inventory_value' => $this->money($value),
                'cost_per_unit' => $costPerUnit !== null ? number_format($costPerUnit, 4, '.', '') : null,
                'status' => $status,
            ];
        });
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

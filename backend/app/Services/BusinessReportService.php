<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessReportService
{
    public function summary(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->period($from, $to);

        $sales = DB::table('sales')
            ->where('status', 'posted')
            ->whereBetween('sale_date', [$from, $to])
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(total), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(cost_of_goods_sold), 0) AS cogs')
            ->selectRaw('COALESCE(SUM(gross_profit), 0) AS gross_profit')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_paid >= total THEN total ELSE 0 END), 0) AS cash_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_paid < total THEN total ELSE 0 END), 0) AS credit_sales')
            ->first();

        $purchases = DB::table('purchases')
            ->where('status', 'posted')
            ->whereBetween('purchase_date', [$from, $to])
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(total), 0) AS total')
            ->selectRaw('COALESCE(SUM(amount_paid), 0) AS amount_paid')
            ->selectRaw('COALESCE(SUM(balance_due), 0) AS balance_due')
            ->first();

        $expenses = DB::table('transactions')
            ->where('status', 'posted')
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$from, $to])
            ->sum('amount');

        $collections = DB::table('transactions')
            ->where('status', 'posted')
            ->whereIn('type', ['cash_sale', 'customer_payment'])
            ->whereBetween('transaction_date', [$from, $to])
            ->sum('amount');

        $supplierPayments = DB::table('transactions')
            ->where('status', 'posted')
            ->where('type', 'supplier_payment')
            ->whereBetween('transaction_date', [$from, $to])
            ->sum('amount');

        $receivables = DB::table('transactions')
            ->join('people', 'people.id', '=', 'transactions.person_id')
            ->where('transactions.status', 'posted')
            ->where('people.is_customer', true)
            ->selectRaw('COALESCE(SUM(transactions.person_balance_effect), 0) AS balance')
            ->value('balance');

        $payables = DB::table('transactions')
            ->where('status', 'posted')
            ->selectRaw('COALESCE(SUM(supplier_balance_effect), 0) AS balance')
            ->value('balance');

        $inventory = DB::table('inventory_movements')
            ->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->where('products.is_active', true)
            ->selectRaw('COALESCE(SUM(inventory_movements.base_quantity), 0) AS quantity')
            ->selectRaw('COALESCE(SUM(inventory_movements.total_cost), 0) AS value')
            ->value('quantity');

        $inventoryValue = DB::table('inventory_movements')
            ->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->where('products.is_active', true)
            ->sum('inventory_movements.total_cost');

        $lowStockCount = $this->inventoryRows()
            ->filter(fn (array $row) => $row['low_stock'])
            ->count();

        $grossProfit = (float) $sales->gross_profit;
        $expenseValue = (float) $expenses;

        return [
            'period' => ['from' => $from, 'to' => $to],
            'sales' => [
                'invoices' => (int) $sales->invoices,
                'revenue' => $this->money($sales->revenue),
                'cash_sales' => $this->money($sales->cash_sales),
                'credit_sales' => $this->money($sales->credit_sales),
                'collected' => $this->money($collections),
                'cogs' => $this->money($sales->cogs),
                'gross_profit' => $this->money($grossProfit),
            ],
            'purchases' => [
                'invoices' => (int) $purchases->invoices,
                'total' => $this->money($purchases->total),
                'amount_paid' => $this->money($purchases->amount_paid),
                'balance_due' => $this->money($purchases->balance_due),
                'supplier_payments' => $this->money($supplierPayments),
            ],
            'profit' => [
                'gross_profit' => $this->money($grossProfit),
                'expenses' => $this->money($expenseValue),
                'net_profit' => $this->money($grossProfit - $expenseValue),
            ],
            'credit' => [
                'receivables' => $this->money($receivables),
                'payables' => $this->money($payables),
            ],
            'inventory' => [
                'quantity' => $this->money($inventory),
                'value' => $this->money($inventoryValue),
                'low_stock_count' => $lowStockCount,
            ],
        ];
    }

    public function sales(?string $from = null, ?string $to = null)
    {
        [$from, $to] = $this->period($from, $to);

        return DB::table('sales')
            ->leftJoin('people', 'people.id', '=', 'sales.customer_id')
            ->where('sales.status', 'posted')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->select([
                'sales.id',
                'sales.invoice_number',
                'sales.sale_date',
                'people.name as customer_name',
                'sales.total',
                'sales.cost_of_goods_sold',
                'sales.gross_profit',
                'sales.amount_paid',
                'sales.balance_due',
                'sales.payment_status',
            ])
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->paginate(50);
    }

    public function purchases(?string $from = null, ?string $to = null)
    {
        [$from, $to] = $this->period($from, $to);

        return DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', 'posted')
            ->whereBetween('purchases.purchase_date', [$from, $to])
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
            ->paginate(50);
    }

    public function inventory(): array
    {
        return $this->inventoryRows()->values()->all();
    }

    public function customerReceivables(): array
    {
        $rows = DB::table('people')
            ->where('people.is_customer', true)
            ->where('people.is_active', true)
            ->leftJoin('transactions', function ($join): void {
                $join->on('transactions.person_id', '=', 'people.id')
                    ->where('transactions.status', '=', 'posted')
                    ->whereIn('transactions.type', ['credit_sale', 'customer_payment']);
            })
            ->groupBy('people.id', 'people.name', 'people.customer_code', 'people.credit_limit')
            ->select([
                'people.id',
                'people.name',
                'people.customer_code',
                'people.credit_limit',
            ])
            ->selectRaw('COALESCE(SUM(transactions.person_balance_effect), 0) AS balance')
            ->havingRaw('COALESCE(SUM(transactions.person_balance_effect), 0) > 0')
            ->orderByDesc('balance')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'customer_code' => $row->customer_code,
            'credit_limit' => $this->money($row->credit_limit),
            'balance' => $this->money($row->balance),
        ])->all();
    }

    public function supplierPayables(): array
    {
        $rows = DB::table('suppliers')
            ->where('suppliers.is_active', true)
            ->leftJoin('transactions', function ($join): void {
                $join->on('transactions.supplier_id', '=', 'suppliers.id')
                    ->where('transactions.status', '=', 'posted');
            })
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.supplier_code', 'suppliers.credit_limit')
            ->select([
                'suppliers.id',
                'suppliers.name',
                'suppliers.supplier_code',
                'suppliers.credit_limit',
            ])
            ->selectRaw('COALESCE(SUM(transactions.supplier_balance_effect), 0) AS balance')
            ->havingRaw('COALESCE(SUM(transactions.supplier_balance_effect), 0) > 0')
            ->orderByDesc('balance')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'supplier_code' => $row->supplier_code,
            'credit_limit' => $this->money($row->credit_limit),
            'balance' => $this->money($row->balance),
        ])->all();
    }

    private function inventoryRows()
    {
        $rows = DB::table('products')
            ->leftJoin('inventory_movements', 'inventory_movements.product_id', '=', 'products.id')
            ->leftJoin('units', 'units.id', '=', 'products.base_unit_id')
            ->where('products.is_active', true)
            ->groupBy(
                'products.id',
                'products.name',
                'products.sku',
                'products.minimum_stock',
                'products.base_unit_id',
                'units.name',
                'units.abbreviation'
            )
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'products.minimum_stock',
                'products.base_unit_id',
                'units.name as unit_name',
                'units.abbreviation as unit_abbreviation',
            ])
            ->selectRaw('COALESCE(SUM(inventory_movements.base_quantity), 0) AS stock')
            ->selectRaw('COALESCE(SUM(inventory_movements.total_cost), 0) AS value')
            ->orderBy('products.name')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'sku' => $row->sku,
            'stock' => (float) $row->stock,
            'minimum_stock' => (float) $row->minimum_stock,
            'unit_name' => $row->unit_name,
            'unit_abbreviation' => $row->unit_abbreviation,
            'value' => $this->money($row->value),
            'low_stock' => (float) $row->stock <= (float) $row->minimum_stock,
        ]);
    }

    private function period(?string $from, ?string $to): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->endOfMonth()->toDateString();

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            throw new \InvalidArgumentException('Date range must use YYYY-MM-DD and from must be on or before to.');
        }

        return [$from, $to];
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

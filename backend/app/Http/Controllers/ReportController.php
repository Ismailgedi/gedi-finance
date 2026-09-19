<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary(): JsonResponse
    {
        $sales = (float) DB::table('sales')
            ->where('status', 'posted')
            ->sum('total');

        $purchases = (float) DB::table('purchases')
            ->where('status', 'posted')
            ->sum('total');

        $cogs = (float) DB::table('sales')
            ->where('status', 'posted')
            ->sum('cost_of_goods_sold');

        $grossProfit = (float) DB::table('sales')
            ->where('status', 'posted')
            ->sum('gross_profit');

        // Receivables are the outstanding balances on posted credit sales.
        // This avoids mixing customer balances with unrelated transaction types.
        $receivables = (float) DB::table('sales')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0)
            ->sum('balance_due');

        // Payables are the outstanding balances on posted purchases.
        $payables = (float) DB::table('purchases')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0)
            ->sum('balance_due');

        // Inventory movements use signed quantities and signed total costs.
        $inventoryValue = (float) DB::table('inventory_movements')
            ->sum('total_cost');

        return response()->json([
            'sales' => round($sales, 2),
            'purchases' => round($purchases, 2),
            'cost_of_goods_sold' => round($cogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'receivables' => round($receivables, 2),
            'payables' => round($payables, 2),
            'inventory_value' => round($inventoryValue, 2),
        ]);
    }

    public function sales(Request $request): JsonResponse
    {
        $query = DB::table('sales')
            ->leftJoin('people', 'people.id', '=', 'sales.customer_id')
            ->where('sales.status', 'posted')
            ->select(
                'sales.id',
                'sales.invoice_number',
                'sales.sale_date',
                'sales.customer_id',
                'people.name as customer_name',
                'sales.subtotal',
                'sales.discount',
                'sales.total',
                'sales.cost_of_goods_sold',
                'sales.gross_profit',
                'sales.amount_paid',
                'sales.balance_due',
                'sales.payment_status'
            )
            ->orderByDesc('sales.sale_date');

        if ($request->filled('from')) {
            $query->whereDate('sales.sale_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sales.sale_date', '<=', $request->input('to'));
        }

        return response()->json($query->get());
    }

    public function purchases(Request $request): JsonResponse
    {
        $query = DB::table('purchases')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', 'posted')
            ->select(
                'purchases.id',
                'purchases.purchase_number',
                'purchases.purchase_date',
                'purchases.supplier_id',
                'suppliers.name as supplier_name',
                'purchases.subtotal',
                'purchases.discount',
                'purchases.total',
                'purchases.amount_paid',
                'purchases.balance_due',
                'purchases.payment_status'
            )
            ->orderByDesc('purchases.purchase_date');

        if ($request->filled('from')) {
            $query->whereDate('purchases.purchase_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('purchases.purchase_date', '<=', $request->input('to'));
        }

        return response()->json($query->get());
    }

    public function inventory(): JsonResponse
    {
        $products = DB::table('products')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                'product_categories.name as category'
            )
            ->orderBy('products.name')
            ->get();

        $rows = $products->map(function ($product) {
            $stock = (float) DB::table('inventory_movements')
                ->where('product_id', $product->id)
                ->sum('base_quantity');

            $value = (float) DB::table('inventory_movements')
                ->where('product_id', $product->id)
                ->sum('total_cost');

            $averageCost = $stock > 0
                ? $value / $stock
                : null;

            return [
                'product_id' => $product->id,
                'product' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category,
                'stock' => round($stock, 4),
                'average_cost' => $averageCost !== null
                    ? round($averageCost, 4)
                    : null,
                'inventory_value' => round($value, 2),
            ];
        });

        return response()->json($rows);
    }

    public function receivables(): JsonResponse
    {
        $people = DB::table('sales')
            ->join('people', 'people.id', '=', 'sales.customer_id')
            ->where('sales.status', 'posted')
            ->whereNotNull('sales.customer_id')
            ->where('sales.balance_due', '>', 0)
            ->groupBy('people.id', 'people.name')
            ->select('people.id', 'people.name')
            ->selectRaw('SUM(sales.balance_due) AS balance')
            ->orderByDesc('balance')
            ->get();

        return response()->json($people);
    }

    public function payables(): JsonResponse
    {
        $suppliers = DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', 'posted')
            ->where('purchases.balance_due', '>', 0)
            ->groupBy('suppliers.id', 'suppliers.name')
            ->select('suppliers.id', 'suppliers.name')
            ->selectRaw('SUM(purchases.balance_due) AS balance')
            ->orderByDesc('balance')
            ->get();

        return response()->json($suppliers);
    }

    public function incomeExpenses(Request $request): JsonResponse
    {
        $query = DB::table('transactions')
            ->selectRaw("DATE(transaction_date) as date")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense")
            ->where('status', 'posted');

        if ($request->filled('from')) {
            $query->whereDate('transaction_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('transaction_date', '<=', $request->input('to'));
        }

        $rows = $query
            ->groupByRaw('DATE(transaction_date)')
            ->orderByDesc('date')
            ->limit(90)
            ->get();

        return response()->json($rows);
    }
}

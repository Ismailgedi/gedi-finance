<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Person;
use App\Models\Supplier;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gathers the exact filtered rows + business-accurate totals for the
 * "Download Excel" report export feature, so what a user filters on
 * screen is exactly what ends up in the workbook. Deliberately does not
 * re-derive a balance another service already owns: outstanding-balance
 * figures for a specific customer/supplier always come from
 * BalanceService (the real, current balance), never from summing the
 * filtered/date-ranged rows, which would silently produce a wrong number
 * whenever a date filter excludes older still-open invoices.
 *
 * This service holds no spreadsheet/rendering concerns at all - it
 * returns plain arrays. ReportExcelController and the Export classes in
 * App\Exports are solely responsible for turning that data into an
 * actual .xlsx response.
 */
class ReportExportService
{
    /**
     * The transaction types that make up a customer's receivable balance
     * (see BalanceService::customerReceivableBalance) - mirrored here
     * (not duplicated logic, just the same list) so the customer
     * statement's running balance column can be seeded with an accurate
     * "brought forward" opening balance as of a given date.
     */
    private const RECEIVABLE_TYPES = ['credit_sale', 'customer_payment'];

    public function __construct(private readonly BalanceService $balances) {}

    /**
     * @param  array{person_id?:string,type?:string,account_id?:string,status?:string,from?:string,to?:string,search?:string}  $filters
     */
    public function transactionsReport(array $filters): array
    {
        $person = ! empty($filters['person_id']) ? Person::find($filters['person_id']) : null;

        $query = Transaction::query()->with(['person', 'account', 'supplier']);

        if ($person) {
            $query->where('person_id', $person->id);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Matches the on-screen filter's semantics exactly: only the
        // source account, not the destination side of a transfer, so the
        // export always contains precisely what the screen currently shows.
        if (! empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('transaction_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('transaction_date', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term): void {
                $q->where('transaction_number', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhereHas('person', fn ($p) => $p->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('supplier', fn ($p) => $p->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('account', fn ($p) => $p->where('name', 'like', "%{$term}%"));
            });
        }

        $transactions = $query->orderBy('transaction_date')->orderBy('id')->get();

        if ($person) {
            return $this->buildCustomerStatement($person, $transactions, $filters['from'] ?? null);
        }

        $rows = $transactions->map(fn (Transaction $t) => [
            'date' => $t->transaction_date->format('d M Y'),
            'description' => $t->description ?: ($t->reference ?: $this->typeLabel($t->type->value)),
            'type' => $this->typeLabel($t->type->value),
            'amount' => $this->money($t->amount),
            'account' => $t->account?->name ?: 'Credit',
            'status' => ucfirst($t->status),
        ])->all();

        $incoming = 0.0;
        $outgoing = 0.0;
        foreach ($transactions as $t) {
            $effect = (float) $t->account_balance_effect + (float) $t->destination_account_effect;
            if ($effect > 0) {
                $incoming += $effect;
            } elseif ($effect < 0) {
                $outgoing += abs($effect);
            }
        }

        return [
            'title' => 'BUSINESS TRANSACTION REPORT',
            'subtitle' => null,
            'columns' => ['Date', 'Description', 'Type', 'Amount', 'Account', 'Status'],
            'align' => ['left', 'left', 'left', 'right', 'left', 'left'],
            'rows' => $rows,
            'totals' => [
                'Total Incoming' => $this->money($incoming),
                'Total Outgoing' => $this->money($outgoing),
                'Net Movement' => $this->money($incoming - $outgoing),
            ],
            'count' => count($rows),
        ];
    }

    /**
     * Builds the CUSTOMER STATEMENT shape: a proper Debit/Credit/running
     * Balance ledger view. The running balance is seeded with the
     * customer's real opening balance as of the filter's `from` date (0
     * if there is no `from` filter, i.e. showing all history), computed
     * with the exact same type/status filter BalanceService::
     * customerReceivableBalance uses - so the LAST row's running balance
     * always equals the real, current balance, never a number that could
     * drift from what the rest of the app shows for this customer.
     */
    private function buildCustomerStatement(Person $person, Collection $transactions, ?string $from): array
    {
        $openingBalance = $this->receivableBalanceAsOf($person, $from);

        $running = $openingBalance;
        $rows = [];

        foreach ($transactions as $t) {
            $isReceivableType = in_array($t->type->value, self::RECEIVABLE_TYPES, true);
            $effect = $isReceivableType ? (float) $t->person_balance_effect : 0.0;
            $running += $effect;

            $rows[] = [
                'date' => $t->transaction_date->format('d M Y'),
                'reference' => $t->reference ?: $t->transaction_number,
                'type' => $this->typeLabel($t->type->value),
                'description' => $t->description ?: $this->typeLabel($t->type->value),
                'debit' => $effect > 0 ? $this->money($effect) : '',
                'credit' => $effect < 0 ? $this->money(abs($effect)) : '',
                'balance' => $this->money($running),
            ];
        }

        $creditSales = (float) $transactions->sum(
            fn (Transaction $t) => $t->type === TransactionType::CreditSale ? $t->amount : 0
        );
        $payments = (float) $transactions->sum(
            fn (Transaction $t) => $t->type === TransactionType::CustomerPayment ? $t->amount : 0
        );

        return [
            'title' => 'CUSTOMER STATEMENT',
            'subtitle' => "Customer: {$person->name}",
            'columns' => ['Date', 'Reference', 'Type', 'Description', 'Debit', 'Credit', 'Balance'],
            'align' => ['left', 'left', 'left', 'left', 'right', 'right', 'right'],
            'rows' => $rows,
            'totals' => [
                'Total Credit Sales' => $this->money($creditSales),
                'Total Payments' => $this->money($payments),
                'Outstanding Balance' => $this->balances->customerReceivableBalance($person),
            ],
            'count' => count($rows),
        ];
    }

    /**
     * The customer's receivable balance as of just before $from (or 0 if
     * $from is null, i.e. the statement covers all history). Mirrors
     * BalanceService::customerReceivableBalance's own type/status filter
     * exactly, only adding a date bound - not a new balance formula.
     */
    private function receivableBalanceAsOf(Person $person, ?string $from): float
    {
        if (! $from) {
            return 0.0;
        }

        $balance = Transaction::query()
            ->where('person_id', $person->id)
            ->where('status', 'posted')
            ->whereIn('type', self::RECEIVABLE_TYPES)
            ->whereDate('transaction_date', '<', $from)
            ->sum('person_balance_effect');

        return (float) $balance;
    }

    /**
     * @param  array{customer_id?:string,payment_status?:string,from?:string,to?:string,search?:string}  $filters
     */
    public function salesReport(array $filters): array
    {
        $customer = ! empty($filters['customer_id']) ? Person::find($filters['customer_id']) : null;

        $query = DB::table('sales')
            ->leftJoin('people', 'people.id', '=', 'sales.customer_id')
            ->leftJoin('transactions', function ($join): void {
                $join->on('transactions.sale_id', '=', 'sales.id')
                    ->where('transactions.status', 'posted')
                    ->whereIn('transactions.type', ['cash_sale', 'credit_sale']);
            })
            ->where('sales.status', 'posted');

        if ($customer) {
            $query->where('sales.customer_id', $customer->id);
        }

        if (! empty($filters['payment_status'])) {
            $query->where('sales.payment_status', $filters['payment_status']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('sales.sale_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('sales.sale_date', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term): void {
                $q->where('sales.invoice_number', 'like', "%{$term}%")
                    ->orWhere('people.name', 'like', "%{$term}%");
            });
        }

        $rows = (clone $query)
            ->select([
                'sales.invoice_number', 'sales.sale_date', 'people.name as customer_name',
                'transactions.type as sale_type',
                'sales.subtotal', 'sales.discount', 'sales.total', 'sales.amount_paid', 'sales.balance_due',
                'sales.cost_of_goods_sold', 'sales.gross_profit', 'sales.payment_status',
            ])
            ->orderBy('sales.sale_date')
            ->orderBy('sales.id')
            ->get();

        $totalsRow = (clone $query)
            ->selectRaw('COALESCE(SUM(sales.total), 0) AS total_sales')
            ->selectRaw('COALESCE(SUM(sales.amount_paid), 0) AS amount_paid')
            ->selectRaw('COALESCE(SUM(sales.balance_due), 0) AS outstanding')
            ->first();

        $title = $customer ? 'CUSTOMER STATEMENT' : 'SALES REPORT';
        $outstanding = $customer
            ? $this->balances->customerReceivableBalance($customer)
            : $this->money($totalsRow->outstanding);

        return [
            'title' => $title,
            'subtitle' => $customer ? "Customer: {$customer->name}" : null,
            'columns' => [
                'Invoice Number', 'Date', 'Customer', 'Sale Type', 'Subtotal', 'Discount',
                'Total', 'Paid', 'Balance', 'COGS', 'Gross Profit', 'Payment Status',
            ],
            'align' => [
                'left', 'left', 'left', 'left', 'right', 'right',
                'right', 'right', 'right', 'right', 'right', 'left',
            ],
            'rows' => $rows->map(fn ($r) => [
                'invoice_number' => $r->invoice_number,
                'date' => Carbon::parse($r->sale_date)->format('d M Y'),
                'customer' => $r->customer_name ?? 'Walk-in',
                'sale_type' => $r->sale_type === 'cash_sale' ? 'Cash Sale' : ($r->sale_type === 'credit_sale' ? 'Credit Sale' : '—'),
                'subtotal' => $this->money($r->subtotal),
                'discount' => $this->money($r->discount),
                'total' => $this->money($r->total),
                'paid' => $this->money($r->amount_paid),
                'balance' => $this->money($r->balance_due),
                'cogs' => $this->money($r->cost_of_goods_sold),
                'gross_profit' => $this->money($r->gross_profit),
                'payment_status' => ucfirst($r->payment_status),
            ])->all(),
            'totals' => [
                'Total Sales' => $this->money($totalsRow->total_sales),
                'Total Payments' => $this->money($totalsRow->amount_paid),
                'Outstanding Balance' => $outstanding,
            ],
            'count' => $rows->count(),
        ];
    }

    /**
     * @param  array{supplier_id?:string,payment_status?:string,from?:string,to?:string,search?:string}  $filters
     */
    public function purchasesReport(array $filters): array
    {
        $supplier = ! empty($filters['supplier_id']) ? Supplier::find($filters['supplier_id']) : null;

        $query = DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', 'posted');

        if ($supplier) {
            $query->where('purchases.supplier_id', $supplier->id);
        }

        if (! empty($filters['payment_status'])) {
            $query->where('purchases.payment_status', $filters['payment_status']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('purchases.purchase_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('purchases.purchase_date', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term): void {
                $q->where('purchases.purchase_number', 'like', "%{$term}%")
                    ->orWhere('suppliers.name', 'like', "%{$term}%");
            });
        }

        $rows = (clone $query)
            ->select([
                'purchases.purchase_number', 'purchases.purchase_date', 'suppliers.name as supplier_name',
                'purchases.subtotal', 'purchases.discount', 'purchases.total', 'purchases.amount_paid',
                'purchases.balance_due', 'purchases.payment_status',
            ])
            ->orderBy('purchases.purchase_date')
            ->orderBy('purchases.id')
            ->get();

        $totalsRow = (clone $query)
            ->selectRaw('COALESCE(SUM(purchases.total), 0) AS total_purchases')
            ->selectRaw('COALESCE(SUM(purchases.amount_paid), 0) AS amount_paid')
            ->selectRaw('COALESCE(SUM(purchases.balance_due), 0) AS outstanding')
            ->first();

        $title = $supplier ? 'SUPPLIER STATEMENT' : 'PURCHASE REPORT';
        $outstanding = $supplier
            ? $this->balances->supplierBalance($supplier)
            : $this->money($totalsRow->outstanding);

        return [
            'title' => $title,
            'subtitle' => $supplier ? "Supplier: {$supplier->name}" : null,
            'columns' => ['Purchase Number', 'Date', 'Supplier', 'Subtotal', 'Discount', 'Total', 'Paid', 'Balance', 'Payment Status'],
            'align' => ['left', 'left', 'left', 'right', 'right', 'right', 'right', 'right', 'left'],
            'rows' => $rows->map(fn ($r) => [
                'purchase_number' => $r->purchase_number,
                'date' => Carbon::parse($r->purchase_date)->format('d M Y'),
                'supplier' => $r->supplier_name,
                'subtotal' => $this->money($r->subtotal),
                'discount' => $this->money($r->discount),
                'total' => $this->money($r->total),
                'paid' => $this->money($r->amount_paid),
                'balance' => $this->money($r->balance_due),
                'payment_status' => ucfirst($r->payment_status),
            ])->all(),
            'totals' => [
                'Total Purchases' => $this->money($totalsRow->total_purchases),
                'Total Payments' => $this->money($totalsRow->amount_paid),
                'Outstanding Balance' => $outstanding,
            ],
            'count' => $rows->count(),
        ];
    }

    private function typeLabel(string $type): string
    {
        return ucwords(str_replace('_', ' ', $type));
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

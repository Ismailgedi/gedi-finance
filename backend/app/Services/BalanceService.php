<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Person;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class BalanceService
{
    public function personBalance(Person $person): string
    {
        $balance = Transaction::query()
            ->where('person_id', $person->id)
            ->where('status', 'posted')
            ->sum('person_balance_effect');

        return number_format((float) $balance, 2, '.', '');
    }

    public function accountBalance(Account $account): string
    {
        $movement = Transaction::query()
            ->where(function ($query) use ($account) {
                $query->where('account_id', $account->id)
                    ->where('status', 'posted');
            })
            ->sum('account_balance_effect');

        $destinationMovement = Transaction::query()
            ->where('destination_account_id', $account->id)
            ->where('status', 'posted')
            ->sum('destination_account_effect');

        $balance = (float) $account->opening_balance + (float) $movement + (float) $destinationMovement;

        return number_format($balance, 2, '.', '');
    }

    public function receivablesSummary(): array
    {
        $result = DB::table('transactions')
            ->where('status', 'posted')
            ->whereNotNull('person_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN person_balance_effect > 0 THEN person_balance_effect ELSE 0 END), 0) AS increases')
            ->selectRaw('COALESCE(SUM(CASE WHEN person_balance_effect < 0 THEN ABS(person_balance_effect) ELSE 0 END), 0) AS decreases')
            ->first();

        return [
            'increases' => number_format((float) $result->increases, 2, '.', ''),
            'decreases' => number_format((float) $result->decreases, 2, '.', ''),
            'outstanding' => number_format((float) $result->increases - (float) $result->decreases, 2, '.', ''),
        ];
    }
    public function supplierBalance(\App\Models\Supplier $supplier): string
    {
        $balance = Transaction::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'posted')
            ->sum('supplier_balance_effect');

        return number_format((float) $balance, 2, '.', '');
    }


    public function customerReceivableBalance(Person $person): string
    {
        $balance = Transaction::query()
            ->where('person_id', $person->id)
            ->where('status', 'posted')
            ->whereIn('type', ['credit_sale', 'customer_payment'])
            ->sum('person_balance_effect');

        return number_format((float) $balance, 2, '.', '');
    }

    /**
     * Per-customer breakdown of the same balance customerReceivableBalance()
     * computes one customer at a time, in a single grouped query. Used by
     * the Customer Receivables report so it doesn't run one query per
     * customer, without introducing a second definition of the balance.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,name:string,customer_code:?string,credit_limit:?string,balance:string}>
     */
    public function receivablesBreakdown(): \Illuminate\Support\Collection
    {
        return DB::table('people')
            ->where('people.is_customer', true)
            ->where('people.is_active', true)
            ->leftJoin('transactions', function ($join): void {
                $join->on('transactions.person_id', '=', 'people.id')
                    ->where('transactions.status', '=', 'posted')
                    ->whereIn('transactions.type', ['credit_sale', 'customer_payment']);
            })
            ->groupBy('people.id', 'people.name', 'people.customer_code', 'people.credit_limit')
            ->select(['people.id', 'people.name', 'people.customer_code', 'people.credit_limit'])
            ->selectRaw('COALESCE(SUM(transactions.person_balance_effect), 0) AS balance')
            ->havingRaw('COALESCE(SUM(transactions.person_balance_effect), 0) > 0')
            ->orderByDesc('balance')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'customer_code' => $row->customer_code,
                'credit_limit' => $row->credit_limit !== null ? number_format((float) $row->credit_limit, 2, '.', '') : null,
                'balance' => number_format((float) $row->balance, 2, '.', ''),
            ]);
    }

    /**
     * Per-supplier breakdown of the same balance supplierBalance() computes
     * one supplier at a time, in a single grouped query. Used by the
     * Supplier Payables report for the same reason as receivablesBreakdown().
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,name:string,supplier_code:string,credit_limit:?string,balance:string}>
     */
    public function payablesBreakdown(): \Illuminate\Support\Collection
    {
        return DB::table('suppliers')
            ->where('suppliers.is_active', true)
            ->leftJoin('transactions', function ($join): void {
                $join->on('transactions.supplier_id', '=', 'suppliers.id')
                    ->where('transactions.status', '=', 'posted');
            })
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.supplier_code', 'suppliers.credit_limit')
            ->select(['suppliers.id', 'suppliers.name', 'suppliers.supplier_code', 'suppliers.credit_limit'])
            ->selectRaw('COALESCE(SUM(transactions.supplier_balance_effect), 0) AS balance')
            ->havingRaw('COALESCE(SUM(transactions.supplier_balance_effect), 0) > 0')
            ->orderByDesc('balance')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'supplier_code' => $row->supplier_code,
                'credit_limit' => $row->credit_limit !== null ? number_format((float) $row->credit_limit, 2, '.', '') : null,
                'balance' => number_format((float) $row->balance, 2, '.', ''),
            ]);
    }
}

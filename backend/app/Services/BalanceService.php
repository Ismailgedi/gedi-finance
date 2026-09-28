<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Person;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class BalanceService
{
    /**
     * Every transaction type that contributes to a customer's receivable
     * balance - credit_sale/customer_payment as before, plus sale_return/
     * sale_return_refund (see SaleReturnService) and
     * opening_balance_receivable (see OpeningBalanceService - a pre-existing
     * receivable the business already had, not a new credit sale). Shared
     * with ReportExportService's customer statement so both never drift
     * apart.
     */
    public const RECEIVABLE_TYPES = ['credit_sale', 'customer_payment', 'sale_return', 'sale_return_refund', 'opening_balance_receivable'];

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
            ->whereIn('type', self::RECEIVABLE_TYPES)
            ->sum('person_balance_effect');

        return number_format((float) $balance, 2, '.', '');
    }

    /**
     * How much $person still owes the business from loans given to them
     * (loan_given/opening_balance_loan_given, less loan_repayment) - a
     * completely separate bucket from customerReceivableBalance() or
     * otherReceivableBalance(), even though a person can carry all three
     * at once. Shared by TransactionService's overpayment guard and
     * PersonController::show() so neither duplicates this calculation -
     * see either call site before adding a third.
     */
    public function loanGivenBalance(Person $person): string
    {
        return $this->bucketBalance($person->id, ['loan_given', 'opening_balance_loan_given'], ['loan_repayment']);
    }

    /**
     * How much the business still owes $person from loans received from
     * them (loan_received/opening_balance_loan_received, less
     * loan_payment) - the mirror of loanGivenBalance() for the other
     * borrowing direction.
     */
    public function loanReceivedBalance(Person $person): string
    {
        return $this->bucketBalance($person->id, ['loan_received', 'opening_balance_loan_received'], ['loan_payment']);
    }

    /**
     * How much $person still owes the business against an Other
     * Receivable (debt_created/opening_balance_other_receivable, less
     * debt_payment) - see the Loans & Debts page's own "Other Receivable"
     * workflow. Never merged with loanGivenBalance() or
     * customerReceivableBalance(): the three type-string sets are
     * disjoint, so summing each separately can never double-count another.
     */
    public function otherReceivableBalance(Person $person): string
    {
        return $this->bucketBalance($person->id, ['debt_created', 'opening_balance_other_receivable'], ['debt_payment']);
    }

    private function bucketBalance(int $personId, array $increaseTypes, array $decreaseTypes): string
    {
        $increased = (float) Transaction::query()
            ->where('person_id', $personId)
            ->where('status', 'posted')
            ->whereIn('type', $increaseTypes)
            ->sum('amount');

        $decreased = (float) Transaction::query()
            ->where('person_id', $personId)
            ->where('status', 'posted')
            ->whereIn('type', $decreaseTypes)
            ->sum('amount');

        return number_format($increased - $decreased, 2, '.', '');
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
                    ->whereIn('transactions.type', self::RECEIVABLE_TYPES);
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

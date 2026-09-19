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


}

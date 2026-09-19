<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function index(BalanceService $balances): JsonResponse
    {
        $accounts = Account::query()->where('is_active', true)->orderBy('name')->get();

        $accounts->each(fn (Account $account) => $account->setAttribute('current_balance', $balances->accountBalance($account)));

        return response()->json($accounts);
    }
}

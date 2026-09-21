<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(BalanceService $balances): JsonResponse
    {
        $accounts = Account::query()->where('is_active', true)->orderBy('name')->get();

        $accounts->each(fn (Account $account) => $account->setAttribute('current_balance', $balances->accountBalance($account)));

        return response()->json($accounts);
    }

    /**
     * opening_balance is deliberately NOT editable here: BalanceService
     * adds it to every posted transaction's effects to compute the current
     * balance, so changing it after transactions exist would silently
     * shift every historical balance calculation for this account rather
     * than correcting anything going forward.
     */
    public function update(Request $request, Account $account, BalanceService $balances): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $account->update($validated);
        $account->setAttribute('current_balance', $balances->accountBalance($account->fresh()));

        return response()->json([
            'message' => 'Account updated successfully.',
            'account' => $account,
        ]);
    }
}

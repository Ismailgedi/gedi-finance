<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * This route is reachable by anyone who can view_accounts OR create a
     * sale/purchase - a cash sale/purchase needs to record which account
     * the money moved through, so whoever can create one must be able to
     * list account names even without view_accounts itself (see
     * routes/api.php). current_balance is still only ever attached for a
     * caller who actually holds view_accounts - a Sales & Inventory user
     * reachable here only via create_sales/create_purchases must not see
     * cash/bank/mobile-money balances, just the names to pick from.
     */
    public function index(Request $request, BalanceService $balances): JsonResponse
    {
        $accounts = Account::query()->where('is_active', true)->orderBy('name')->get();

        if ($request->user()->can('view_accounts')) {
            $accounts->each(fn (Account $account) => $account->setAttribute('current_balance', $balances->accountBalance($account)));
        }

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

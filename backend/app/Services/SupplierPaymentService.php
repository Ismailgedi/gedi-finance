<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly BalanceService $balances,
    ) {}

    public function pay(Purchase $purchase, array $data, ?int $userId = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $data, $userId) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status !== 'posted') {
                throw ValidationException::withMessages([
                    'purchase' => 'Payments can only be made against a posted purchase.',
                ]);
            }

            $amount = round((float) $data['amount'], 2);
            $this->assertAmountWithinBalance($amount, (float) $purchase->balance_due);

            return $this->applyToPurchase(
                $purchase,
                $amount,
                (int) $data['account_id'],
                $data['currency'] ?? 'USD',
                $data['reference'] ?? null,
                $data['payment_date'] ?? null,
                $userId,
            );
        });
    }

    /**
     * Pays a supplier directly (not tied to one purchase the caller already
     * has open) by applying the amount across that supplier's outstanding
     * purchases oldest-first - what the Dashboard's "Pay Supplier" quick
     * action uses. One DB transaction covering every purchase it touches:
     * the whole payment either applies correctly, or none of it does.
     *
     * The ceiling is the supplier's REAL aggregate balance
     * (BalanceService::supplierBalance() - every type that moves
     * supplier_balance_effect combined), not just what happens to be tied
     * to a Purchase row: an opening payable (see OpeningBalanceService)
     * has no Purchase behind it at all, but is still a real payable this
     * supplier can be paid down against. Any amount left over once every
     * real Purchase is fully settled is applied as one standalone
     * supplier_payment with no purchase_id - the exact same transaction
     * type and effect, never a fabricated Purchase.
     *
     * @return array{purchases: \Illuminate\Support\Collection<int, Purchase>, applied: string, applied_to_opening_balance: string}
     */
    public function payForSupplier(Supplier $supplier, float $amount, int $accountId, ?string $reference, ?int $userId = null): array
    {
        return DB::transaction(function () use ($supplier, $amount, $accountId, $reference, $userId) {
            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment must be greater than zero.',
                ]);
            }

            $outstandingPurchases = Purchase::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', 'posted')
                ->where('balance_due', '>', 0)
                ->orderBy('purchase_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $totalOwed = (float) $this->balances->supplierBalance($supplier);

            if ($totalOwed <= 0) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'This supplier has no outstanding balance to pay.',
                ]);
            }

            if ($amount > $totalOwed + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the total outstanding balance of ' . number_format($totalOwed, 2) . ' owed to this supplier.',
                ]);
            }

            $remaining = $amount;
            $updatedPurchases = collect();

            foreach ($outstandingPurchases as $purchase) {
                if ($remaining <= 0.0001) {
                    break;
                }

                $portion = round(min($remaining, (float) $purchase->balance_due), 2);
                $updatedPurchases->push(
                    $this->applyToPurchase($purchase, $portion, $accountId, 'USD', $reference, null, $userId),
                );
                $remaining = round($remaining - $portion, 2);
            }

            $appliedToOpeningBalance = '0.00';

            if ($remaining > 0.0001) {
                $this->applyStandalone($supplier, $remaining, $accountId, 'USD', $reference, $userId);
                $appliedToOpeningBalance = number_format($remaining, 2, '.', '');
            }

            return [
                'purchases' => $updatedPurchases,
                'applied' => number_format($amount, 2, '.', ''),
                'applied_to_opening_balance' => $appliedToOpeningBalance,
            ];
        });
    }

    private function assertAmountWithinBalance(float $amount, float $balanceDue): void
    {
        if ($amount <= 0 || $amount > $balanceDue + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => 'Payment must be greater than zero and cannot exceed the outstanding balance.',
            ]);
        }
    }

    private function applyToPurchase(
        Purchase $purchase,
        float $amount,
        int $accountId,
        string $currency,
        ?string $reference,
        ?string $paymentDate,
        ?int $userId,
    ): Purchase {
        $supplier = Supplier::query()->findOrFail($purchase->supplier_id);

        $this->transactions->create([
            'type' => TransactionType::SupplierPayment->value,
            'supplier_id' => $supplier->id,
            'purchase_id' => $purchase->id,
            'account_id' => $accountId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => "Payment for {$purchase->purchase_number}",
            'reference' => $reference ?? $purchase->purchase_number,
            'transaction_date' => $paymentDate ?? now(),
        ], $userId, internal: true);

        $newPaid = round((float) $purchase->amount_paid + $amount, 2);
        $newBalance = round((float) $purchase->total - $newPaid, 2);

        $purchase->update([
            'amount_paid' => $newPaid,
            'balance_due' => max(0, $newBalance),
            'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
        ]);

        return $purchase->fresh(['supplier', 'items.product', 'items.productUnit.unit']);
    }

    /**
     * The leftover portion of a payForSupplier() payment once every real
     * Purchase is fully settled - a plain supplier_payment against a
     * non-Purchase-linked payable (an opening balance, or a lingering
     * purchase_return credit), with no purchase_id at all. Same
     * transaction type, same signed effect, same audit trail as every
     * other supplier_payment - this only omits the Purchase link and the
     * Purchase row update, since there is no Purchase to update.
     */
    private function applyStandalone(
        Supplier $supplier,
        float $amount,
        int $accountId,
        string $currency,
        ?string $reference,
        ?int $userId,
    ): void {
        $this->transactions->create([
            'type' => TransactionType::SupplierPayment->value,
            'supplier_id' => $supplier->id,
            'account_id' => $accountId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => "Payment against {$supplier->name}'s outstanding balance (not tied to a specific purchase)",
            'reference' => $reference,
            'transaction_date' => now(),
        ], $userId, internal: true);
    }
}

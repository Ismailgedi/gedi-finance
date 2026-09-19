<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentService
{
    public function __construct(private readonly TransactionService $transactions) {}

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
            $remaining = (float) $purchase->balance_due;

            if ($amount <= 0 || $amount > $remaining + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment must be greater than zero and cannot exceed the outstanding balance.',
                ]);
            }

            $supplier = Supplier::query()->findOrFail($purchase->supplier_id);

            $this->transactions->create([
                'type' => TransactionType::SupplierPayment->value,
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchase->id,
                'account_id' => $data['account_id'],
                'amount' => $amount,
                'currency' => $data['currency'] ?? 'USD',
                'description' => "Payment for {$purchase->purchase_number}",
                'reference' => $data['reference'] ?? $purchase->purchase_number,
                'transaction_date' => $data['payment_date'] ?? now(),
            ], $userId);

            $newPaid = round((float) $purchase->amount_paid + $amount, 2);
            $newBalance = round((float) $purchase->total - $newPaid, 2);

            $purchase->update([
                'amount_paid' => $newPaid,
                'balance_due' => max(0, $newBalance),
                'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
            ]);

            return $purchase->fresh(['supplier', 'items.product', 'items.productUnit.unit']);
        });
    }
}

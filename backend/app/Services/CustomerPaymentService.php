<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Person;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerPaymentService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly BalanceService $balances,
    ) {}

    public function receive(Sale $sale, array $data, ?int $userId = null): Sale
    {
        return DB::transaction(function () use ($sale, $data, $userId) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== 'posted') {
                throw ValidationException::withMessages([
                    'sale' => 'Payments can only be made against a posted sale.',
                ]);
            }

            if (!$sale->customer_id) {
                throw ValidationException::withMessages([
                    'sale' => 'This sale has no customer and cannot receive a customer payment.',
                ]);
            }

            $amount = round((float) $data['amount'], 2);
            $remaining = (float) $sale->balance_due;

            if ($amount <= 0 || $amount > $remaining + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment must be greater than zero and cannot exceed the outstanding balance.',
                ]);
            }

            $customer = Person::query()->findOrFail($sale->customer_id);

            $this->transactions->create([
                'type' => TransactionType::CustomerPayment->value,
                'person_id' => $customer->id,
                'account_id' => $data['account_id'],
                'sale_id' => $sale->id,
                'amount' => $amount,
                'currency' => $data['currency'] ?? 'USD',
                'description' => "Payment for {$sale->invoice_number}",
                'reference' => $data['reference'] ?? $sale->invoice_number,
                'transaction_date' => $data['payment_date'] ?? now(),
            ], $userId);

            $newPaid = round((float) $sale->amount_paid + $amount, 2);
            $newBalance = round((float) $sale->total - $newPaid, 2);

            $sale->update([
                'amount_paid' => $newPaid,
                'balance_due' => max(0, $newBalance),
                'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
            ]);

            return $sale->fresh(['customer', 'items.product', 'items.productUnit.unit']);
        });
    }
}

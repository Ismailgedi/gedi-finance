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

            $this->assertPayable($sale);

            $amount = round((float) $data['amount'], 2);
            $this->assertAmountWithinBalance($amount, (float) $sale->balance_due);

            return $this->applyToSale(
                $sale,
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
     * Receives a single payment FROM A CUSTOMER (not tied to one invoice
     * the caller already has open) and applies it across that customer's
     * outstanding sales oldest-first, exactly like paying down a running
     * account balance - this is what the Dashboard's "Receive Payment"
     * quick action uses. It is one DB transaction covering every sale it
     * touches: the whole payment either applies correctly, or none of it
     * does - there is no partially-applied state to clean up.
     *
     * Each sale still gets its own real customer_payment Transaction row
     * with the correct sale_id, so nothing about the underlying ledger
     * differs from paying a single invoice directly; this only decides
     * which invoice(s) the money is applied to.
     *
     * @return array{sales: \Illuminate\Support\Collection<int, Sale>, applied: string}
     */
    public function receiveForCustomer(Person $customer, float $amount, int $accountId, ?string $reference, ?int $userId = null): array
    {
        return DB::transaction(function () use ($customer, $amount, $accountId, $reference, $userId) {
            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment must be greater than zero.',
                ]);
            }

            $outstandingSales = Sale::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'posted')
                ->where('balance_due', '>', 0)
                ->orderBy('sale_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $totalOwed = (float) $outstandingSales->sum('balance_due');

            if ($totalOwed <= 0) {
                throw ValidationException::withMessages([
                    'customer_id' => 'This customer has no outstanding balance to receive a payment against.',
                ]);
            }

            if ($amount > $totalOwed + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the customer\'s total outstanding balance of ' . number_format($totalOwed, 2) . '.',
                ]);
            }

            $remaining = $amount;
            $updatedSales = collect();

            foreach ($outstandingSales as $sale) {
                if ($remaining <= 0.0001) {
                    break;
                }

                $portion = round(min($remaining, (float) $sale->balance_due), 2);
                $updatedSales->push(
                    $this->applyToSale($sale, $portion, $accountId, 'USD', $reference, null, $userId),
                );
                $remaining = round($remaining - $portion, 2);
            }

            return ['sales' => $updatedSales, 'applied' => number_format($amount, 2, '.', '')];
        });
    }

    private function assertPayable(Sale $sale): void
    {
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
    }

    private function assertAmountWithinBalance(float $amount, float $balanceDue): void
    {
        if ($amount <= 0 || $amount > $balanceDue + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => 'Payment must be greater than zero and cannot exceed the outstanding balance.',
            ]);
        }
    }

    private function applyToSale(
        Sale $sale,
        float $amount,
        int $accountId,
        string $currency,
        ?string $reference,
        ?string $paymentDate,
        ?int $userId,
    ): Sale {
        $customer = Person::query()->findOrFail($sale->customer_id);

        $this->transactions->create([
            'type' => TransactionType::CustomerPayment->value,
            'person_id' => $customer->id,
            'account_id' => $accountId,
            'sale_id' => $sale->id,
            'amount' => $amount,
            'currency' => $currency,
            'description' => "Payment for {$sale->invoice_number}",
            'reference' => $reference ?? $sale->invoice_number,
            'transaction_date' => $paymentDate ?? now(),
        ], $userId);

        $newPaid = round((float) $sale->amount_paid + $amount, 2);
        $newBalance = round((float) $sale->total - $newPaid, 2);

        $sale->update([
            'amount_paid' => $newPaid,
            'balance_due' => max(0, $newBalance),
            'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
        ]);

        return $sale->fresh(['customer', 'items.product', 'items.productUnit.unit']);
    }
}

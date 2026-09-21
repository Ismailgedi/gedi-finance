<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Person;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function create(array $data, ?int $userId = null): Transaction
    {
        return DB::transaction(function () use ($data, $userId) {
            $type = TransactionType::from($data['type']);

            $this->validateBusinessRules($type, $data);

            $amount = $this->normalizeAmount($data['amount']);

            [$personEffect, $accountEffect, $destinationEffect] =
                $this->effectsFor($type, $amount);

            $transaction = Transaction::create([
                'transaction_number' => $data['transaction_number']
                    ?? $this->nextTransactionNumber(),

                'type' => $type,

                'person_id' => $data['person_id'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'destination_account_id' => $data['destination_account_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'loan_id' => $data['loan_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'sale_id' => $data['sale_id'] ?? null,
                'purchase_id' => $data['purchase_id'] ?? null,

                'amount' => $amount,
                'currency' => strtoupper($data['currency'] ?? 'USD'),

                'person_balance_effect' => $personEffect,
                'account_balance_effect' => $accountEffect,
                'destination_account_effect' => $destinationEffect,
                'supplier_balance_effect' => $this->supplierEffect($type, $amount),

                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'status' => $data['status'] ?? 'posted',
                'created_by' => $userId,
            ]);

            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $transaction->items()->create([
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total' => $item['total'],
                    ]);
                }
            }

            return $transaction->load([
                'person',
                'account',
                'destinationAccount',
                'category',
                'loan',
                'items',
            ]);
        });
    }

    /**
     * Flips a single posted transaction to voided so every balance
     * calculation across the app (BalanceService, reports, dashboard - all
     * of which already filter status = 'posted') stops counting its
     * effects, without deleting or mutating the historical row itself. The
     * transaction's signed effects, amount and description are left exactly
     * as recorded - only status changes, preserving the audit trail.
     * Callers (SaleService::void / PurchaseService::void) are responsible
     * for the business-level safety checks (would this make an account
     * balance negative, would this make stock negative) before calling
     * this - it does not re-check those itself.
     */
    public function voidTransaction(Transaction $transaction): Transaction
    {
        if ($transaction->status !== 'posted') {
            throw ValidationException::withMessages([
                'transaction' => "Transaction {$transaction->transaction_number} is not posted and cannot be voided.",
            ]);
        }

        $transaction->update(['status' => 'voided']);

        return $transaction->fresh();
    }

    private function validateBusinessRules(
        TransactionType $type,
        array $data
    ): void {
        // CashSale deliberately excludes person_id: it represents money paid
        // in full at the time of sale (including walk-in customers with no
        // record on file), so nothing is owed and there is no balance to
        // track against a person. CreditSale/CustomerPayment and the loan
        // and debt types all leave an outstanding balance against someone
        // specific, so they still require it.
        $personRequired = in_array($type, [
            TransactionType::CreditSale,
            TransactionType::CustomerPayment,
            TransactionType::LoanGiven,
            TransactionType::LoanRepayment,
            TransactionType::LoanReceived,
            TransactionType::LoanPayment,
            TransactionType::DebtCreated,
            TransactionType::DebtPayment,
        ], true);

        if ($personRequired && empty($data['person_id'])) {
            throw ValidationException::withMessages([
                'person_id' => 'A person is required for this transaction type.',
            ]);
        }

        if (!empty($data['person_id'])) {
            $person = Person::query()->find($data['person_id']);

            if (!$person) {
                throw ValidationException::withMessages([
                    'person_id' => 'The selected person does not exist.',
                ]);
            }

            if (!$person->is_active) {
                throw ValidationException::withMessages([
                    'person_id' => 'The selected person is inactive.',
                ]);
            }
        }

        if ($type === TransactionType::Purchase && empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => 'A supplier is required for a purchase transaction.',
            ]);
        }

        if ($type === TransactionType::SupplierPayment && empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => 'A supplier is required for a supplier payment.',
            ]);
        }

        if (!empty($data['supplier_id'])) {
            $supplier = \App\Models\Supplier::query()->find($data['supplier_id']);

            if (!$supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'The selected supplier does not exist.',
                ]);
            }

            if (!$supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'The selected supplier is inactive.',
                ]);
            }
        }

        $accountRequired = in_array($type, [
            TransactionType::Income,
            TransactionType::Expense,
            TransactionType::CashSale,
            TransactionType::CustomerPayment,
            TransactionType::LoanRepayment,
            TransactionType::LoanReceived,
            TransactionType::LoanPayment,
            TransactionType::AccountTransfer,
            TransactionType::Adjustment,
            TransactionType::SupplierPayment,
        ], true);

        if ($accountRequired && empty($data['account_id'])) {
            throw ValidationException::withMessages([
                'account_id' => 'An account is required for this transaction type.',
            ]);
        }

        if ($type === TransactionType::AccountTransfer) {
            if (empty($data['destination_account_id'])) {
                throw ValidationException::withMessages([
                    'destination_account_id' =>
                        'A destination account is required for an account transfer.',
                ]);
            }
        }

        if (in_array($type, [
            TransactionType::CashSale,
            TransactionType::CreditSale,
            TransactionType::CustomerPayment,
        ], true) && empty($data['description'])) {
            throw ValidationException::withMessages([
                'description' => 'A description is required for this transaction type.',
            ]);
        }
    }

    private function normalizeAmount(string|int|float $amount): string
    {
        $normalized = number_format((float) $amount, 2, '.', '');

        if ((float) $normalized <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function effectsFor(
        TransactionType $type,
        string $amount
    ): array {
        return match ($type) {
            TransactionType::Income => [
                '0.00',
                $amount,
                '0.00',
            ],

            TransactionType::Expense => [
                '0.00',
                '-' . $amount,
                '0.00',
            ],

            TransactionType::CashSale => [
                '0.00',
                $amount,
                '0.00',
            ],

            TransactionType::CreditSale => [
                $amount,
                '0.00',
                '0.00',
            ],

            TransactionType::CustomerPayment => [
                '-' . $amount,
                $amount,
                '0.00',
            ],

            TransactionType::SupplierPayment => [
                '0.00',
                '-' . $amount,
                '0.00',
            ],

            TransactionType::Purchase => [
                '0.00',
                '0.00',
                '0.00',
            ],

            TransactionType::LoanGiven => [
                $amount,
                '-' . $amount,
                '0.00',
            ],

            TransactionType::LoanRepayment => [
                '-' . $amount,
                $amount,
                '0.00',
            ],

            TransactionType::LoanReceived => [
                '-' . $amount,
                $amount,
                '0.00',
            ],

            TransactionType::LoanPayment => [
                $amount,
                '-' . $amount,
                '0.00',
            ],

            TransactionType::DebtCreated => [
                $amount,
                '0.00',
                '0.00',
            ],

            TransactionType::DebtPayment => [
                '-' . $amount,
                $amount,
                '0.00',
            ],

            TransactionType::AccountTransfer => [
                '0.00',
                '-' . $amount,
                $amount,
            ],

            TransactionType::Adjustment => [
                '0.00',
                $amount,
                '0.00',
            ],
        };
    }


    private function supplierEffect(TransactionType $type, string $amount): string
    {
        return match ($type) {
            TransactionType::Purchase => $amount,
            TransactionType::SupplierPayment => '-' . $amount,
            default => '0.00',
        };
    }

    private function nextTransactionNumber(): string
    {
        $today = now()->format('Ymd');
        $prefix = "GEDI-{$today}-";

        $lastNumber = Transaction::query()
            ->where('transaction_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('transaction_number');

        $nextNumber = $lastNumber
            ? ((int) substr($lastNumber, -4)) + 1
            : 1;

        return $prefix . str_pad(
            (string) $nextNumber,
            4,
            '0',
            STR_PAD_LEFT
        );
    }
}

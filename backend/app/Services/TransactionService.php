<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        private readonly FinancialYearCloseService $financialYears,
        private readonly BalanceService $balances,
    ) {
    }

    public function create(array $data, ?int $userId = null, bool $internal = false): Transaction
    {
        return DB::transaction(function () use ($data, $userId, $internal) {
            $type = TransactionType::from($data['type']);

            if (!$internal && !in_array($type, TransactionType::publiclyCreatable(), true)) {
                throw ValidationException::withMessages([
                    'type' => "The transaction type \"{$type->value}\" cannot be created directly. "
                        . 'It must be created through its owning workflow (a sale, purchase, return, '
                        . 'payment, or the Opening Balance setup).',
                ]);
            }

            $this->assertAuthorizedForCapitalMovement($type, $userId);
            $this->validateBusinessRules($type, $data);

            $transactionDate = $data['transaction_date'] ?? now();
            $this->financialYears->assertDatePostable($transactionDate);
            $this->assertNotBeforeOpeningBalance($type, $transactionDate);

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
                'transaction_date' => $transactionDate,
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

            // One entry per Transaction row, covering every type created
            // through this single method (sales, purchases, payments,
            // expenses, income, transfers, loans, other receivables, owner
            // capital, ...) - never called a second time for the same
            // transaction, so there is exactly one 'transaction_created'
            // audit record per row. `person_id` is who/what the transaction
            // is against (e.g. the owner for a capital movement);
            // `created_by` is the authenticated user who recorded it -
            // AuditLog::record's own `user_id` column already captures the
            // latter, but it's included here too so both are visible
            // together in one place without opening the user_id column.
            AuditLog::record('transaction_created', $transaction, null, [
                'transaction_id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'type' => $type->value,
                'amount' => (string) $amount,
                'account_id' => $transaction->account_id,
                'person_id' => $transaction->person_id,
                'transaction_date' => $transaction->transaction_date?->toDateString(),
                'description' => $transaction->description,
                'created_by' => $userId,
            ]);

            return $transaction->load([
                'person',
                'account',
                'destinationAccount',
                'category',
                'supplier',
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
    public function voidTransaction(Transaction $transaction, ?string $reason = null): Transaction
    {
        if ($transaction->status !== 'posted') {
            throw ValidationException::withMessages([
                'transaction' => "Transaction {$transaction->transaction_number} is not posted and cannot be voided.",
            ]);
        }

        $this->financialYears->assertDatePostable($transaction->transaction_date);

        $transaction->update(['status' => 'voided']);

        AuditLog::record('transaction_voided', $transaction, ['status' => 'posted'], [
            'status' => 'voided',
            'reason' => $reason,
        ]);

        return $transaction->fresh();
    }

    /**
     * Once a locked OpeningBalance exists, no ordinary transaction may be
     * dated on or before its as_of_date - that date boundary is exactly
     * what "opening balance" means (the position immediately before Gedi
     * Finance's own transaction history begins), and letting a backdated
     * entry land underneath it would silently invalidate the reconciled
     * opening_equity figure OpeningBalanceService validated at lock time.
     * The opening_balance_* types themselves are exempt - they are the
     * only things ever meant to be dated exactly on that boundary (see
     * OpeningBalanceService::lock(), which posts them there itself).
     *
     * The actual lookup/comparison lives on OpeningBalance::
     * assertDateNotBeforeLock() (a plain model-level query, not a service -
     * OpeningBalanceService itself depends on this class, so injecting it
     * back here would be circular), shared with InventoryAdjustmentService
     * so the exact same rule never needs a second, drift-prone copy.
     */
    private function assertNotBeforeOpeningBalance(TransactionType $type, string|CarbonInterface $date): void
    {
        if (in_array($type, [
            TransactionType::OpeningBalanceReceivable,
            TransactionType::OpeningBalanceOtherReceivable,
            TransactionType::OpeningBalancePayable,
            TransactionType::OpeningBalanceLoanGiven,
            TransactionType::OpeningBalanceLoanReceived,
            TransactionType::OpeningBalanceCapital,
        ], true)) {
            return;
        }

        \App\Models\OpeningBalance::assertDateNotBeforeLock($date);
    }

    /**
     * Owner capital movements directly change equity, unlike every other
     * type this method creates - restricted to Super Admin here (rather
     * than only at the route level) because every transaction type,
     * sensitive or not, is created through this one POST /api/transactions
     * endpoint, so route middleware alone can't tell owner_contribution/
     * owner_withdrawal apart from an ordinary expense by URL.
     */
    private function assertAuthorizedForCapitalMovement(TransactionType $type, ?int $userId): void
    {
        if (!in_array($type, [TransactionType::OwnerContribution, TransactionType::OwnerWithdrawal], true)) {
            return;
        }

        $user = $userId ? User::find($userId) : null;

        if (!$user || !$user->hasRole('Super Admin')) {
            throw new AuthorizationException('Only a Super Admin can record owner capital contributions or withdrawals.');
        }
    }

    /**
     * Refuses a settlement transaction (loan_repayment/loan_payment/
     * debt_payment) whose amount exceeds the ACTUAL outstanding balance in
     * its own bucket - never the person's combined balance, since one
     * person can simultaneously carry a customer receivable, a loan, and
     * an other-receivable, each a completely separate debt. Applies
     * equally to a balance sourced entirely from an opening balance (see
     * OpeningBalanceService) - BalanceService's bucket methods sum both
     * the ordinary and opening_balance_* types together already. Never
     * called for loan_given/loan_received/debt_created: creating a new
     * debt has nothing to be "within," only settling one does.
     */
    private function assertWithinOutstandingBalance(TransactionType $type, Person $person, float $amount): void
    {
        [$outstanding, $label] = match ($type) {
            TransactionType::LoanRepayment => [(float) $this->balances->loanGivenBalance($person), 'Loan Given'],
            TransactionType::LoanPayment => [(float) $this->balances->loanReceivedBalance($person), 'Loan Received'],
            TransactionType::DebtPayment => [(float) $this->balances->otherReceivableBalance($person), 'Other Receivable'],
            default => [null, null],
        };

        if ($outstanding === null) {
            return;
        }

        if ($amount > $outstanding + 0.01) {
            throw ValidationException::withMessages([
                'amount' => "This amount exceeds the outstanding {$label} balance of "
                    . number_format(max(0.0, $outstanding), 2) . ' for this person.',
            ]);
        }
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
            TransactionType::OwnerContribution,
            TransactionType::OwnerWithdrawal,
            TransactionType::OpeningBalanceReceivable,
            TransactionType::OpeningBalanceOtherReceivable,
            TransactionType::OpeningBalanceLoanGiven,
            TransactionType::OpeningBalanceLoanReceived,
            TransactionType::OpeningBalanceCapital,
        ], true);

        if ($personRequired && empty($data['person_id'])) {
            throw ValidationException::withMessages([
                'person_id' => $type === TransactionType::OwnerContribution || $type === TransactionType::OwnerWithdrawal
                    ? 'An owner is required for this transaction type.'
                    : 'A person is required for this transaction type.',
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

            if (
                in_array($type, [TransactionType::OwnerContribution, TransactionType::OwnerWithdrawal, TransactionType::OpeningBalanceCapital], true)
                && !$person->is_owner
            ) {
                throw ValidationException::withMessages([
                    'person_id' => 'The selected person is not marked as a business owner.',
                ]);
            }

            $this->assertWithinOutstandingBalance($type, $person, (float) ($data['amount'] ?? 0));
        }

        if ($type === TransactionType::Purchase && empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => 'A supplier is required for a purchase transaction.',
            ]);
        }

        if ($type === TransactionType::PurchaseCost && empty($data['purchase_id'])) {
            throw ValidationException::withMessages([
                'purchase_id' => 'A purchase is required for a purchase cost transaction.',
            ]);
        }

        if (
            in_array($type, [TransactionType::SaleReturn, TransactionType::SaleReturnRefund], true)
            && empty($data['sale_id'])
        ) {
            throw ValidationException::withMessages([
                'sale_id' => 'A sale is required for this transaction type.',
            ]);
        }

        if (
            in_array($type, [TransactionType::PurchaseReturn, TransactionType::PurchaseReturnRefund], true)
            && empty($data['purchase_id'])
        ) {
            throw ValidationException::withMessages([
                'purchase_id' => 'A purchase is required for this transaction type.',
            ]);
        }

        if ($type === TransactionType::SupplierPayment && empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => 'A supplier is required for a supplier payment.',
            ]);
        }

        if ($type === TransactionType::OpeningBalancePayable && empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => 'A supplier is required for an opening balance payable.',
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
            TransactionType::OwnerContribution,
            TransactionType::OwnerWithdrawal,
            TransactionType::PurchaseCost,
            TransactionType::SaleReturnRefund,
            TransactionType::PurchaseReturnRefund,
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

            // Reduces the receivable (or, past zero, creates a customer
            // credit) - see the enum case doc. Never touches an account by
            // itself; SaleReturnRefund is the only type that does.
            TransactionType::SaleReturn => [
                '-' . $amount,
                '0.00',
                '0.00',
            ],

            // Settles a customer credit created by SaleReturn back toward
            // zero (person effect is positive here) while paying real cash
            // out - the same shape as CustomerPayment settling a receivable,
            // just for a credit instead of a debt.
            TransactionType::SaleReturnRefund => [
                $amount,
                '-' . $amount,
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

            // Owner equity, not a receivable/payable against the owner as
            // a person - person_effect stays 0 so this never leaks into
            // personBalance() or the customer/supplier breakdowns.
            TransactionType::OwnerContribution => [
                '0.00',
                $amount,
                '0.00',
            ],

            TransactionType::OwnerWithdrawal => [
                '0.00',
                '-' . $amount,
                '0.00',
            ],

            // Same account effect as an expense (money leaving the account)
            // but a distinct type so BusinessReportService::profit()'s
            // operating_expenses query - which filters type = 'expense' -
            // never picks it up. See the enum case doc for why.
            TransactionType::PurchaseCost => [
                '0.00',
                '-' . $amount,
                '0.00',
            ],

            // Purely a supplier_balance_effect (below) - never a
            // person/account effect, same as Purchase/SupplierPayment.
            TransactionType::PurchaseReturn => [
                '0.00',
                '0.00',
                '0.00',
            ],

            TransactionType::PurchaseReturnRefund => [
                '0.00',
                $amount,
                '0.00',
            ],

            // Mirrors CreditSale's person effect only - never an account
            // effect, since an opening receivable never represents fresh
            // cash movement.
            TransactionType::OpeningBalanceReceivable => [
                $amount,
                '0.00',
                '0.00',
            ],

            // Mirrors DebtCreated's person effect only. Deliberately a
            // distinct type from DebtCreated so BusinessReportService::
            // profit()'s other_income query (which filters
            // type = 'debt_created' exactly) never recognizes this as
            // current-year income.
            TransactionType::OpeningBalanceOtherReceivable => [
                $amount,
                '0.00',
                '0.00',
            ],

            // Purely a supplier_balance_effect (below), like Purchase.
            TransactionType::OpeningBalancePayable => [
                '0.00',
                '0.00',
                '0.00',
            ],

            // Mirrors LoanGiven's person effect only (person owes the
            // business) - no account effect, no fresh cash left the
            // business.
            TransactionType::OpeningBalanceLoanGiven => [
                $amount,
                '0.00',
                '0.00',
            ],

            // Mirrors LoanReceived's person effect only (the business owes
            // the person) - no account effect, no fresh cash came in.
            TransactionType::OpeningBalanceLoanReceived => [
                '-' . $amount,
                '0.00',
                '0.00',
            ],

            // Owner equity, exactly like OwnerContribution - but ALSO no
            // account effect (OwnerContribution's account effect represents
            // fresh cash coming in; an opening allocation is purely
            // attribution of an already-existing balance to a specific
            // owner, so nothing here should double count what the locked
            // OpeningBalance record's own `opening_equity` already
            // contributes - see BusinessCapitalService::
            // cumulativeEquityAsOf()). person_id is still recorded (which
            // owner), it just never affects any balance sum.
            TransactionType::OpeningBalanceCapital => [
                '0.00',
                '0.00',
                '0.00',
            ],
        };
    }


    private function supplierEffect(TransactionType $type, string $amount): string
    {
        return match ($type) {
            TransactionType::Purchase => $amount,
            TransactionType::SupplierPayment => '-' . $amount,
            // Reduces the payable (or, past zero, creates a supplier
            // credit) - the mirror of SaleReturn on the person side.
            TransactionType::PurchaseReturn => '-' . $amount,
            // Settles a supplier credit back toward zero while cash comes
            // IN from the supplier - the mirror of SaleReturnRefund.
            TransactionType::PurchaseReturnRefund => $amount,
            // Mirrors Purchase's supplier effect only - an opening payable,
            // never a fresh purchase.
            TransactionType::OpeningBalancePayable => $amount,
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

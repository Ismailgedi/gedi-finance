<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly InventoryService $inventory,
        private readonly BalanceService $balances,
    ) {}

    public function create(array $data, ?int $userId = null): Sale
    {
        return DB::transaction(function () use ($data, $userId) {
            $customer = null;
            if (!empty($data['customer_id'])) {
                $customer = Person::query()
                    ->where('id', $data['customer_id'])
                    ->where('is_active', true)
                    ->where('is_customer', true)
                    ->first();

                if (!$customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'The selected customer does not exist, is inactive, or is not marked as a customer.',
                    ]);
                }
            }

            $items = $data['items'] ?? [];
            if (count($items) === 0) {
                throw ValidationException::withMessages([
                    'items' => 'A sale must contain at least one item.',
                ]);
            }

            $prepared = [];
            $subtotal = 0.0;

            foreach ($items as $index => $item) {
                $product = Product::query()
                    ->where('id', $item['product_id'])
                    ->where('is_active', true)
                    ->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        "items.$index.product_id" => 'The selected product does not exist or is inactive.',
                    ]);
                }

                $quantity = (float) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $discount = (float) ($item['discount'] ?? 0);

                if ($quantity <= 0 || $unitPrice < 0 || $discount < 0) {
                    throw ValidationException::withMessages([
                        "items.$index" => 'Quantity must be positive and price/discount cannot be negative.',
                    ]);
                }

                $resolved = $this->inventory->baseQuantity(
                    $product,
                    $quantity,
                    $item['product_unit_id'] ?? null,
                );

                $lineTotal = max(0, ($quantity * $unitPrice) - $discount);
                $subtotal += $lineTotal;

                $prepared[] = [
                    'product' => $product,
                    'product_unit_id' => $item['product_unit_id'] ?? null,
                    'quantity' => $quantity,
                    'base_quantity' => $resolved['base_quantity'],
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'line_total' => $lineTotal,
                ];
            }

            $invoiceDiscount = (float) ($data['discount'] ?? 0);
            if ($invoiceDiscount < 0 || $invoiceDiscount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount' => 'Invoice discount must be between zero and the subtotal.',
                ]);
            }

            $total = round($subtotal - $invoiceDiscount, 2);
            $amountPaid = round((float) ($data['amount_paid'] ?? 0), 2);

            if ($amountPaid < 0 || $amountPaid > $total) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Amount paid cannot be negative or greater than the sale total.',
                ]);
            }

            if (!$customer && $amountPaid < $total) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required for unpaid or partially paid sales.',
                ]);
            }

            if ($amountPaid >= $total && empty($data['account_id'])) {
                throw ValidationException::withMessages([
                    'account_id' => 'An account is required for a fully paid cash sale.',
                ]);
            }

            if ($customer && $total > $amountPaid && $customer->credit_limit !== null) {
                $existing = (float) $this->balances->customerReceivableBalance($customer);
                $newBalance = $existing + ($total - $amountPaid);

                if ($newBalance > (float) $customer->credit_limit + 0.0001) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'This sale would exceed the customer credit limit.',
                    ]);
                }
            }

            $sale = Sale::create([
                'invoice_number' => $data['invoice_number'] ?? $this->nextInvoiceNumber(),
                'customer_id' => $customer?->id,
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $invoiceDiscount,
                'total' => $total,
                'amount_paid' => $amountPaid,
                'balance_due' => round($total - $amountPaid, 2),
                'payment_status' => $amountPaid <= 0 ? 'unpaid' : ($amountPaid >= $total ? 'paid' : 'partial'),
                'status' => 'posted',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $costOfGoodsSold = 0.0;

            foreach ($prepared as $item) {
                $unitCost = $this->inventory->weightedAverageCost($item['product']);

                if ($unitCost === null) {
                    throw ValidationException::withMessages([
                        'items' => "No inventory cost is available for {$item['product']->name}.",
                    ]);
                }

                $costTotal = round((float) $item['base_quantity'] * (float) $unitCost, 2);

                $sale->items()->create([
                    'product_id' => $item['product']->id,
                    'product_unit_id' => $item['product_unit_id'],
                    'quantity' => $item['quantity'],
                    'base_quantity' => $item['base_quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $unitCost,
                    'cost_total' => $costTotal,
                    'discount' => $item['discount'],
                    'line_total' => $item['line_total'],
                ]);

                $this->inventory->record(
                    $item['product'],
                    $item['quantity'],
                    $item['product_unit_id'],
                    'sale',
                    'sale',
                    $sale->id,
                    null,
                    "Invoice {$sale->invoice_number}",
                    $userId,
                    'out',
                    $unitCost,
                );

                $costOfGoodsSold += $costTotal;
            }

            $grossProfit = round($total - $costOfGoodsSold, 2);

            $sale->update([
                'cost_of_goods_sold' => round($costOfGoodsSold, 2),
                'gross_profit' => $grossProfit,
            ]);

            $transactionType = $amountPaid >= $total
                ? TransactionType::CashSale
                : TransactionType::CreditSale;

            // A partial payment is represented as a credit sale for the full invoice,
            // followed by a separate customer payment below.
            if ($amountPaid > 0 && $amountPaid < $total) {
                $transactionType = TransactionType::CreditSale;
            }

            $this->transactions->create([
                'type' => $transactionType->value,
                'person_id' => $customer?->id,
                'account_id' => $amountPaid >= $total ? $data['account_id'] : null,
                'sale_id' => $sale->id,
                'amount' => $total,
                'currency' => $data['currency'] ?? 'USD',
                'description' => "Sale {$sale->invoice_number}",
                'reference' => $sale->invoice_number,
                'transaction_date' => $data['sale_date'] ?? now(),
            ], $userId);

            if ($amountPaid > 0 && $amountPaid < $total) {
                if (empty($data['account_id'])) {
                    throw ValidationException::withMessages([
                        'account_id' => 'An account is required when a sale has a payment.',
                    ]);
                }

                $this->transactions->create([
                    'type' => TransactionType::CustomerPayment->value,
                    'person_id' => $customer->id,
                    'account_id' => $data['account_id'],
                    'sale_id' => $sale->id,
                    'amount' => $amountPaid,
                    'currency' => $data['currency'] ?? 'USD',
                    'description' => "Payment for {$sale->invoice_number}",
                    'reference' => $sale->invoice_number,
                    'transaction_date' => $data['sale_date'] ?? now(),
                ], $userId);
            }

            return $sale->load(['customer', 'items.product.baseUnit', 'items.productUnit.unit']);
        });
    }

    /**
     * Voids a posted sale: the row and every linked transaction stay in the
     * database, only their `status` changes, so the audit trail is intact
     * and every balance calculation (which already filters status='posted')
     * automatically stops counting them - no separate reversal math needed
     * for the money side. Inventory is reversed by inserting a new
     * compensating 'in' movement (never by deleting the original 'out'
     * movement), at the exact unit_cost recorded on the original sale item,
     * so the reversal exactly cancels what was removed regardless of how
     * the weighted-average cost has moved since.
     *
     * Refuses to void (rather than silently reversing) when doing so would
     * drive an account's balance negative - the concrete, checkable sign
     * that money collected against this sale has already been spent
     * elsewhere, which is exactly the "already received a payment and it's
     * no longer safely reversible" scenario this guard exists for.
     */
    public function void(Sale $sale, ?string $reason, ?int $userId = null): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $userId) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== 'posted') {
                throw ValidationException::withMessages([
                    'sale' => 'Only a posted sale can be voided.',
                ]);
            }

            $linkedTransactions = Transaction::query()
                ->where('sale_id', $sale->id)
                ->where('status', 'posted')
                ->lockForUpdate()
                ->get();

            // Safety check: every linked transaction that brought money IN
            // (cash_sale or customer_payment, both with a positive
            // account_balance_effect) would, if reversed, reduce that
            // account's balance. If the account no longer holds enough to
            // absorb that reduction, the money has already been used
            // elsewhere and an automatic void would silently create a
            // negative balance - refuse instead.
            foreach ($linkedTransactions as $transaction) {
                if (!$transaction->account_id || (float) $transaction->account_balance_effect <= 0) {
                    continue;
                }

                $account = Account::query()->lockForUpdate()->find($transaction->account_id);
                if (!$account) {
                    continue;
                }

                $currentBalance = (float) $this->balances->accountBalance($account);
                $projectedBalance = $currentBalance - (float) $transaction->account_balance_effect;

                if ($projectedBalance < -0.005) {
                    throw ValidationException::withMessages([
                        'sale' => "Voiding this sale would reduce {$account->name}'s balance to "
                            . number_format($projectedBalance, 2)
                            . ", because money from this sale has already been used elsewhere. "
                            . "Resolve the account balance manually before voiding.",
                    ]);
                }
            }

            $sale->load('items.product');

            foreach ($sale->items as $item) {
                $this->inventory->record(
                    $item->product,
                    $item->quantity,
                    $item->product_unit_id,
                    'sale_void',
                    'sale_void',
                    $sale->id,
                    "Reversal of sale {$sale->invoice_number}",
                    $reason,
                    $userId,
                    'in',
                    $item->unit_cost,
                );
            }

            foreach ($linkedTransactions as $transaction) {
                $this->transactions->voidTransaction($transaction);
            }

            $sale->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $userId,
                'void_reason' => $reason,
            ]);

            return $sale->fresh(['customer', 'items.product.baseUnit', 'items.productUnit.unit', 'voidedBy']);
        });
    }

    private function nextInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ymd') . '-';
        $last = Sale::query()
            ->where('invoice_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('invoice_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}

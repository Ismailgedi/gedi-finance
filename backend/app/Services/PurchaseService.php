<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly InventoryService $inventory,
        private readonly BalanceService $balances,
    ) {}

    public function create(array $data, ?int $userId = null): Purchase
    {
        return DB::transaction(function () use ($data, $userId) {
            $supplier = Supplier::query()
                ->where('id', $data['supplier_id'])
                ->where('is_active', true)
                ->first();

            if (!$supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'The selected supplier does not exist or is inactive.',
                ]);
            }

            $items = $data['items'] ?? [];
            if (count($items) === 0) {
                throw ValidationException::withMessages([
                    'items' => 'A purchase must contain at least one item.',
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
                $unitCost = (float) $item['unit_cost'];

                if ($quantity <= 0 || $unitCost < 0) {
                    throw ValidationException::withMessages([
                        "items.$index" => 'Quantity must be positive and cost cannot be negative.',
                    ]);
                }

                $resolved = $this->inventory->baseQuantity(
                    $product,
                    $quantity,
                    $item['product_unit_id'] ?? null,
                );

                $lineTotal = round($quantity * $unitCost, 2);
                $subtotal += $lineTotal;

                $prepared[] = [
                    'product' => $product,
                    'product_unit_id' => $item['product_unit_id'] ?? null,
                    'quantity' => $quantity,
                    'base_quantity' => $resolved['base_quantity'],
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ];
            }

            $discount = (float) ($data['discount'] ?? 0);
            if ($discount < 0 || $discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount' => 'Discount must be between zero and the subtotal.',
                ]);
            }

            $total = round($subtotal - $discount, 2);
            $amountPaid = round((float) ($data['amount_paid'] ?? 0), 2);

            if ($amountPaid < 0 || $amountPaid > $total) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Amount paid cannot be negative or greater than the purchase total.',
                ]);
            }

            if ($amountPaid > 0 && empty($data['account_id'])) {
                throw ValidationException::withMessages([
                    'account_id' => 'An account is required when a purchase has a payment.',
                ]);
            }

            if ($total > $amountPaid && $supplier->credit_limit !== null) {
                $existing = (float) $this->balances->supplierBalance($supplier);
                $newBalance = $existing + ($total - $amountPaid);

                if ($newBalance > (float) $supplier->credit_limit + 0.0001) {
                    throw ValidationException::withMessages([
                        'supplier_id' => 'This purchase would exceed the supplier credit limit.',
                    ]);
                }
            }

            $purchase = Purchase::create([
                'purchase_number' => $data['purchase_number'] ?? $this->nextPurchaseNumber(),
                'supplier_id' => $supplier->id,
                'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'amount_paid' => $amountPaid,
                'balance_due' => round($total - $amountPaid, 2),
                'payment_status' => $amountPaid <= 0 ? 'unpaid' : ($amountPaid >= $total ? 'paid' : 'partial'),
                'status' => 'posted',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($prepared as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product']->id,
                    'product_unit_id' => $item['product_unit_id'],
                    'quantity' => $item['quantity'],
                    'base_quantity' => $item['base_quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $item['line_total'],
                ]);

                $this->inventory->record(
                    $item['product'],
                    $item['quantity'],
                    $item['product_unit_id'],
                    'purchase',
                    'purchase',
                    $purchase->id,
                    null,
                    "Purchase {$purchase->purchase_number}",
                    $userId,
                    'in',
                    $item['unit_cost'],
                );
            }

            $this->transactions->create([
                'type' => TransactionType::Purchase->value,
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchase->id,
                'amount' => $total,
                'currency' => $data['currency'] ?? 'USD',
                'description' => "Purchase {$purchase->purchase_number}",
                'reference' => $purchase->purchase_number,
                'transaction_date' => $data['purchase_date'] ?? now(),
            ], $userId);

            if ($amountPaid > 0) {
                if (empty($data['account_id'])) {
                    throw ValidationException::withMessages([
                        'account_id' => 'An account is required when a purchase has a payment.',
                    ]);
                }

                $this->transactions->create([
                    'type' => TransactionType::SupplierPayment->value,
                    'supplier_id' => $supplier->id,
                    'purchase_id' => $purchase->id,
                    'account_id' => $data['account_id'],
                    'amount' => $amountPaid,
                    'currency' => $data['currency'] ?? 'USD',
                    'description' => "Payment for {$purchase->purchase_number}",
                    'reference' => $purchase->purchase_number,
                    'transaction_date' => $data['purchase_date'] ?? now(),
                ], $userId);
            }

            return $purchase->load(['supplier', 'items.product.baseUnit', 'items.productUnit.unit']);
        });
    }

    /**
     * Voids a posted purchase: mirrors SaleService::void() - the row and
     * its linked transactions stay in the database with only `status`
     * changing, and inventory is reversed via a new compensating 'out'
     * movement at the purchase item's original unit_cost.
     *
     * The risk profile here is the mirror image of a sale void: reversing
     * a purchase's linked supplier_payment (if any) only ever INCREASES an
     * account's balance (money that was paid out is un-paid), which can
     * never go negative, so no account safety check is needed. The real
     * danger is inventory: this purchase's stock may have already been
     * sold or used elsewhere since, so removing it now could drive current
     * stock negative - refuse per line item where that would happen rather
     * than letting InventoryService throw partway through and leave a
     * half-reversed purchase (the whole method is wrapped in one DB
     * transaction, but checking up front gives a much clearer error).
     */
    public function void(Purchase $purchase, ?string $reason, ?int $userId = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $reason, $userId) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status !== 'posted') {
                throw ValidationException::withMessages([
                    'purchase' => 'Only a posted purchase can be voided.',
                ]);
            }

            $purchase->load('items.product');

            foreach ($purchase->items as $item) {
                $resolved = $this->inventory->baseQuantity($item->product, $item->quantity, $item->product_unit_id);
                $available = (float) $this->inventory->currentStock($item->product);

                if ((float) $resolved['base_quantity'] > $available + 0.00005) {
                    throw ValidationException::withMessages([
                        'purchase' => "Cannot void this purchase: {$item->product->name} would need "
                            . number_format((float) $resolved['base_quantity'], 4)
                            . " removed from stock, but only " . number_format($available, 4)
                            . " remain. Some of this stock has already been sold or used elsewhere - "
                            . "resolve this manually before voiding.",
                    ]);
                }
            }

            $linkedTransactions = Transaction::query()
                ->where('purchase_id', $purchase->id)
                ->where('status', 'posted')
                ->lockForUpdate()
                ->get();

            foreach ($purchase->items as $item) {
                $this->inventory->record(
                    $item->product,
                    $item->quantity,
                    $item->product_unit_id,
                    'purchase_void',
                    'purchase_void',
                    $purchase->id,
                    "Reversal of purchase {$purchase->purchase_number}",
                    $reason,
                    $userId,
                    'out',
                    $item->unit_cost,
                );
            }

            foreach ($linkedTransactions as $transaction) {
                $this->transactions->voidTransaction($transaction);
            }

            $purchase->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $userId,
                'void_reason' => $reason,
            ]);

            return $purchase->fresh(['supplier', 'items.product.baseUnit', 'items.productUnit.unit', 'voidedBy']);
        });
    }

    private function nextPurchaseNumber(): string
    {
        $prefix = 'PUR-' . now()->format('Ymd') . '-';
        $last = Purchase::query()
            ->where('purchase_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('purchase_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}

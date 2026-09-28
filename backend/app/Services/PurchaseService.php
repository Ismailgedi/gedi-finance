<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAdditionalCost;
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

            // Landed costs (transport, customs, ...): every cost line
            // contributes to inventory value regardless of who it's owed
            // to, but only supplier_bundled costs are owed to the supplier
            // and therefore inflate the purchase total/payable -
            // third_party costs are paid separately and never touch it.
            $additionalCosts = $this->prepareAdditionalCosts($data['additional_costs'] ?? []);
            $totalAdditionalCosts = array_sum(array_column($additionalCosts, 'amount'));
            $supplierBundledTotal = array_sum(array_column(
                array_filter($additionalCosts, fn (array $cost) => $cost['type'] === 'supplier_bundled'),
                'amount',
            ));

            // Allocated by value (each item's share of the goods subtotal) -
            // the only allocation basis the current data model supports,
            // since products carry no weight/volume field. Falls back to an
            // even split across items in the (rare) case every item is
            // free, so this never divides by zero.
            //
            // The purchase-level $discount is allocated across items the
            // exact same way, then SUBTRACTED rather than added - the
            // business only actually paid/owes ($subtotal - $discount) for
            // the goods themselves, so that's what should be capitalized
            // into inventory, mirroring how additional costs (a basis
            // increase) are already folded in. Before this, a discount
            // reduced the supplier payable ($total below) but never the
            // capitalized cost, permanently overstating inventory value and
            // every future sale's COGS by the discount amount - see the
            // accounting audit's H3 finding.
            $itemCount = count($prepared);
            foreach ($prepared as &$item) {
                $discountShare = $discount <= 0
                    ? 0.0
                    : ($subtotal > 0.0001
                        ? ($item['line_total'] / $subtotal) * $discount
                        : $discount / $itemCount);

                $allocatedShare = $totalAdditionalCosts <= 0
                    ? 0.0
                    : ($subtotal > 0.0001
                        ? ($item['line_total'] / $subtotal) * $totalAdditionalCosts
                        : $totalAdditionalCosts / $itemCount);

                $item['landed_unit_cost'] = round(
                    $item['unit_cost'] - ($discountShare / (float) $item['base_quantity']) + ($allocatedShare / (float) $item['base_quantity']),
                    4,
                );
            }
            unset($item);

            $total = round($subtotal - $discount + $supplierBundledTotal, 2);
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
                    'landed_unit_cost' => $item['landed_unit_cost'],
                    'line_total' => $item['line_total'],
                ]);

                // The landed unit cost (purchase price + its allocated
                // share of any additional costs) is what actually gets
                // capitalized into inventory - InventoryService itself is
                // completely unaware landed costs exist, it just costs the
                // movement at whatever unitCost it's given, exactly like
                // any other purchase.
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
                    $item['landed_unit_cost'],
                    occurredAt: $purchase->purchase_date,
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
            ], $userId, internal: true);

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
                ], $userId, internal: true);
            }

            foreach ($additionalCosts as $cost) {
                // supplier_bundled: already folded into $total above - no
                // separate cash transaction is ever created for it, it's
                // owed to the supplier as part of the one Purchase
                // transaction already posted.
                $transactionId = null;

                if ($cost['type'] === 'third_party') {
                    $costTransaction = $this->transactions->create([
                        'type' => TransactionType::PurchaseCost->value,
                        'purchase_id' => $purchase->id,
                        'account_id' => $cost['account_id'],
                        'category_id' => $cost['category_id'] ?? null,
                        'amount' => $cost['amount'],
                        'currency' => $data['currency'] ?? 'USD',
                        'description' => "{$cost['description']} for Purchase {$purchase->purchase_number}",
                        'reference' => $purchase->purchase_number,
                        'transaction_date' => $data['purchase_date'] ?? now(),
                    ], $userId, internal: true);

                    $transactionId = $costTransaction->id;
                }

                PurchaseAdditionalCost::create([
                    'purchase_id' => $purchase->id,
                    'description' => $cost['description'],
                    'amount' => $cost['amount'],
                    'type' => $cost['type'],
                    'account_id' => $cost['account_id'] ?? null,
                    'category_id' => $cost['category_id'] ?? null,
                    'transaction_id' => $transactionId,
                    'created_by' => $userId,
                ]);
            }

            return $purchase->load([
                'supplier',
                'items.product.baseUnit',
                'items.productUnit.unit',
                'additionalCosts.account',
                'additionalCosts.category',
            ]);
        });
    }

    /**
     * @return array<int, array{description: string, amount: float, type: string, account_id: ?int, category_id: ?int}>
     */
    private function prepareAdditionalCosts(array $costs): array
    {
        $prepared = [];

        foreach ($costs as $index => $cost) {
            $type = $cost['type'] ?? null;

            if (!in_array($type, ['supplier_bundled', 'third_party'], true)) {
                throw ValidationException::withMessages([
                    "additional_costs.$index.type" => 'Type must be supplier_bundled or third_party.',
                ]);
            }

            $amount = (float) ($cost['amount'] ?? 0);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    "additional_costs.$index.amount" => 'Additional cost amount must be greater than zero.',
                ]);
            }

            // A third-party cost must be paid immediately from a selected
            // account at the time the purchase is recorded - the system
            // has no generic accounts-payable concept for an arbitrary
            // third party (only suppliers and, separately, loans/debts
            // against a Person), so an unpaid third-party landed cost is
            // not yet supported. Rather than silently misclassifying it,
            // this rejects it outright.
            if ($type === 'third_party' && empty($cost['account_id'])) {
                throw ValidationException::withMessages([
                    "additional_costs.$index.account_id" =>
                        'An account is required for a third-party additional cost - it must be paid immediately.',
                ]);
            }

            $prepared[] = [
                'description' => (string) ($cost['description'] ?? ''),
                'amount' => round($amount, 2),
                'type' => $type,
                'account_id' => $type === 'third_party' ? (int) $cost['account_id'] : null,
                'category_id' => !empty($cost['category_id']) ? (int) $cost['category_id'] : null,
            ];
        }

        return $prepared;
    }

    /**
     * Voids a posted purchase: mirrors SaleService::void() - the row and
     * its linked transactions stay in the database with only `status`
     * changing, and inventory is reversed via a new compensating 'out'
     * movement at the purchase item's landed_unit_cost (falling back to
     * unit_cost for a purchase recorded before this column existed) -
     * exactly the value that was actually capitalized into inventory at
     * purchase time, so the reversal exactly cancels it regardless of
     * whether the purchase had any additional costs.
     *
     * Every posted Transaction linked to this purchase (the main Purchase
     * transaction, any SupplierPayment, and now any third-party PurchaseCost
     * transactions - all found by purchase_id, not by type) is voided the
     * same way it always was, with no extra code needed: voiding a
     * PurchaseCost transaction un-decreases the account it was paid from,
     * exactly like un-paying a supplier payment.
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

            // Weighted-average inventory pools every purchase of a product
            // together with no per-purchase provenance - there is no way to
            // tell "these particular units are still this purchase's" once
            // anything else has touched the pool. Comparing this purchase's
            // original quantity against the product's CURRENT pooled stock
            // (the old guard) is only a safe proxy when NOTHING has left
            // inventory since - a later, unrelated purchase or adjustment
            // can replenish the pool and make that comparison pass even
            // though part of THIS purchase's own contribution was already
            // consumed, silently corrupting the remaining weighted-average
            // cost once voided (see the accounting audit's C4 finding).
            //
            // The safe rule instead: once ANY unit of this product has left
            // inventory (a sale, a purchase return back to a supplier, or a
            // negative adjustment) at any point after this purchase's own
            // "in" movement was recorded, this purchase can no longer be
            // voided - regardless of what current pooled stock looks like.
            // This is deliberately conservative rather than attempting
            // item-level lot tracking, per the audit's own recommendation.
            // A purchase return against this purchase is caught by the same
            // check, since PurchaseReturnService's own inventory reversal
            // is itself an "out" movement for the same product.
            foreach ($purchase->items as $item) {
                $ownMovementId = InventoryMovement::query()
                    ->where('reference_type', 'purchase')
                    ->where('reference_id', $purchase->id)
                    ->where('product_id', $item->product_id)
                    ->max('id');

                $consumedSince = InventoryMovement::query()
                    ->where('product_id', $item->product_id)
                    ->where('base_quantity', '<', 0)
                    ->where('id', '>', $ownMovementId ?? 0)
                    ->exists();

                if ($consumedSince) {
                    throw ValidationException::withMessages([
                        'purchase' => "Cannot void this purchase: stock of {$item->product->name} has already "
                            . 'left inventory (sold, returned to the supplier, or adjusted down) since this '
                            . 'purchase was recorded. Once any of a purchase\'s stock has moved, voiding it '
                            . 'could corrupt the remaining weighted-average cost - resolve this manually instead.',
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
                    $item->landed_unit_cost ?? $item->unit_cost,
                );
            }

            foreach ($linkedTransactions as $transaction) {
                $this->transactions->voidTransaction($transaction, $reason);
            }

            $purchase->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $userId,
                'void_reason' => $reason,
            ]);

            return $purchase->fresh([
                'supplier',
                'items.product.baseUnit',
                'items.productUnit.unit',
                'additionalCosts.account',
                'additionalCosts.category',
                'voidedBy',
            ]);
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

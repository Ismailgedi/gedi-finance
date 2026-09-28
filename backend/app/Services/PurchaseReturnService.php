<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A supplier return against part or all of a specific, already-posted
 * purchase - the mirror of SaleReturnService. Reduces the supplier payable
 * (or, past zero, creates a supplier credit); only the excess beyond what
 * was still outstanding becomes a real cash refund from the supplier,
 * capped by the same "would this drive stock negative" guard
 * PurchaseService::void() already uses, since the returned batch may have
 * already been partially sold.
 */
class PurchaseReturnService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly InventoryService $inventory,
        private readonly FinancialYearCloseService $financialYears,
    ) {
    }

    public function create(array $data, ?int $userId = null): PurchaseReturn
    {
        return DB::transaction(function () use ($data, $userId) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($data['purchase_id']);

            if ($purchase->status !== 'posted') {
                throw ValidationException::withMessages([
                    'purchase' => 'Only a posted purchase can have items returned. This purchase is voided.',
                ]);
            }

            $returnDate = $data['return_date'] ?? now()->toDateString();
            $this->financialYears->assertDatePostable($returnDate);

            $items = $data['items'] ?? [];
            if (count($items) === 0) {
                throw ValidationException::withMessages([
                    'items' => 'A return must contain at least one item.',
                ]);
            }

            $purchase->load('items.product');

            $prepared = [];
            $totalValue = 0.0;
            $totalQuantity = 0.0;

            foreach ($items as $index => $itemData) {
                /** @var PurchaseItem|null $purchaseItem */
                $purchaseItem = $purchase->items->firstWhere('id', $itemData['purchase_item_id']);

                if (!$purchaseItem) {
                    throw ValidationException::withMessages([
                        "items.$index.purchase_item_id" => 'This item does not belong to the selected purchase.',
                    ]);
                }

                $quantity = (float) $itemData['quantity'];
                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Return quantity must be greater than zero.',
                    ]);
                }

                $alreadyReturned = (float) PurchaseReturnItem::query()
                    ->where('purchase_item_id', $purchaseItem->id)
                    ->sum('quantity');

                $remaining = round((float) $purchaseItem->quantity - $alreadyReturned, 4);

                if ($quantity > $remaining + 0.00005) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => "Cannot return {$quantity}; only "
                            . number_format($remaining, 4) . ' remains returnable for this line.',
                    ]);
                }

                // Same guard as PurchaseService::void(): this batch may
                // already have been partially sold or used elsewhere, so
                // removing it now could drive current stock negative.
                $resolved = $this->inventory->baseQuantity($purchaseItem->product, $quantity, $purchaseItem->product_unit_id);
                $available = (float) $this->inventory->currentStock($purchaseItem->product);

                if ((float) $resolved['base_quantity'] > $available + 0.00005) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => "Cannot return this quantity of {$purchaseItem->product->name}: "
                            . number_format((float) $resolved['base_quantity'], 4) . ' would need removing from stock, but only '
                            . number_format($available, 4) . ' remain. Some of it has already been sold or used elsewhere.',
                    ]);
                }

                $lineTotal = round(($quantity / (float) $purchaseItem->quantity) * (float) $purchaseItem->line_total, 2);

                $prepared[] = [
                    'purchase_item' => $purchaseItem,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                ];

                $totalValue += $lineTotal;
                $totalQuantity += $quantity;
            }

            $totalValue = round($totalValue, 2);

            $outstandingBefore = (float) $purchase->balance_due;
            $appliedToPayable = round(min($totalValue, max(0.0, $outstandingBefore)), 2);
            $excess = round($totalValue - $appliedToPayable, 2);

            $settlementMethod = $data['settlement_method'] ?? 'credit';

            // No "would this go negative" check needed here, unlike the
            // sale-return refund: a supplier refund only ever INCREASES an
            // account's balance (cash coming in), which can never go
            // negative - same reasoning PurchaseService::void() already
            // documents for reversing a supplier_payment.
            $refundAccount = null;
            if ($excess > 0.005 && $settlementMethod === 'refund') {
                if (empty($data['refund_account_id'])) {
                    throw ValidationException::withMessages([
                        'refund_account_id' => 'An account is required to receive the refunded excess amount.',
                    ]);
                }

                $refundAccount = Account::query()->find($data['refund_account_id']);

                if (!$refundAccount) {
                    throw ValidationException::withMessages([
                        'refund_account_id' => 'The selected refund account does not exist.',
                    ]);
                }
            }

            $returnRecord = PurchaseReturn::create([
                'purchase_id' => $purchase->id,
                'return_number' => $this->nextReturnNumber(),
                'return_date' => $returnDate,
                'reason' => $data['reason'],
                'settlement_method' => $settlementMethod,
                'refund_account_id' => $refundAccount?->id,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
                'applied_to_payable' => $appliedToPayable,
                'refund_amount' => 0,
                'credited_amount' => 0,
                'created_by' => $userId,
            ]);

            foreach ($prepared as $item) {
                /** @var PurchaseItem $purchaseItem */
                $purchaseItem = $item['purchase_item'];

                $returnRecord->items()->create([
                    'purchase_item_id' => $purchaseItem->id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $purchaseItem->landed_unit_cost ?? $purchaseItem->unit_cost,
                    'line_total' => $item['line_total'],
                ]);

                // Removed at the ORIGINAL landed_unit_cost - never today's
                // weighted-average cost.
                $this->inventory->record(
                    $purchaseItem->product,
                    $item['quantity'],
                    $purchaseItem->product_unit_id,
                    'purchase_return',
                    'purchase_return',
                    $returnRecord->id,
                    $data['reason'],
                    "Return {$returnRecord->return_number} of Purchase {$purchase->purchase_number}",
                    $userId,
                    'out',
                    $purchaseItem->landed_unit_cost ?? $purchaseItem->unit_cost,
                    occurredAt: $returnDate,
                );
            }

            $this->transactions->create([
                'type' => 'purchase_return',
                'supplier_id' => $purchase->supplier_id,
                'purchase_id' => $purchase->id,
                'amount' => $totalValue,
                'description' => "Return {$returnRecord->return_number} for Purchase {$purchase->purchase_number}: {$data['reason']}",
                'reference' => $returnRecord->return_number,
                'transaction_date' => $returnDate,
            ], $userId, internal: true);

            $refundAmount = 0.0;

            if ($excess > 0.005 && $settlementMethod === 'refund') {
                $refundAmount = $excess;

                $this->transactions->create([
                    'type' => 'purchase_return_refund',
                    'supplier_id' => $purchase->supplier_id,
                    'account_id' => $refundAccount->id,
                    'purchase_id' => $purchase->id,
                    'amount' => $refundAmount,
                    'description' => "Refund for return {$returnRecord->return_number} (Purchase {$purchase->purchase_number})",
                    'reference' => $returnRecord->return_number,
                    'transaction_date' => $returnDate,
                ], $userId, internal: true);
            }

            $creditedAmount = round($excess - $refundAmount, 2);

            $returnRecord->update([
                'refund_amount' => $refundAmount,
                'credited_amount' => $creditedAmount,
            ]);

            $newTotal = round((float) $purchase->total - $totalValue, 2);
            $newAmountPaid = round((float) $purchase->amount_paid - $refundAmount, 2);
            $newBalanceDue = round($newTotal - $newAmountPaid, 2);
            $newPaymentStatus = $newBalanceDue <= 0.005
                ? 'paid'
                : ($newAmountPaid > 0.005 ? 'partial' : 'unpaid');

            $purchase->update([
                'total' => $newTotal,
                'amount_paid' => $newAmountPaid,
                'balance_due' => $newBalanceDue,
                'payment_status' => $newPaymentStatus,
            ]);

            AuditLog::record('purchase_return_created', $returnRecord, null, [
                'purchase_return_id' => $returnRecord->id,
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number,
                'return_date' => $returnRecord->return_date->toDateString(),
                'reason' => $data['reason'],
                'quantity' => (string) $totalQuantity,
                'value' => (string) $totalValue,
                'settlement_method' => $settlementMethod,
                'applied_to_payable' => (string) $appliedToPayable,
                'refund_amount' => (string) $refundAmount,
                'credited_amount' => (string) $creditedAmount,
                'refund_account_id' => $refundAccount?->id,
                'created_by' => $userId,
            ]);

            return $returnRecord->load([
                'purchase.supplier',
                'items.purchaseItem.product',
                'refundAccount',
                'creator',
            ]);
        });
    }

    private function nextReturnNumber(): string
    {
        $prefix = 'PRET-' . now()->format('Ymd') . '-';

        $last = PurchaseReturn::query()
            ->where('return_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('return_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}

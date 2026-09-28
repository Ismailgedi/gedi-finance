<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer return against part or all of a specific, already-posted sale -
 * distinct from voiding (SaleService::void()), which reverses the entire
 * sale. A return only ever reverses the returned quantity/value; the
 * original sale and its sale_items are never modified or deleted - this
 * service adjusts the sale's own running totals (total/cost_of_goods_sold/
 * gross_profit/amount_paid/balance_due/payment_status) so every existing
 * report/statement that reads those columns keeps working unmodified, while
 * the sale_return/sale_return_items rows are the permanent record of what
 * was returned, when, by whom, and why.
 *
 * Settlement always happens in the same order: the return value first pays
 * down whatever is still outstanding on THIS sale (sale.balance_due at the
 * moment of the return, which already reflects any earlier returns); only
 * the excess beyond that becomes either a customer credit (a negative
 * person_balance left in place - no separate ledger, see BalanceService's
 * existing signed-balance convention) or a real cash refund, capped at what
 * the chosen account currently holds.
 */
class SaleReturnService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly InventoryService $inventory,
        private readonly BalanceService $balances,
        private readonly FinancialYearCloseService $financialYears,
    ) {
    }

    public function create(array $data, ?int $userId = null): SaleReturn
    {
        return DB::transaction(function () use ($data, $userId) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($data['sale_id']);

            if ($sale->status !== 'posted') {
                throw ValidationException::withMessages([
                    'sale' => 'Only a posted sale can have items returned. This sale is voided.',
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

            $sale->load('items.product');

            // sale_items.line_total already has any PER-LINE discount baked
            // in (see the loop below), but sales.discount - the INVOICE-
            // level discount, applied once across the whole sale - is
            // never distributed back onto individual lines: SUM(sale_items.
            // line_total) always equals sales.subtotal, never sales.total
            // (see SaleService::create()). Without this ratio, a return
            // credited/refunded the customer for their pre-invoice-discount
            // share, overstating the credit/refund and corrupting sales.
            // total/gross_profit by exactly the discount amount (see the
            // accounting audit's C3 finding).
            //
            // sales.subtotal/sales.discount are themselves never touched by
            // a return (only total/cost_of_goods_sold/gross_profit/
            // amount_paid/balance_due/payment_status are updated below) -
            // they stay frozen at their sale-creation values forever, so
            // this ratio is stable and correct across any number of partial
            // returns, never compounding a discount reduction twice.
            $subtotal = (float) $sale->subtotal;
            $invoiceDiscountRatio = $subtotal > 0.00005
                ? ($subtotal - (float) $sale->discount) / $subtotal
                : 1.0;

            $prepared = [];
            $totalValue = 0.0;
            $totalCost = 0.0;
            $totalQuantity = 0.0;

            foreach ($items as $index => $itemData) {
                /** @var SaleItem|null $saleItem */
                $saleItem = $sale->items->firstWhere('id', $itemData['sale_item_id']);

                if (!$saleItem) {
                    throw ValidationException::withMessages([
                        "items.$index.sale_item_id" => 'This item does not belong to the selected sale.',
                    ]);
                }

                $quantity = (float) $itemData['quantity'];
                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Return quantity must be greater than zero.',
                    ]);
                }

                $alreadyReturned = (float) SaleReturnItem::query()
                    ->where('sale_item_id', $saleItem->id)
                    ->sum('quantity');

                $remaining = round((float) $saleItem->quantity - $alreadyReturned, 4);

                if ($quantity > $remaining + 0.00005) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => "Cannot return {$quantity}; only "
                            . number_format($remaining, 4) . ' remains returnable for this line.',
                    ]);
                }

                $isSaleable = array_key_exists('is_saleable', $itemData) ? (bool) $itemData['is_saleable'] : true;

                // Prorated against the original line's own total (which
                // already has any per-line discount baked in) rather than
                // quantity * unit_price, so a partial return of a
                // discounted line reverses its fair share of that discount
                // too - then scaled by $invoiceDiscountRatio so the
                // invoice-level discount is reflected as well.
                $lineTotal = round(($quantity / (float) $saleItem->quantity) * (float) $saleItem->line_total * $invoiceDiscountRatio, 2);

                // $saleItem->unit_cost is the BASE-unit historical cost
                // (see InventoryService::baseQuantity()/record()) - $quantity
                // here is in the sale line's own DISPLAY unit (e.g. cartons),
                // which can differ from the base unit (e.g. bags) by a
                // conversion factor. Multiplying the display quantity
                // directly by the base-unit cost undercounts (or overcounts)
                // COGS by exactly that factor whenever a non-base unit was
                // used - see the accounting audit's Finding 2. Converting to
                // base quantity first matches exactly what InventoryService::
                // record() already does internally for the inventory
                // movement's own total_cost below, so the two never disagree.
                $baseQuantity = (float) $this->inventory->baseQuantity(
                    $saleItem->product,
                    $quantity,
                    $saleItem->product_unit_id,
                )['base_quantity'];
                $costTotal = round($baseQuantity * (float) $saleItem->unit_cost, 2);

                $prepared[] = [
                    'sale_item' => $saleItem,
                    'quantity' => $quantity,
                    'is_saleable' => $isSaleable,
                    'line_total' => $lineTotal,
                    'cost_total' => $costTotal,
                ];

                $totalValue += $lineTotal;
                $totalQuantity += $quantity;
                if ($isSaleable) {
                    $totalCost += $costTotal;
                }
            }

            $totalValue = round($totalValue, 2);
            $totalCost = round($totalCost, 2);

            $outstandingBefore = (float) $sale->balance_due;
            $appliedToReceivable = round(min($totalValue, max(0.0, $outstandingBefore)), 2);
            $excess = round($totalValue - $appliedToReceivable, 2);

            $settlementMethod = $data['settlement_method'] ?? 'credit';

            // A true walk-in sale (no customer on file at all) has no
            // person to hold a credit against - the excess can only ever
            // be a cash refund.
            if (!$sale->customer_id) {
                $settlementMethod = 'refund';
            }

            $refundAccount = null;
            if ($excess > 0.005 && $settlementMethod === 'refund') {
                if (empty($data['refund_account_id'])) {
                    throw ValidationException::withMessages([
                        'refund_account_id' => 'An account is required to refund the excess amount.',
                    ]);
                }

                $refundAccount = Account::query()->find($data['refund_account_id']);

                if (!$refundAccount) {
                    throw ValidationException::withMessages([
                        'refund_account_id' => 'The selected refund account does not exist.',
                    ]);
                }

                $currentBalance = (float) $this->balances->accountBalance($refundAccount);

                if ($excess > $currentBalance + 0.005) {
                    throw ValidationException::withMessages([
                        'refund_account_id' => "Cannot refund " . number_format($excess, 2)
                            . "; {$refundAccount->name} only holds " . number_format($currentBalance, 2) . '.',
                    ]);
                }
            }

            $returnRecord = SaleReturn::create([
                'sale_id' => $sale->id,
                'return_number' => $this->nextReturnNumber(),
                'return_date' => $returnDate,
                'reason' => $data['reason'],
                'settlement_method' => $settlementMethod,
                'refund_account_id' => $refundAccount?->id,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
                'total_cost' => $totalCost,
                'applied_to_receivable' => $appliedToReceivable,
                'refund_amount' => 0,
                'credited_amount' => 0,
                'created_by' => $userId,
            ]);

            foreach ($prepared as $item) {
                /** @var SaleItem $saleItem */
                $saleItem = $item['sale_item'];

                $returnRecord->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $saleItem->unit_price,
                    'unit_cost' => $saleItem->unit_cost,
                    'line_total' => $item['line_total'],
                    'cost_total' => $item['cost_total'],
                    'is_saleable' => $item['is_saleable'],
                ]);

                // Saleable -> goods physically go back into stock, at the
                // ORIGINAL sale-line unit_cost (never today's weighted
                // average - see InventoryService::record(), which is given
                // the cost explicitly and never recomputes it). Unsaleable
                // -> no inventory movement at all: the stock already left
                // at the time of the original sale and never comes back,
                // so there is nothing to reverse a second time.
                if ($item['is_saleable']) {
                    $this->inventory->record(
                        $saleItem->product,
                        $item['quantity'],
                        $saleItem->product_unit_id,
                        'sale_return',
                        'sale_return',
                        $returnRecord->id,
                        $data['reason'],
                        "Return {$returnRecord->return_number} of Sale {$sale->invoice_number}",
                        $userId,
                        'in',
                        $saleItem->unit_cost,
                        occurredAt: $returnDate,
                    );
                }
            }

            // Step 1: the full return value, always - this is what reduces
            // the receivable (or, once it's already at zero, creates the
            // customer credit).
            $this->transactions->create([
                'type' => 'sale_return',
                'person_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'amount' => $totalValue,
                'description' => "Return {$returnRecord->return_number} for Sale {$sale->invoice_number}: {$data['reason']}",
                'reference' => $returnRecord->return_number,
                'transaction_date' => $returnDate,
            ], $userId, internal: true);

            $refundAmount = 0.0;

            // Step 2: only the excess (never the full return value) is
            // actually refunded - the receivable-reduction portion above
            // already settled the rest.
            if ($excess > 0.005 && $settlementMethod === 'refund') {
                $refundAmount = $excess;

                $this->transactions->create([
                    'type' => 'sale_return_refund',
                    'person_id' => $sale->customer_id,
                    'account_id' => $refundAccount->id,
                    'sale_id' => $sale->id,
                    'amount' => $refundAmount,
                    'description' => "Refund for return {$returnRecord->return_number} (Sale {$sale->invoice_number})",
                    'reference' => $returnRecord->return_number,
                    'transaction_date' => $returnDate,
                ], $userId, internal: true);
            }

            $creditedAmount = round($excess - $refundAmount, 2);

            $returnRecord->update([
                'refund_amount' => $refundAmount,
                'credited_amount' => $creditedAmount,
            ]);

            // The original sale_items rows are never touched - only the
            // sale's own running totals move, exactly like a live balance
            // would, so every existing report/statement that already reads
            // sales.total/cost_of_goods_sold/gross_profit/balance_due keeps
            // working with no query changes.
            $newTotal = round((float) $sale->total - $totalValue, 2);
            $newCogs = round((float) $sale->cost_of_goods_sold - $totalCost, 2);
            $newGrossProfit = round($newTotal - $newCogs, 2);
            $newAmountPaid = round((float) $sale->amount_paid - $refundAmount, 2);
            $newBalanceDue = round($newTotal - $newAmountPaid, 2);
            $newPaymentStatus = $newBalanceDue <= 0.005
                ? 'paid'
                : ($newAmountPaid > 0.005 ? 'partial' : 'unpaid');

            $sale->update([
                'total' => $newTotal,
                'cost_of_goods_sold' => $newCogs,
                'gross_profit' => $newGrossProfit,
                'amount_paid' => $newAmountPaid,
                'balance_due' => $newBalanceDue,
                'payment_status' => $newPaymentStatus,
            ]);

            AuditLog::record('sale_return_created', $returnRecord, null, [
                'sale_return_id' => $returnRecord->id,
                'sale_id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'return_date' => $returnRecord->return_date->toDateString(),
                'reason' => $data['reason'],
                'quantity' => (string) $totalQuantity,
                'value' => (string) $totalValue,
                'settlement_method' => $settlementMethod,
                'applied_to_receivable' => (string) $appliedToReceivable,
                'refund_amount' => (string) $refundAmount,
                'credited_amount' => (string) $creditedAmount,
                'refund_account_id' => $refundAccount?->id,
                'created_by' => $userId,
            ]);

            return $returnRecord->load([
                'sale.customer',
                'items.saleItem.product',
                'refundAccount',
                'creator',
            ]);
        });
    }

    private function nextReturnNumber(): string
    {
        $prefix = 'SRET-' . now()->format('Ymd') . '-';

        $last = SaleReturn::query()
            ->where('return_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('return_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}

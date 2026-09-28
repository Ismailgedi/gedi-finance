<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\OpeningBalance;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reconciles a physical stock count against the system quantity.
 * Deliberately thin: it never touches inventory_movements/current stock
 * directly - it computes the difference and hands it to the existing,
 * unmodified InventoryService::record() exactly the way PurchaseService/
 * SaleService already do, so weighted-average costing and inventory
 * valuation pick it up automatically.
 */
class InventoryAdjustmentService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly FinancialYearCloseService $financialYears,
    ) {
    }

    public function create(array $data, ?int $userId = null): InventoryAdjustment
    {
        return DB::transaction(function () use ($data, $userId) {
            $product = Product::query()
                ->where('id', $data['product_id'])
                ->where('is_active', true)
                ->first();

            if (!$product) {
                throw ValidationException::withMessages([
                    'product_id' => 'The selected product does not exist or is inactive.',
                ]);
            }

            $adjustmentDate = $data['adjustment_date'] ?? now();

            // Same two guards every other posting path already respects
            // (TransactionService::create()) - an adjustment posts a real
            // InventoryMovement that feeds BusinessCapitalService::
            // inventoryBreakdown()/assetsAsOf() exactly like a purchase or
            // sale would, so it must not be able to land inside an
            // already-closed financial year's frozen snapshot, or predate
            // a locked Opening Balance's own cost basis. See the
            // accounting audit's H2 finding.
            $this->financialYears->assertDatePostable($adjustmentDate);
            OpeningBalance::assertDateNotBeforeLock($adjustmentDate);

            $physicalQuantity = (float) $data['physical_quantity'];

            // A physical count can never itself be negative - and because
            // the adjustment always resolves stock TO whatever was counted,
            // stock can never end up negative as a result of one (the
            // "out" movement below can remove at most current stock, since
            // the count can be at least zero).
            if ($physicalQuantity < 0) {
                throw ValidationException::withMessages([
                    'physical_quantity' => 'Physical quantity cannot be negative.',
                ]);
            }

            if (trim((string) ($data['reason'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'A reason is required for every inventory adjustment.',
                ]);
            }

            $systemQuantity = (float) $this->inventory->currentStock($product);
            $difference = round($physicalQuantity - $systemQuantity, 4);

            $unitCost = null;
            $totalValue = 0.0;

            if (abs($difference) > 0.00005) {
                // A shortfall (difference < 0) removes units that are
                // already in stock at the EXISTING weighted-average cost -
                // there is no legitimate reason to value that removal at
                // anything else. Accepting a client-supplied unit_cost here
                // would let a single 'out' movement silently distort the
                // average of every unit left behind (inventory_movements is
                // append-only and never rebalanced), permanently corrupting
                // every future sale's COGS and the Business Position's
                // inventory_at_cost - see the accounting audit's C5
                // finding. Only a positive difference (found stock with no
                // existing cost basis yet) may legitimately need an
                // explicit override.
                if ($difference < 0) {
                    $unitCost = $this->inventory->weightedAverageCost($product);
                } else {
                    $unitCost = $data['unit_cost'] ?? $this->inventory->weightedAverageCost($product);
                }

                if ($unitCost === null) {
                    throw ValidationException::withMessages([
                        'unit_cost' => 'A unit cost is required - this product has no existing weighted-average cost to default to.',
                    ]);
                }

                $unitCost = (float) $unitCost;

                if ($unitCost < 0) {
                    throw ValidationException::withMessages([
                        'unit_cost' => 'Unit cost cannot be negative.',
                    ]);
                }

                $totalValue = round($difference * $unitCost, 2);
            }

            $adjustment = InventoryAdjustment::create([
                'product_id' => $product->id,
                'unit_id' => $product->base_unit_id,
                'system_quantity' => $systemQuantity,
                'physical_quantity' => $physicalQuantity,
                'quantity_difference' => $difference,
                'unit_cost' => $unitCost,
                'total_adjustment_value' => $totalValue,
                'reason' => $data['reason'],
                'adjustment_date' => $adjustmentDate,
                'created_by' => $userId,
            ]);

            if (abs($difference) > 0.00005) {
                $movement = $this->inventory->record(
                    $product,
                    abs($difference),
                    null,
                    'adjustment',
                    'inventory_adjustment',
                    $adjustment->id,
                    $data['reason'],
                    null,
                    $userId,
                    $difference > 0 ? 'in' : 'out',
                    $unitCost,
                    occurredAt: $adjustmentDate,
                );

                $adjustment->update(['inventory_movement_id' => $movement->id]);
            }

            AuditLog::record('inventory_adjustment_created', $adjustment, null, [
                'product_id' => $product->id,
                'system_quantity' => (string) $systemQuantity,
                'physical_quantity' => (string) $physicalQuantity,
                'quantity_difference' => (string) $difference,
                'total_adjustment_value' => (string) $totalValue,
            ]);

            return $adjustment->fresh(['product.baseUnit', 'unit', 'creator']);
        });
    }
}

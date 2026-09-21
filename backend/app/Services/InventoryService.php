<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function baseQuantity(
        Product $product,
        float|int|string $quantity,
        ?int $productUnitId = null,
    ): array {
        $quantity = (float) $quantity;

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be greater than zero.',
            ]);
        }

        if ($productUnitId === null) {
            return [
                'unit_id' => $product->base_unit_id,
                'factor' => 1.0,
                'base_quantity' => $quantity,
            ];
        }

        $productUnit = ProductUnit::query()
            ->where('id', $productUnitId)
            ->where('product_id', $product->id)
            ->first();

        if (!$productUnit) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'The selected unit is not configured for this product.',
            ]);
        }

        $factor = (float) $productUnit->conversion_factor;

        if ($factor <= 0) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'The product unit conversion must be greater than zero.',
            ]);
        }

        return [
            'unit_id' => $productUnit->unit_id,
            'factor' => $factor,
            'base_quantity' => $quantity * $factor,
        ];
    }

    public function currentStock(Product $product): string
    {
        $stock = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->sum('base_quantity');

        return number_format((float) $stock, 4, '.', '');
    }

    public function inventoryValue(Product $product): string
    {
        $value = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->sum('total_cost');

        return number_format((float) $value, 2, '.', '');
    }

    public function weightedAverageCost(Product $product): ?string
    {
        $stock = (float) $this->currentStock($product);

        if ($stock <= 0.00005) {
            return null;
        }

        $uncostedQuantity = (float) InventoryMovement::query()
            ->where('product_id', $product->id)
            ->whereNull('total_cost')
            ->sum(DB::raw('ABS(base_quantity)'));

        if ($uncostedQuantity > 0.00005) {
            throw ValidationException::withMessages([
                'product_id' => 'This product has inventory without a recorded cost. Add/repair the inventory cost before selling it.',
            ]);
        }

        $value = (float) $this->inventoryValue($product);

        return number_format($value / $stock, 4, '.', '');
    }

    public function record(
        Product $product,
        float|int|string $quantity,
        ?int $productUnitId,
        string $movementType,
        ?string $referenceType,
        ?int $referenceId,
        ?string $reason,
        ?string $notes,
        ?int $userId,
        string $direction = 'in',
        float|int|string|null $unitCost = null,
    ): InventoryMovement {
        return DB::transaction(function () use (
            $product,
            $quantity,
            $productUnitId,
            $movementType,
            $referenceType,
            $referenceId,
            $reason,
            $notes,
            $userId,
            $direction,
            $unitCost
        ) {
            if (!in_array($direction, ['in', 'out'], true)) {
                throw ValidationException::withMessages([
                    'direction' => 'Inventory direction must be in or out.',
                ]);
            }

            $resolved = $this->baseQuantity($product, $quantity, $productUnitId);
            $baseQuantity = (float) $resolved['base_quantity'];

            $resolvedUnitCost = $unitCost !== null ? (float) $unitCost : null;

            if ($resolvedUnitCost === null && $direction === 'in' && $product->default_cost_price !== null) {
                $resolvedUnitCost = (float) $product->default_cost_price;
            }

            if ($direction === 'out') {
                $available = (float) $this->currentStock($product);

                if ($baseQuantity > $available + 0.00005) {
                    throw ValidationException::withMessages([
                        'quantity' => "Insufficient stock. Available base quantity: {$available}.",
                    ]);
                }

                if ($resolvedUnitCost === null) {
                    $weightedAverage = $this->weightedAverageCost($product);

                    if ($weightedAverage === null) {
                        throw ValidationException::withMessages([
                            'product_id' => 'No inventory cost is available for this product.',
                        ]);
                    }

                    $resolvedUnitCost = (float) $weightedAverage;
                }

                $baseQuantity *= -1;
            }

            if ($resolvedUnitCost !== null && $resolvedUnitCost < 0) {
                throw ValidationException::withMessages([
                    'unit_cost' => 'Inventory unit cost cannot be negative.',
                ]);
            }

            $totalCost = $resolvedUnitCost === null
                ? null
                : round(abs($baseQuantity) * $resolvedUnitCost, 2) * ($direction === 'out' ? -1 : 1);

            return InventoryMovement::create([
                'product_id' => $product->id,
                'unit_id' => $resolved['unit_id'],
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'unit_cost' => $resolvedUnitCost,
                'total_cost' => $totalCost,
                'movement_type' => $movementType,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'occurred_at' => now(),
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $userId,
            ]);
        });
    }
}

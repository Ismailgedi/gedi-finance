<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A physical stock count reconciled against the system quantity - see
 * InventoryAdjustmentService::create(). Never edits inventory directly: a
 * non-zero difference is recorded as an ordinary InventoryMovement (via the
 * existing, unmodified InventoryService::record()) with movement_type
 * 'adjustment', so weighted-average costing, current stock and inventory
 * valuation pick it up exactly the way they already pick up a purchase or
 * a sale. This table is the audit/business record of the count itself
 * (system quantity, physical quantity, difference, cost, reason, who and
 * when) - inventory_movement_id links to the movement it produced, and is
 * left null for a count that confirmed no discrepancy (no movement is
 * created when there is nothing to move).
 *
 * Deliberately does NOT create a ledger Transaction: a stock count has no
 * cash/receivable/payable effect, so TransactionService's accounting
 * machinery (built for money movements) does not apply here - only the
 * inventory side of the existing architecture is reused.
 *
 * Purely additive: creates a new, empty table. No existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->foreignId('unit_id')
                ->constrained('units')
                ->restrictOnDelete();

            $table->decimal('system_quantity', 15, 4);
            $table->decimal('physical_quantity', 15, 4);
            $table->decimal('quantity_difference', 15, 4);

            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_adjustment_value', 15, 2)->default(0);

            $table->text('reason');
            $table->date('adjustment_date');

            $table->foreignId('inventory_movement_id')
                ->nullable()
                ->constrained('inventory_movements')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['product_id', 'adjustment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};

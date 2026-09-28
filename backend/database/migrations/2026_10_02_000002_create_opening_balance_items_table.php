<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('opening_balance_id')
                ->constrained('opening_balances')
                ->cascadeOnDelete();

            // receivable | other_receivable | payable | loan_given |
            // loan_received | capital | inventory - one polymorphic-ish
            // table rather than seven near-identical ones, following the
            // same conditional-columns-by-type precedent as
            // purchase_additional_costs.type.
            $table->string('category', 30);

            $table->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained('product_units')->restrictOnDelete();

            // Inventory only.
            $table->decimal('quantity', 15, 4)->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();

            // The item's value - for inventory this is quantity * unit_cost
            // (stored, not recomputed, so the audit trail always shows
            // exactly what was entered).
            $table->decimal('amount', 15, 2);

            // Set once the OpeningBalance is locked and this item's
            // underlying Transaction/InventoryMovement is actually posted -
            // null while still in draft, since nothing is posted yet.
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();

            $table->timestamps();

            $table->index(['opening_balance_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_items');
    }
};

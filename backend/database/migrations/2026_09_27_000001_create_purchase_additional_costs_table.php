<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landed costs (transport, customs, handling, ...) attached to a purchase.
 * Every row contributes to the landed unit cost allocated into
 * inventory_movements (see PurchaseService::create()); `type` decides how
 * the money side is recorded:
 *
 *   - supplier_bundled: folded straight into purchases.total (the supplier
 *     is owed for it too) - no transaction_id, no separate Transaction is
 *     ever created for it (see PurchaseService::create()'s comment on
 *     "must not create a separate cash transaction merely because it is a
 *     landed cost").
 *   - third_party: paid immediately from account_id via a dedicated
 *     TransactionType::PurchaseCost transaction (transaction_id links to
 *     it) - decreases that account, tagged to this purchase, but
 *     deliberately excluded from ordinary operating expenses.
 *
 * Purely additive: creates a new, empty table. No existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_additional_costs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_id')
                ->constrained('purchases')
                ->cascadeOnDelete();

            $table->string('description', 255);
            $table->decimal('amount', 15, 2);
            $table->string('type', 20);

            $table->foreignId('account_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['purchase_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_additional_costs');
    }
};

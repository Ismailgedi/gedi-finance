<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_return_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sale_return_id')
                ->constrained('sale_returns')
                ->cascadeOnDelete();

            // The original line this return is against - never modified or
            // deleted by a return. Cumulative validation (requested <=
            // original quantity - already returned) always sums against
            // this exact column.
            $table->foreignId('sale_item_id')
                ->constrained('sale_items')
                ->restrictOnDelete();

            $table->decimal('quantity', 15, 4);

            // Copied from the original sale_item at return time - frozen,
            // never today's price/weighted-average cost.
            $table->decimal('unit_price', 15, 4);
            $table->decimal('unit_cost', 15, 4);

            $table->decimal('line_total', 15, 2);
            $table->decimal('cost_total', 15, 2);

            // Saleable -> goods physically go back into stock (an inventory
            // movement is recorded). Unsaleable/damaged -> the customer
            // still gets their financial credit/refund, but no inventory
            // movement is ever created for this line - see SaleReturnService.
            $table->boolean('is_saleable')->default(true);

            $table->timestamps();

            $table->index(['sale_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
    }
};

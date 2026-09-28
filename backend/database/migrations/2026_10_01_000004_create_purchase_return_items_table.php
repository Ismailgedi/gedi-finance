<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_return_id')
                ->constrained('purchase_returns')
                ->cascadeOnDelete();

            $table->foreignId('purchase_item_id')
                ->constrained('purchase_items')
                ->restrictOnDelete();

            $table->decimal('quantity', 15, 4);

            // Copied from purchase_items.landed_unit_cost at return time -
            // frozen, never today's weighted-average cost.
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('line_total', 15, 2);

            $table->timestamps();

            $table->index(['purchase_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};

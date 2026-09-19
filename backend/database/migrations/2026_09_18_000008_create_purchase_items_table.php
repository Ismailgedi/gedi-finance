<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_id')
                ->constrained('purchases')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->foreignId('product_unit_id')
                ->nullable()
                ->constrained('product_units')
                ->restrictOnDelete();

            $table->decimal('quantity', 15, 4);
            $table->decimal('base_quantity', 15, 4);

            // Historical transaction-time cost. Never recomputed from the product default.
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('line_total', 15, 2);

            $table->timestamps();

            $table->index(['purchase_id', 'product_id']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};

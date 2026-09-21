<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('sales')
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

            // Historical transaction-time selling price.
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);

            $table->timestamps();

            $table->index(['sale_id', 'product_id']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('product_categories')
                ->nullOnDelete();

            $table->foreignId('base_unit_id')
                ->constrained('units')
                ->restrictOnDelete();

            $table->string('name', 150);
            $table->string('sku', 50)->unique();
            $table->decimal('default_cost_price', 15, 4)->nullable();
            $table->decimal('default_selling_price', 15, 4)->nullable();
            $table->decimal('default_wholesale_price', 15, 4)->nullable();
            $table->decimal('minimum_stock', 15, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
            $table->index(['name', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

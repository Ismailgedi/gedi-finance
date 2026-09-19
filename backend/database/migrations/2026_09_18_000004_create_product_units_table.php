<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('unit_id')
                ->constrained('units')
                ->restrictOnDelete();

            // Number of base units represented by one selected unit.
            // Example: if KG is the base unit, one Sack may equal 50 KG.
            $table->decimal('conversion_factor', 15, 4);

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->index(['product_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};

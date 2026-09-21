<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->foreignId('unit_id')
                ->nullable()
                ->constrained('units')
                ->restrictOnDelete();

            // Quantity in the unit selected by the user, for example 10 sacks.
            $table->decimal('quantity', 15, 4);

            // Signed quantity in the product's base unit, for example +500 KG.
            $table->decimal('base_quantity', 15, 4);

            $table->string('movement_type', 50);

            // Optional reference to the originating business event.
            // The first inventory phase does not force a polymorphic relation yet.
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->dateTime('occurred_at');

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['product_id', 'occurred_at']);
            $table->index(['movement_type', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['created_by', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};

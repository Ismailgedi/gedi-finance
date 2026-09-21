<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();

            $table->foreignId('sale_id')
                ->nullable()
                ->constrained('sales')
                ->nullOnDelete();

            $table->foreignId('purchase_id')
                ->nullable()
                ->constrained('purchases')
                ->nullOnDelete();

            $table->decimal('supplier_balance_effect', 15, 2)->default(0);

            $table->index(['supplier_id', 'transaction_date']);
            $table->index(['sale_id', 'transaction_date']);
            $table->index(['purchase_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['sale_id']);
            $table->dropForeign(['purchase_id']);
            $table->dropColumn([
                'supplier_id',
                'sale_id',
                'purchase_id',
                'supplier_balance_effect',
            ]);
        });
    }
};

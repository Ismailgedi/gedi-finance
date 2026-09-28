<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_id')
                ->constrained('purchases')
                ->restrictOnDelete();

            $table->string('return_number')->unique();
            $table->date('return_date');
            $table->text('reason');

            // 'credit' or 'refund' - see sale_returns.settlement_method.
            $table->string('settlement_method', 20);

            $table->foreignId('refund_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->decimal('total_quantity', 15, 4);
            $table->decimal('total_value', 15, 2);
            $table->decimal('applied_to_payable', 15, 2);
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->decimal('credited_amount', 15, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['purchase_id', 'return_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 50)->unique();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('people')
                ->restrictOnDelete();

            $table->date('sale_date');
            $table->date('due_date')->nullable();

            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);

            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);

            $table->string('payment_status', 30)->default('unpaid');
            $table->string('status', 30)->default('posted');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_id', 'sale_date']);
            $table->index(['payment_status', 'due_date']);
            $table->index(['status', 'sale_date']);
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};

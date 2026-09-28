<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            $table->string('return_number')->unique();
            $table->date('return_date');
            $table->text('reason');

            // 'credit' or 'refund' - which way the excess (return value minus
            // whatever was applied to the outstanding receivable) is settled.
            // Meaningless when there is no excess, but always recorded so the
            // user's intent is on record either way.
            $table->string('settlement_method', 20);

            $table->foreignId('refund_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->decimal('total_quantity', 15, 4);
            // Full reversed sale value (revenue side) - always the sum of
            // the return items' line_total, regardless of settlement.
            $table->decimal('total_value', 15, 2);
            // COGS actually reversed - 0 for any unsaleable item, since
            // its cost was never recovered. Never equals total_value's
            // proportional cost when any item is unsaleable.
            $table->decimal('total_cost', 15, 2);
            $table->decimal('applied_to_receivable', 15, 2);
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->decimal('credited_amount', 15, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['sale_id', 'return_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};

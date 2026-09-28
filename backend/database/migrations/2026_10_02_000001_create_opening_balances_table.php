<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table): void {
            $table->id();

            // The point immediately before Gedi Finance's own transaction
            // history begins - see OpeningBalanceService's own doc comment.
            $table->date('as_of_date');

            // The single, validated (assets - liabilities) figure for this
            // date - stored, never re-derived, exactly like
            // financial_year_closes.closing_equity is stored rather than
            // recomputed once a year is closed.
            $table->decimal('opening_equity', 15, 2)->nullable();

            $table->string('status', 20)->default('draft'); // draft | locked | reopened

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};

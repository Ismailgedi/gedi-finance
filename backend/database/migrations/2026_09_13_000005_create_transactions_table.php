<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 40)->unique();
            $table->string('type', 40);
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('destination_account_id')->nullable()->constrained('accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('USD');
            $table->decimal('person_balance_effect', 15, 2)->default(0);
            $table->decimal('account_balance_effect', 15, 2)->default(0);
            $table->decimal('destination_account_effect', 15, 2)->default(0);
            $table->text('description');
            $table->string('reference', 100)->nullable();
            $table->dateTime('transaction_date');
            $table->string('status', 20)->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['person_id', 'transaction_date']);
            $table->index(['account_id', 'transaction_date']);
            $table->index(['type', 'transaction_date']);
            $table->index(['status', 'transaction_date']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

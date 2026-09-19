<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('customer_code', 50)->nullable()->unique();
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->boolean('is_customer')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_code',
                'credit_limit',
                'payment_terms_days',
                'is_customer',
            ]);
        });
    }
};

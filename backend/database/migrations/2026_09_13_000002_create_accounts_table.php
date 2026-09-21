<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 30);
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

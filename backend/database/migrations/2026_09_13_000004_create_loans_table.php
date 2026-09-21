<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('principal_amount', 15, 2);
            $table->char('currency', 3)->default('USD');
            $table->date('start_date');
            $table->date('due_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['person_id', 'status']);
            $table->index('start_date');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};

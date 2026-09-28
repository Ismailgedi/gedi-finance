<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A closed financial year's frozen Business Position snapshot - the
 * historical record that BusinessCapitalService::position() reads instead
 * of live-recomputing, once a year is closed (see FinancialYearCloseService).
 * One row per calendar financial year, mutated across its close/reopen/
 * re-close lifecycle rather than appended to - the full history of who did
 * what and when lives in the existing audit_logs table (see
 * FinancialYearCloseService::close()/reopen(), which write to it), matching
 * how this app already separates current-state tables from its audit trail.
 *
 * Purely additive: creates a new, empty table. No existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_year_closes', function (Blueprint $table): void {
            $table->id();

            $table->unsignedSmallInteger('financial_year')->unique();
            $table->date('period_start_date');
            $table->date('period_end_date');

            $table->decimal('opening_equity', 15, 2);
            $table->decimal('profit_loss', 15, 2);
            $table->decimal('owner_contributions', 15, 2);
            $table->decimal('owner_withdrawals', 15, 2);
            $table->decimal('closing_equity', 15, 2);

            // Frozen breakdown, same shape BusinessCapitalService::position()
            // returns for assets/liabilities - stored so a closed year's
            // report never depends on re-deriving these from live data.
            $table->json('assets');
            $table->json('liabilities');

            $table->string('status', 20)->default('closed');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('closed_at');

            $table->foreignId('reopened_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'financial_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_year_closes');
    }
};

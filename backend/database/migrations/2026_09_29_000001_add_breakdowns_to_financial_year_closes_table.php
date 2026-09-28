<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends a closed year's frozen snapshot with the per-account cash
 * breakdown and the per-product inventory breakdown the Business Position
 * report now shows, so a closed year's report renders those sections from
 * the frozen snapshot too, instead of falling back to nothing or a live
 * recompute. Does not change any close/reopen rule - purely additive data
 * captured alongside the figures already snapshotted (see
 * FinancialYearCloseService::close()).
 *
 * Nullable and left null on every existing closed-year row - the report
 * simply shows an empty breakdown for a year closed before this column
 * existed, exactly as if no accounts/products were recorded, rather than
 * erroring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_year_closes', function (Blueprint $table): void {
            $table->json('cash_accounts')->nullable()->after('assets');
            $table->json('inventory_breakdown')->nullable()->after('cash_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('financial_year_closes', function (Blueprint $table): void {
            $table->dropColumn(['cash_accounts', 'inventory_breakdown']);
        });
    }
};

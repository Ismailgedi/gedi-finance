<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The period's Other Income (debt_created, recognized at creation time -
 * see BusinessReportService::profit()) captured as its own frozen figure
 * alongside profit_loss, which already includes it. Purely a breakdown for
 * display - closing_equity was never wrong, this just shows where part of
 * profit_loss came from for a closed year the same way position() already
 * does for an open one.
 *
 * Nullable and left null on every existing closed-year row - shown as zero
 * rather than recomputed, since the frozen profit_loss/closing_equity above
 * must not change now regardless of what this breakdown shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_year_closes', function (Blueprint $table): void {
            $table->decimal('other_income', 15, 2)->nullable()->after('profit_loss');
        });
    }

    public function down(): void
    {
        Schema::table('financial_year_closes', function (Blueprint $table): void {
            $table->dropColumn('other_income');
        });
    }
};

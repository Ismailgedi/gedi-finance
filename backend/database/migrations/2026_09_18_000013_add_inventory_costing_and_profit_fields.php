<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 4)->nullable()->after('base_quantity');
            $table->decimal('total_cost', 15, 2)->nullable()->after('unit_cost');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 4)->nullable()->after('unit_price');
            $table->decimal('cost_total', 15, 2)->nullable()->after('unit_cost');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('cost_of_goods_sold', 15, 2)->default(0)->after('total');
            $table->decimal('gross_profit', 15, 2)->default(0)->after('cost_of_goods_sold');
        });

        // Backfill the existing Step 1-5 test/legacy inventory using the
        // product's current default cost. New purchases/sales will record
        // their actual cost through the services.
        //
        // Row-by-row via the query builder (not a single joined UPDATE)
        // specifically for portability: the raw `UPDATE ... FROM` form this
        // originally used is PostgreSQL-only syntax and fails outright on
        // MySQL/MariaDB, and a `DB::table(...)->join(...)->update([...])`
        // replacement (MySQL/PostgreSQL both support a joined UPDATE)
        // breaks differently on SQLite - its grammar rewrites a joined
        // UPDATE into `WHERE rowid IN (subquery)`, which leaves the join's
        // alias out of scope for the SET clause, so a SET value that
        // references the joined table's column (p.default_cost_price)
        // fails there instead. Doing the arithmetic in PHP and issuing one
        // single-table UPDATE per row avoids every engine's join-in-UPDATE
        // dialect differences entirely (all three confirmed: SQLite via
        // the full test suite, MySQL via a local migration compatibility
        // test, PostgreSQL unchanged in behavior from before).
        DB::table('inventory_movements as im')
            ->join('products as p', 'im.product_id', '=', 'p.id')
            ->whereNull('im.unit_cost')
            ->select('im.id', 'im.base_quantity', 'p.default_cost_price')
            ->get()
            ->each(function (object $row): void {
                DB::table('inventory_movements')->where('id', $row->id)->update([
                    'unit_cost' => $row->default_cost_price,
                    'total_cost' => $row->default_cost_price === null
                        ? null
                        : round((float) $row->base_quantity * (float) $row->default_cost_price, 2),
                ]);
            });

        DB::table('sale_items as si')
            ->join('products as p', 'si.product_id', '=', 'p.id')
            ->whereNull('si.unit_cost')
            ->select('si.id', 'si.base_quantity', 'p.default_cost_price')
            ->get()
            ->each(function (object $row): void {
                DB::table('sale_items')->where('id', $row->id)->update([
                    'unit_cost' => $row->default_cost_price,
                    'cost_total' => $row->default_cost_price === null
                        ? null
                        : round((float) $row->base_quantity * (float) $row->default_cost_price, 2),
                ]);
            });

        DB::statement(<<<'SQL'
            UPDATE sales AS s
            SET cost_of_goods_sold = COALESCE(
                    (
                        SELECT SUM(si.cost_total)
                        FROM sale_items AS si
                        WHERE si.sale_id = s.id
                    ),
                    0
                ),
                gross_profit = s.total - COALESCE(
                    (
                        SELECT SUM(si.cost_total)
                        FROM sale_items AS si
                        WHERE si.sale_id = s.id
                    ),
                    0
                )
        SQL);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['cost_of_goods_sold', 'gross_profit']);
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost', 'cost_total']);
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });
    }
};

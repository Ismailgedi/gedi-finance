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
        DB::statement(<<<'SQL'
            UPDATE inventory_movements AS im
            SET unit_cost = p.default_cost_price,
                total_cost = CASE
                    WHEN p.default_cost_price IS NULL THEN NULL
                    ELSE ROUND(im.base_quantity * p.default_cost_price, 2)
                END
            FROM products AS p
            WHERE im.product_id = p.id
              AND im.unit_cost IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE sale_items AS si
            SET unit_cost = p.default_cost_price,
                cost_total = CASE
                    WHEN p.default_cost_price IS NULL THEN NULL
                    ELSE ROUND(si.base_quantity * p.default_cost_price, 2)
                END
            FROM products AS p
            WHERE si.product_id = p.id
              AND si.unit_cost IS NULL
        SQL);

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

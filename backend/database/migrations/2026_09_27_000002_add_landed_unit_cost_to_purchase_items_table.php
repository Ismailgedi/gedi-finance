<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The actual per-unit cost capitalized into inventory for this line - the
 * purchase price (unit_cost) plus its allocated share of any landed costs
 * on the purchase (see PurchaseService::create()). This is exactly the
 * value passed into InventoryService::record() as unitCost, kept alongside
 * unit_cost (the pure price paid to the supplier, unchanged) so
 * PurchaseService::void() can reverse the exact inventory value that was
 * actually capitalized for THIS line, rather than re-deriving it by
 * product_id (ambiguous when a purchase has two lines for the same
 * product) or falling back to the pre-landed-cost price.
 *
 * Nullable and left null on every existing row: PurchaseService::void()
 * falls back to unit_cost when it's null, which is exactly what void()
 * already did before this column existed - existing purchases behave
 * identically. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->decimal('landed_unit_cost', 15, 4)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropColumn('landed_unit_cost');
        });
    }
};

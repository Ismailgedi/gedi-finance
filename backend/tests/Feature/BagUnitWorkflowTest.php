<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gedi Finance sells wholesale grain by the bag, not the individual piece.
 * A sale/purchase item normally uses the product's own sellable unit (e.g.
 * Bag) directly, with no ProductUnit conversion row involved at all - that
 * is the *default* path, not an edge case. SaleController/PurchaseController
 * previously eager-loaded `items.product` without `.baseUnit`, so
 * `item.product.base_unit` was silently absent from the API response and
 * the invoice's Unit column rendered "-" for exactly this default path.
 * This proves the fix: base_unit is actually present on the item's product.
 */
class BagUnitWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_sale_item_sold_in_the_products_bag_unit_exposes_base_unit_on_the_invoice(): void
    {
        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);

        $rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Rice 25kg',
            'sku' => 'BAG-RICE-25',
            'default_cost_price' => 20,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);

        app(InventoryService::class)->record($rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 20);

        $cash = Account::create([
            'name' => 'Bag Unit Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 270,
            'account_id' => $cash->id,
            'items' => [
                // No product_unit_id - sold directly in the product's own
                // "Bag" unit, which is the normal wholesale path.
                ['product_id' => $rice->id, 'quantity' => 10, 'unit_price' => 27],
            ],
        ])->assertCreated()->json('sale');

        $this->assertSame('270.00', $sale['total']);

        $invoice = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();

        $this->assertNull($invoice['items'][0]['product_unit']);
        $this->assertSame('Bag', $invoice['items'][0]['product']['base_unit']['name']);
        $this->assertSame('bag', $invoice['items'][0]['product']['base_unit']['abbreviation']);

        $list = $this->getJson('/api/sales')->assertOk()->json();
        $this->assertSame('Bag', $list['data'][0]['items'][0]['product']['base_unit']['name']);
    }

    public function test_purchase_item_received_in_the_products_bag_unit_exposes_base_unit(): void
    {
        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);

        $maize = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Maize 50kg',
            'sku' => 'BAG-MAIZE-50',
            'default_cost_price' => 30,
            'default_selling_price' => 38,
            'is_active' => true,
        ]);

        $supplier = Supplier::create([
            'supplier_code' => 'BAG-SUP-1',
            'name' => 'Bag Unit Supplier',
            'is_active' => true,
        ]);

        $cash = Account::create([
            'name' => 'Bag Unit Purchase Cash',
            'type' => 'cash',
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 300,
            'account_id' => $cash->id,
            'items' => [
                ['product_id' => $maize->id, 'quantity' => 10, 'unit_cost' => 30],
            ],
        ])->assertCreated()->json('purchase');

        $receipt = $this->getJson("/api/purchases/{$purchase['id']}")->assertOk()->json();

        $this->assertNull($receipt['items'][0]['product_unit']);
        $this->assertSame('Bag', $receipt['items'][0]['product']['base_unit']['name']);
    }
}

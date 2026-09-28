<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 2 (accounting audit): SaleReturnService computed a returned
 * line's cost as `quantity * unit_cost`, where $quantity is in the sale
 * line's own DISPLAY unit (e.g. cartons) but unit_cost is the BASE-unit
 * historical cost (e.g. per bag) - see InventoryService::baseQuantity().
 * Whenever a non-base product unit was used, this undercounted (or
 * overcounted) the returned COGS by exactly the conversion factor, while
 * InventoryService::record()'s own inventory movement already converted
 * to base quantity correctly - the two disagreed. The fix converts the
 * returned quantity to base quantity first, matching record() exactly, so
 * SaleReturnItem.cost_total, sale.cost_of_goods_sold and the inventory
 * movement's own total_cost can never drift apart again.
 *
 * Fixture throughout: base unit = bag, 1 carton = 10 bags, unit cost =
 * $18/bag (the exact numbers from the audit's own example).
 */
class SaleReturnUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private InventoryService $inventory;
    private Product $rice;
    private ProductUnit $carton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->balances = app(BalanceService::class);
        $this->inventory = app(InventoryService::class);

        $bag = Unit::create(['name' => 'SRUC Bag', 'abbreviation' => 'sruc-bag' . uniqid()]);
        $cartonUnit = Unit::create(['name' => 'SRUC Carton', 'abbreviation' => 'sruc-ctn' . uniqid()]);

        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'SRUC Rice',
            'sku' => 'SRUC-RICE-' . uniqid(),
            'default_cost_price' => 18,
            'default_selling_price' => 25,
            'is_active' => true,
        ]);

        $this->carton = ProductUnit::create([
            'product_id' => $this->rice->id,
            'unit_id' => $cartonUnit->id,
            'conversion_factor' => 10,
        ]);

        $this->inventory->record($this->rice, 1000, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18);
    }

    private function customer(string $name): Person
    {
        return Person::create(['name' => $name, 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
    }

    /** @return array{sale: array, saleItemId: int} */
    private function cartonSale(Person $customer, float $cartons, float $unitPrice = 250, float $discount = 0): array
    {
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'discount' => $discount,
            'items' => [['product_id' => $this->rice->id, 'product_unit_id' => $this->carton->id, 'quantity' => $cartons, 'unit_price' => $unitPrice]],
        ])->assertCreated()->json('sale');

        return ['sale' => $sale, 'saleItemId' => $sale['items'][0]['id']];
    }

    // --- 1. Base-unit sale return - unaffected by the fix (base_quantity
    // == quantity when no product_unit_id is used). ---
    public function test_base_unit_sale_return_cost_is_unaffected(): void
    {
        $customer = $this->customer('SRUC Base Unit Customer');
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 30, 'unit_price' => 25]],
        ])->assertCreated()->json('sale');
        // cost_of_goods_sold = 30 * 18 = 540.

        $response = $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'test', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 10, 'is_saleable' => true]],
        ])->assertCreated();

        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('360.00', $updated['cost_of_goods_sold'], '540 - (10 * 18) = 360.');
    }

    // --- 2. Converted-unit sale return: the exact audit example. ---
    public function test_converted_unit_sale_return_uses_base_quantity_for_cost(): void
    {
        $customer = $this->customer('SRUC Converted Unit Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->cartonSale($customer, 3);
        // 3 cartons = 30 bags -> cost_of_goods_sold = 30 * 18 = 540.
        $this->assertSame('540.00', $sale['cost_of_goods_sold']);

        // Return 1 carton = 10 bags. COGS reduction must be 10 * 18 = 180,
        // never 1 * 18 = 18 (the pre-fix bug).
        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'Wrong grade', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1, 'is_saleable' => true]],
        ])->assertCreated();

        $returnItem = \App\Models\SaleReturnItem::query()->latest('id')->firstOrFail();
        $this->assertSame('180.00', $returnItem->cost_total, '10 bags * $18, not 1 * $18.');

        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('360.00', $updated['cost_of_goods_sold'], '540 - 180 = 360, not 540 - 18 = 522.');
    }

    // --- 3. Multiple converted-unit returns. ---
    public function test_multiple_converted_unit_returns_accumulate_correctly(): void
    {
        $customer = $this->customer('SRUC Multiple Returns Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->cartonSale($customer, 5);
        // 5 cartons = 50 bags -> COGS = 50 * 18 = 900.

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'First return', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1, 'is_saleable' => true]],
        ])->assertCreated();
        // -1 carton = -10 bags -> -180.

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'Second return', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 2, 'is_saleable' => true]],
        ])->assertCreated();
        // -2 cartons = -20 bags -> -360.

        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        // 900 - 180 - 360 = 360.
        $this->assertSame('360.00', $updated['cost_of_goods_sold']);

        // Stock: 1000 - 50 (sold) + 10 + 20 (both saleable returns) = 980.
        $this->assertSame('980.0000', $this->inventory->currentStock($this->rice->fresh()));
    }

    // --- 4. Saleable converted-unit return: inventory restocked at the
    // correct base quantity and cost. ---
    public function test_saleable_converted_unit_return_restocks_correct_base_quantity(): void
    {
        $customer = $this->customer('SRUC Saleable Customer');
        $stockBefore = $this->inventory->currentStock($this->rice->fresh());
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->cartonSale($customer, 3);
        // Stock: 1000 - 30 = 970.
        $this->assertSame('970.0000', $this->inventory->currentStock($this->rice->fresh()));

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'test', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1, 'is_saleable' => true]],
        ])->assertCreated();

        // 970 + 10 (1 carton restocked) = 980, never 971 (1 bag).
        $this->assertSame('980.0000', $this->inventory->currentStock($this->rice->fresh()));
        $this->assertNotSame($stockBefore, $this->inventory->currentStock($this->rice->fresh()));
    }

    // --- 5. Unsaleable converted-unit return: no inventory movement, but
    // the recorded cost_total is still the correct base-quantity value
    // (used for record-keeping even though it doesn't reduce COGS - see
    // SaleReturnService's own doc comment on why unsaleable returns never
    // reduce cost_of_goods_sold). ---
    public function test_unsaleable_converted_unit_return_records_correct_cost_but_no_inventory_movement(): void
    {
        $customer = $this->customer('SRUC Unsaleable Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->cartonSale($customer, 3);
        $stockAfterSale = $this->inventory->currentStock($this->rice->fresh());

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'Damaged goods', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1, 'is_saleable' => false]],
        ])->assertCreated();

        // No inventory movement - stock unchanged.
        $this->assertSame($stockAfterSale, $this->inventory->currentStock($this->rice->fresh()));

        // cost_total is still recorded at the correct base-quantity value.
        $returnItem = \App\Models\SaleReturnItem::query()->latest('id')->firstOrFail();
        $this->assertSame('180.00', $returnItem->cost_total, '1 carton = 10 bags * $18, not 1 * $18.');

        // But COGS is unaffected (unsaleable never reduces it) - the loss
        // shows up entirely as reduced gross profit instead.
        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('540.00', $updated['cost_of_goods_sold'], 'Unchanged - unsaleable returns never reduce COGS.');
    }

    // --- 6. COGS and gross profit after a converted-unit return. ---
    public function test_gross_profit_after_converted_unit_return(): void
    {
        $customer = $this->customer('SRUC Gross Profit Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->cartonSale($customer, 3, unitPrice: 250);
        // 3 cartons @ $250 = $750 total, COGS = 30 * 18 = 540, gross profit = 210.
        $this->assertSame('750.00', $sale['total']);
        $this->assertSame('540.00', $sale['cost_of_goods_sold']);
        $this->assertSame('210.00', $sale['gross_profit']);

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'test', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1, 'is_saleable' => true]],
        ])->assertCreated();

        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        // total: 750 - 250 (1/3 of 750) = 500.
        // cogs: 540 - 180 (10 bags * 18) = 360.
        // gross profit: 500 - 360 = 140.
        $this->assertSame('500.00', $updated['total']);
        $this->assertSame('360.00', $updated['cost_of_goods_sold']);
        $this->assertSame('140.00', $updated['gross_profit']);
    }

    // --- 7. Invoice-level discount + converted-unit return combined. ---
    public function test_invoice_discount_and_converted_unit_return_combine_correctly(): void
    {
        $customer = $this->customer('SRUC Discount Customer');
        // 3 cartons @ $250 = $750 subtotal, $75 invoice discount -> $675 total.
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->cartonSale($customer, 3, unitPrice: 250, discount: 75);
        $this->assertSame('750.00', $sale['subtotal']);
        $this->assertSame('675.00', $sale['total']);
        // COGS is independent of discount - still 30 * 18 = 540.
        $this->assertSame('540.00', $sale['cost_of_goods_sold']);
        $this->assertSame('135.00', $sale['gross_profit'], '675 - 540 = 135.');

        // Return 1 of 3 cartons. Revenue side: (1/3) * 750 * (675/750) = 225.
        // Cost side: 1 carton = 10 bags * $18 = 180 (the Finding 2 fix),
        // completely independent of the discount ratio (the C3 fix).
        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'test', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1, 'is_saleable' => true]],
        ])->assertCreated();

        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('450.00', $updated['total'], '675 - 225 = 450.');
        $this->assertSame('360.00', $updated['cost_of_goods_sold'], '540 - 180 = 360.');
        $this->assertSame('90.00', $updated['gross_profit'], '450 - 360 = 90.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\InventoryMovement;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Finding 1 (accounting audit): InventoryService::record() used to always
 * stamp inventory_movements.occurred_at as now(), regardless of the
 * business event's own date (a backdated sale's sale_date, a purchase's
 * purchase_date, etc.) - so BusinessCapitalService::inventoryBreakdown()/
 * assetsAsOf(), which filter historical snapshots by this exact column,
 * could silently disagree with an as-of-that-date Business Position for
 * any backdated entry. record() now accepts an optional $occurredAt,
 * defaulting to now() for any caller that doesn't pass it (unchanged
 * behavior), and every business-dated workflow passes its own date
 * explicitly. Void/reversal movements are deliberately NOT backdated to
 * the original transaction - they're dated at the actual reversal/posting
 * moment, which this file also verifies.
 */
class InventoryMovementDateTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->cash = Account::create(['name' => 'IMD Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        $unit = Unit::create(['name' => 'IMD Unit', 'abbreviation' => 'imdu' . uniqid()]);
        $this->product = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'IMD Product', 'sku' => 'IMD-' . uniqid(),
            'default_cost_price' => 10, 'default_selling_price' => 20, 'is_active' => true,
        ]);
        app(InventoryService::class)->record($this->product, 1000, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);
    }

    private function lastMovement(string $movementType): InventoryMovement
    {
        return InventoryMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', $movementType)
            ->latest('id')
            ->firstOrFail();
    }

    // --- 1. Sale ---
    public function test_sale_inventory_movement_uses_sale_date_not_posting_date(): void
    {
        $customer = Person::create(['name' => 'IMD Sale Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $saleDate = now()->subDays(18)->toDateString();

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'sale_date' => $saleDate,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated();

        $movement = $this->lastMovement('sale');
        $this->assertSame($saleDate, $movement->occurred_at->toDateString());
        $this->assertNotSame(now()->toDateString(), $movement->occurred_at->toDateString());
    }

    // --- 2. Purchase ---
    public function test_purchase_inventory_movement_uses_purchase_date_not_posting_date(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'IMD-SUP-1', 'name' => 'IMD Supplier 1', 'is_active' => true]);
        $purchaseDate = now()->subDays(25)->toDateString();

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'purchase_date' => $purchaseDate,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 10]],
        ])->assertCreated();

        $movement = $this->lastMovement('purchase');
        $this->assertSame($purchaseDate, $movement->occurred_at->toDateString());
        $this->assertNotSame(now()->toDateString(), $movement->occurred_at->toDateString());
    }

    // --- 3. Sale return ---
    public function test_sale_return_inventory_movement_uses_return_date_not_posting_date(): void
    {
        $customer = Person::create(['name' => 'IMD Sale Return Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');

        $returnDate = now()->subDays(3)->toDateString();
        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'reason' => 'Customer changed their mind',
            'settlement_method' => 'credit',
            'return_date' => $returnDate,
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 4, 'is_saleable' => true]],
        ])->assertCreated();

        $movement = $this->lastMovement('sale_return');
        $this->assertSame($returnDate, $movement->occurred_at->toDateString());
        $this->assertNotSame(now()->toDateString(), $movement->occurred_at->toDateString());
    }

    // --- 4. Purchase return ---
    public function test_purchase_return_inventory_movement_uses_return_date_not_posting_date(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'IMD-SUP-2', 'name' => 'IMD Supplier 2', 'is_active' => true]);
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 10]],
        ])->assertCreated()->json('purchase');

        $returnDate = now()->subDays(5)->toDateString();
        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'],
            'reason' => 'Damaged on arrival',
            'settlement_method' => 'credit',
            'return_date' => $returnDate,
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 5]],
        ])->assertCreated();

        $movement = $this->lastMovement('purchase_return');
        $this->assertSame($returnDate, $movement->occurred_at->toDateString());
        $this->assertNotSame(now()->toDateString(), $movement->occurred_at->toDateString());
    }

    // --- 5. Inventory adjustment ---
    public function test_inventory_adjustment_movement_uses_adjustment_date_not_posting_date(): void
    {
        $adjustmentDate = now()->subDays(7)->toDateString();

        $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->product->id,
            'physical_quantity' => 990,
            'reason' => 'Backdated stock count',
            'adjustment_date' => $adjustmentDate,
        ])->assertCreated();

        $movement = $this->lastMovement('adjustment');
        $this->assertSame($adjustmentDate, $movement->occurred_at->toDateString());
        $this->assertNotSame(now()->toDateString(), $movement->occurred_at->toDateString());
    }

    // --- 6. Opening balance ---
    public function test_opening_balance_inventory_movement_uses_as_of_date_not_posting_date(): void
    {
        $asOfDate = now()->subDays(200)->toDateString();

        $unit = Unit::create(['name' => 'IMD OB Unit', 'abbreviation' => 'imdobu' . uniqid()]);
        $obProduct = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'IMD OB Product', 'sku' => 'IMD-OB-' . uniqid(),
            'default_cost_price' => 15, 'default_selling_price' => 25, 'is_active' => true,
        ]);

        $this->postJson('/api/admin/opening-balance', [
            'as_of_date' => $asOfDate,
            'opening_equity' => 300,
            'items' => [['category' => 'inventory', 'product_id' => $obProduct->id, 'quantity' => 20, 'unit_cost' => 15]],
        ])->assertOk();
        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $movement = InventoryMovement::query()
            ->where('product_id', $obProduct->id)
            ->where('movement_type', 'opening_balance')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($asOfDate, $movement->occurred_at->toDateString());
        $this->assertNotSame(now()->toDateString(), $movement->occurred_at->toDateString());
    }

    // --- Void/reversal movements retain the actual reversal date ---

    public function test_sale_void_reversal_uses_the_actual_void_date_not_the_original_sale_date(): void
    {
        $customer = Person::create(['name' => 'IMD Void Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $saleDate = now()->subDays(15)->toDateString();

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'sale_date' => $saleDate,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');

        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'test'])->assertOk();

        $voidMovement = $this->lastMovement('sale_void');
        $this->assertSame(now()->toDateString(), $voidMovement->occurred_at->toDateString());
        $this->assertNotSame($saleDate, $voidMovement->occurred_at->toDateString());
    }

    public function test_purchase_void_reversal_uses_the_actual_void_date_not_the_original_purchase_date(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'IMD-SUP-3', 'name' => 'IMD Supplier 3', 'is_active' => true]);
        $purchaseDate = now()->subDays(20)->toDateString();

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'purchase_date' => $purchaseDate,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 10]],
        ])->assertCreated()->json('purchase');

        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'test'])->assertOk();

        $voidMovement = $this->lastMovement('purchase_void');
        $this->assertSame(now()->toDateString(), $voidMovement->occurred_at->toDateString());
        $this->assertNotSame($purchaseDate, $voidMovement->occurred_at->toDateString());
    }

    public function test_opening_balance_reopen_reversal_uses_as_of_date_not_todays_date(): void
    {
        $asOfDate = now()->subDays(180)->toDateString();

        $unit = Unit::create(['name' => 'IMD OB Reopen Unit', 'abbreviation' => 'imdobr' . uniqid()]);
        $obProduct = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'IMD OB Reopen Product', 'sku' => 'IMD-OBR-' . uniqid(),
            'default_cost_price' => 12, 'default_selling_price' => 22, 'is_active' => true,
        ]);

        $this->postJson('/api/admin/opening-balance', [
            'as_of_date' => $asOfDate,
            'opening_equity' => 240,
            'items' => [['category' => 'inventory', 'product_id' => $obProduct->id, 'quantity' => 20, 'unit_cost' => 12]],
        ])->assertOk();
        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->postJson('/api/admin/opening-balance/reopen')->assertOk();

        // Deliberately NOT "now" - see OpeningBalanceService::reopen()'s own
        // doc comment: a reopen-correct-relock cycle must make every
        // past-and-future report agree with the corrected position from
        // as_of_date onward, not just from today onward.
        $reversal = InventoryMovement::query()
            ->where('product_id', $obProduct->id)
            ->where('movement_type', 'opening_balance_reversal')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($asOfDate, $reversal->occurred_at->toDateString());
        $this->assertNotSame(now()->toDateString(), $reversal->occurred_at->toDateString());
    }

    // --- Default behavior (no $occurredAt passed) is unchanged ---
    public function test_record_without_occurred_at_still_defaults_to_now(): void
    {
        $before = Carbon::now();

        $movement = app(InventoryService::class)->record(
            $this->product, 1, null, 'adjustment', null, null, null, null, null, 'in', 5,
        );

        $this->assertTrue($movement->occurred_at->greaterThanOrEqualTo($before->subSecond()));
        $this->assertTrue($movement->occurred_at->lessThanOrEqualTo(Carbon::now()->addSecond()));
    }
}

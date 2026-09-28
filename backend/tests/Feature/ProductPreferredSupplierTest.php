<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product.supplier_id is a catalog-level "preferred supplier" default,
 * purely informational - it never affects, and is never affected by,
 * which supplier an actual Purchase records (Purchase::supplier_id stays
 * completely independent - see Product::supplier()'s doc comment).
 */
class ProductPreferredSupplierTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());

        $this->unit = Unit::create(['name' => 'PPS Unit', 'abbreviation' => 'ppsu' . uniqid()]);
    }

    private function activeSupplier(string $name = 'PPS Supplier'): Supplier
    {
        return Supplier::create([
            'supplier_code' => 'PPS-' . uniqid(),
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function test_product_can_be_created_with_a_preferred_supplier(): void
    {
        $supplier = $this->activeSupplier();

        $response = $this->postJson('/api/products', [
            'name' => 'PPS Product 1',
            'base_unit_id' => $this->unit->id,
            'supplier_id' => $supplier->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('product.supplier.id', $supplier->id);
        $response->assertJsonPath('product.supplier.name', $supplier->name);

        $this->assertSame($supplier->id, Product::query()->first()->supplier_id);
    }

    public function test_product_can_be_created_without_a_preferred_supplier(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'PPS Product 2',
            'base_unit_id' => $this->unit->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('product.supplier_id', null);
        $response->assertJsonPath('product.supplier', null);

        $this->assertNull(Product::query()->first()->supplier_id);
    }

    public function test_preferred_supplier_can_be_changed(): void
    {
        $original = $this->activeSupplier('PPS Original Supplier');
        $replacement = $this->activeSupplier('PPS Replacement Supplier');

        $product = Product::create([
            'base_unit_id' => $this->unit->id,
            'supplier_id' => $original->id,
            'name' => 'PPS Product 3',
            'sku' => 'PPS-SKU-3',
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => $product->name,
            'supplier_id' => $replacement->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('product.supplier.id', $replacement->id);
        $this->assertSame($replacement->id, $product->fresh()->supplier_id);
    }

    public function test_preferred_supplier_can_be_cleared_back_to_none(): void
    {
        $original = $this->activeSupplier();

        $product = Product::create([
            'base_unit_id' => $this->unit->id,
            'supplier_id' => $original->id,
            'name' => 'PPS Product 4',
            'sku' => 'PPS-SKU-4',
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => $product->name,
            'supplier_id' => null,
        ]);

        $response->assertOk();
        $this->assertNull($product->fresh()->supplier_id);
    }

    public function test_an_inactive_supplier_is_rejected_as_preferred_supplier_on_create(): void
    {
        $inactive = Supplier::create([
            'supplier_code' => 'PPS-INACTIVE-1',
            'name' => 'PPS Inactive Supplier',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/products', [
            'name' => 'PPS Product 5',
            'base_unit_id' => $this->unit->id,
            'supplier_id' => $inactive->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['supplier_id']);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_a_nonexistent_supplier_is_rejected_as_preferred_supplier_on_create(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'PPS Product 6',
            'base_unit_id' => $this->unit->id,
            'supplier_id' => 999999,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_an_inactive_supplier_is_rejected_as_preferred_supplier_on_update(): void
    {
        $active = $this->activeSupplier();
        $inactive = Supplier::create([
            'supplier_code' => 'PPS-INACTIVE-2',
            'name' => 'PPS Inactive Supplier 2',
            'is_active' => false,
        ]);

        $product = Product::create([
            'base_unit_id' => $this->unit->id,
            'supplier_id' => $active->id,
            'name' => 'PPS Product 7',
            'sku' => 'PPS-SKU-7',
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => $product->name,
            'supplier_id' => $inactive->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['supplier_id']);
        $this->assertSame($active->id, $product->fresh()->supplier_id);
    }

    public function test_product_index_and_show_return_the_preferred_supplier(): void
    {
        $supplier = $this->activeSupplier();

        $product = Product::create([
            'base_unit_id' => $this->unit->id,
            'supplier_id' => $supplier->id,
            'name' => 'PPS Product 8',
            'sku' => 'PPS-SKU-8',
            'is_active' => true,
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonFragment(['id' => $supplier->id, 'name' => $supplier->name]);

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('supplier.id', $supplier->id)
            ->assertJsonPath('supplier.name', $supplier->name);
    }

    public function test_setting_a_product_preferred_supplier_does_not_create_a_new_supplier_or_person(): void
    {
        $supplier = $this->activeSupplier();
        $personCountBefore = \App\Models\Person::query()->count();
        $supplierCountBefore = Supplier::query()->count();

        $this->postJson('/api/products', [
            'name' => 'PPS Product 9',
            'base_unit_id' => $this->unit->id,
            'supplier_id' => $supplier->id,
        ])->assertCreated();

        $this->assertSame($personCountBefore, \App\Models\Person::query()->count());
        $this->assertSame($supplierCountBefore, Supplier::query()->count());
    }

    /**
     * A product's preferred supplier is a catalog default only - it must
     * never leak into, or be influenced by, the actual supplier a real
     * Purchase records against a completely different supplier.
     */
    public function test_purchase_workflow_still_records_its_own_independent_supplier(): void
    {
        $preferred = $this->activeSupplier('PPS Preferred Supplier');
        $actual = $this->activeSupplier('PPS Actual Purchase Supplier');

        $product = Product::create([
            'base_unit_id' => $this->unit->id,
            'supplier_id' => $preferred->id,
            'name' => 'PPS Product 10',
            'sku' => 'PPS-SKU-10',
            'default_cost_price' => 10,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $actual->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 10]],
        ]);

        $response->assertCreated();
        $this->assertSame($actual->id, \App\Models\Purchase::query()->first()->supplier_id);
        $this->assertSame($preferred->id, $product->fresh()->supplier_id);
    }
}

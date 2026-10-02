<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 items that aren't about roles/permissions directly: the default
 * minimum_stock change (0 -> 5, never overwriting an existing value),
 * inline supplier creation returning the provisioned Supplier immediately
 * (PersonController), and the inventory report's new category field.
 */
class InventoryAndSupplierEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    private Unit $bag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create()); // auto-assigned Manager, see TestCase::actingAs()

        $this->bag = Unit::create(['name' => 'IASE Bag ' . uniqid(), 'abbreviation' => 'iabag' . uniqid()]);
    }

    public function test_new_product_defaults_minimum_stock_to_five_when_omitted(): void
    {
        $product = $this->postJson('/api/products', [
            'base_unit_id' => $this->bag->id,
            'name' => 'Default Min Stock Product',
        ])->assertCreated()->json('product');

        $this->assertSame('5.0000', $product['minimum_stock']);
    }

    public function test_new_product_defaults_minimum_stock_to_five_when_explicitly_null(): void
    {
        $product = $this->postJson('/api/products', [
            'base_unit_id' => $this->bag->id,
            'name' => 'Null Min Stock Product',
            'minimum_stock' => null,
        ])->assertCreated()->json('product');

        $this->assertSame('5.0000', $product['minimum_stock']);
    }

    public function test_new_product_can_still_override_the_default_minimum_stock(): void
    {
        $product = $this->postJson('/api/products', [
            'base_unit_id' => $this->bag->id,
            'name' => 'Custom Min Stock Product',
            'minimum_stock' => 20,
        ])->assertCreated()->json('product');

        $this->assertSame('20.0000', $product['minimum_stock']);
    }

    public function test_updating_a_product_without_touching_minimum_stock_leaves_it_unchanged(): void
    {
        $product = Product::create([
            'base_unit_id' => $this->bag->id,
            'name' => 'Preexisting Product',
            'sku' => 'IASE-PRE-' . uniqid(),
            'minimum_stock' => 0,
            'is_active' => true,
        ]);

        $updated = $this->putJson("/api/products/{$product->id}", [
            'name' => 'Preexisting Product Renamed',
            'minimum_stock' => null,
        ])->assertOk()->json('product');

        $this->assertSame('Preexisting Product Renamed', $updated['name']);
        // Never blindly reset to 5 - the product's own deliberate value
        // (0, in this case) survives an edit that doesn't touch the field.
        $this->assertSame('0.0000', $updated['minimum_stock']);
    }

    public function test_inventory_report_includes_each_products_category(): void
    {
        $category = ProductCategory::create(['name' => 'IASE Grains ' . uniqid(), 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'base_unit_id' => $this->bag->id,
            'name' => 'Categorized Rice',
            'sku' => 'IASE-RICE-' . uniqid(),
            'minimum_stock' => 5,
            'is_active' => true,
        ]);

        app(InventoryService::class)->record(
            $product, 50, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18,
        );

        $rows = $this->getJson('/api/reports/inventory')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertSame($category->name, $row['category']);
    }

    public function test_creating_a_supplier_inline_returns_the_provisioned_supplier_immediately(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Inline Supplier Co',
            'is_supplier' => true,
            'roles' => ['supplier'],
        ])->assertCreated()->json('person');

        $this->assertNotNull($person['supplier'], 'The provisioned Supplier profile must be returned immediately, not require a second request.');
        $this->assertSame('Inline Supplier Co', $person['supplier']['name']);
        $this->assertIsInt($person['supplier']['id']);

        // That exact id must actually work as a Purchase's supplier_id -
        // proving this isn't just a cosmetic echo of the request.
        $this->assertDatabaseHas('suppliers', ['id' => $person['supplier']['id'], 'person_id' => $person['id']]);
    }

    public function test_updating_a_person_to_be_a_supplier_also_returns_the_supplier(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Becomes Supplier Later',
            'is_customer' => true,
            'roles' => ['customer'],
        ])->assertCreated()->json('person');

        $this->assertNull($person['supplier']);

        $updated = $this->putJson("/api/people/{$person['id']}", [
            'name' => 'Becomes Supplier Later',
            'is_customer' => true,
            'is_supplier' => true,
            'roles' => ['customer', 'supplier'],
        ])->assertOk()->json('person');

        $this->assertNotNull($updated['supplier']);
    }
}

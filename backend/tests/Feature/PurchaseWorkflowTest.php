<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_supplier_product_catalog_and_purchase_can_be_created_end_to_end(): void
    {
        $unit = $this->postJson('/api/units', [
            'name' => 'Piece',
            'abbreviation' => 'pc',
        ])->assertCreated()->json('unit');

        $category = $this->postJson('/api/product-categories', [
            'name' => 'Groceries',
        ])->assertCreated()->json('category');

        $supplier = $this->postJson('/api/suppliers', [
            'name' => 'Test Supplier Co.',
            'phone' => '+252 61 000 0000',
        ])->assertCreated()->json('supplier');

        $this->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Test Supplier Co.']);

        $product = $this->postJson('/api/products', [
            'category_id' => $category['id'],
            'base_unit_id' => $unit['id'],
            'name' => 'Test Rice Bag',
            'default_cost_price' => 18,
            'default_selling_price' => 24,
        ])->assertCreated()->json('product');

        $this->assertNotEmpty($product['sku']);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Test Rice Bag']);

        $account = Account::create([
            'name' => 'Purchase Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier['id'],
            'amount_paid' => 100,
            'account_id' => $account->id,
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 10, 'unit_cost' => 18],
            ],
        ])->assertCreated()->json('purchase');

        $this->assertSame('180.00', $purchase['total']);
        $this->assertSame('partial', $purchase['payment_status']);

        $this->getJson("/api/purchases/{$purchase['id']}")
            ->assertOk()
            ->assertJsonPath('supplier.name', 'Test Supplier Co.')
            ->assertJsonPath('items.0.product.name', 'Test Rice Bag');
    }
}

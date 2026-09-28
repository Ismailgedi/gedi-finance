<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 5 (accounting audit): a sale whose final total is $0.00 - either
 * because item-level discounts wipe out the subtotal, or because the
 * invoice-level discount equals the subtotal - is now rejected outright
 * with a clear message (SaleService::create()) instead of being silently
 * treated as an (ambiguous) "fully paid cash sale" for zero dollars.
 */
class ZeroValueSaleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());

        $unit = Unit::create(['name' => 'ZVS Unit', 'abbreviation' => 'zvsu' . uniqid()]);
        $this->product = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'ZVS Product', 'sku' => 'ZVS-' . uniqid(),
            'default_cost_price' => 10, 'default_selling_price' => 20, 'is_active' => true,
        ]);
        app(InventoryService::class)->record($this->product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);
    }

    public function test_a_sale_fully_wiped_out_by_the_invoice_discount_is_rejected(): void
    {
        $response = $this->postJson('/api/sales', [
            'discount' => 100,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['discount']);
        $this->assertSame(0, \App\Models\Sale::query()->count());
    }

    public function test_a_sale_fully_wiped_out_by_item_level_discounts_is_rejected(): void
    {
        $response = $this->postJson('/api/sales', [
            'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20, 'discount' => 100]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['discount']);
        $this->assertSame(0, \App\Models\Sale::query()->count());
    }

    public function test_a_sale_with_a_positive_total_still_succeeds(): void
    {
        $account = Account::create(['name' => 'ZVS Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        $response = $this->postJson('/api/sales', [
            'discount' => 90,
            'amount_paid' => 10,
            'account_id' => $account->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ]);

        $response->assertCreated();
        $this->assertSame(1, \App\Models\Sale::query()->count());
        $this->assertSame(10.0, (float) \App\Models\Sale::query()->first()->total);
    }

    public function test_a_near_zero_discount_leaving_one_cent_still_succeeds(): void
    {
        $customer = Person::create(['name' => 'ZVS Credit Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $response = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'discount' => 99.99,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ]);

        $response->assertCreated();
        $this->assertSame(0.01, (float) \App\Models\Sale::query()->first()->total);
    }
}

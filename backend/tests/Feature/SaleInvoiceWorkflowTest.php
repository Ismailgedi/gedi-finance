<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the exact data shape the Sales page's Invoices panel and
 * InvoiceModal consume (GET /api/sales, GET /api/sales/{sale}), proving
 * the invoice preview has real customer/item/unit/total data to render
 * rather than just checking the routes respond.
 */
class SaleInvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_sale_with_items_can_be_recorded_and_the_invoice_shows_correct_totals(): void
    {
        $piece = Unit::create(['name' => 'Piece', 'abbreviation' => 'pc']);
        $carton = Unit::create(['name' => 'Carton', 'abbreviation' => 'ctn']);

        $rice = Product::create([
            'base_unit_id' => $piece->id,
            'name' => 'Test 25kg Rice Bag',
            'sku' => 'TEST-RICE',
            'default_cost_price' => 18,
            'default_selling_price' => 24,
            'is_active' => true,
        ]);

        $riceCarton = ProductUnit::create([
            'product_id' => $rice->id,
            'unit_id' => $carton->id,
            'conversion_factor' => 10,
        ]);

        app(InventoryService::class)->record($rice, 500, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18);

        $customer = Person::create([
            'name' => 'Al-Amin Trading Co.',
            'phone' => '+252 61 234 5678',
            'address' => 'Bakaaro Market, Mogadishu',
            'is_active' => true,
            'is_customer' => true,
            'roles' => ['customer'],
        ]);

        $account = \App\Models\Account::create([
            'name' => 'Test Partial Payment Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 100,
            'account_id' => $account->id,
            'items' => [
                ['product_id' => $rice->id, 'product_unit_id' => $riceCarton->id, 'quantity' => 3, 'unit_price' => 230, 'discount' => 10],
            ],
        ])->assertCreated()->json('sale');

        // 3 * 230 - 10 discount = 680 subtotal/total (no invoice-level discount)
        $this->assertSame('680.00', $sale['total']);
        $this->assertSame('100.00', $sale['amount_paid']);
        $this->assertSame('580.00', $sale['balance_due']);
        $this->assertSame('partial', $sale['payment_status']);

        $invoice = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();

        $this->assertSame('Al-Amin Trading Co.', $invoice['customer']['name']);
        $this->assertSame('+252 61 234 5678', $invoice['customer']['phone']);
        $this->assertSame('Bakaaro Market, Mogadishu', $invoice['customer']['address']);
        $this->assertCount(1, $invoice['items']);
        $this->assertSame('Test 25kg Rice Bag', $invoice['items'][0]['product']['name']);
        $this->assertSame('Carton', $invoice['items'][0]['product_unit']['unit']['name']);
        $this->assertSame('680.00', $invoice['total']);

        $list = $this->getJson('/api/sales')->assertOk()->json();
        $this->assertSame($sale['id'], $list['data'][0]['id']);
    }

    public function test_fully_paid_sale_reports_paid_status_for_invoice_badge(): void
    {
        $piece = Unit::create(['name' => 'Piece', 'abbreviation' => 'pc']);
        $oil = Product::create([
            'base_unit_id' => $piece->id,
            'name' => 'Test Cooking Oil',
            'sku' => 'TEST-OIL',
            'default_cost_price' => 8,
            'default_selling_price' => 12,
            'is_active' => true,
        ]);

        app(InventoryService::class)->record($oil, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 8);

        $account = \App\Models\Account::create([
            'name' => 'Test Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 120,
            'account_id' => $account->id,
            'items' => [
                ['product_id' => $oil->id, 'quantity' => 10, 'unit_price' => 12],
            ],
        ])->assertCreated()->json('sale');

        $this->assertSame('0.00', $sale['balance_due']);
        $this->assertSame('paid', $sale['payment_status']);
    }

    /**
     * A person created through the real "Add Person" UI (POST /api/people
     * with the "Customer" role selected) must actually be usable as a
     * sale's customer for a credit/partial sale - otherwise the People
     * page and the Sales/Invoice feature don't actually connect.
     */
    public function test_person_created_via_add_person_form_with_customer_role_can_be_used_for_a_credit_sale(): void
    {
        $piece = Unit::create(['name' => 'Piece', 'abbreviation' => 'pc']);
        $rice = Product::create([
            'base_unit_id' => $piece->id,
            'name' => 'Test Rice for Credit Sale',
            'sku' => 'TEST-RICE-CREDIT',
            'default_cost_price' => 18,
            'default_selling_price' => 24,
            'is_active' => true,
        ]);

        app(InventoryService::class)->record($rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18);

        $personId = $this->postJson('/api/people', [
            'name' => 'New Customer via Add Person Form',
            'phone' => '+252 61 555 0000',
            'roles' => ['customer'],
            'is_customer' => true,
        ])->assertCreated()->json('person.id');

        $this->postJson('/api/sales', [
            'customer_id' => $personId,
            'amount_paid' => 0,
            'items' => [
                ['product_id' => $rice->id, 'quantity' => 2, 'unit_price' => 24],
            ],
        ])->assertCreated();
    }
}

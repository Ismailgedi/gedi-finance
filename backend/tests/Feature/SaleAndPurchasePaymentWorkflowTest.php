<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the exact payloads the rebuilt SaleForm and the new
 * InvoiceModal/PurchaseDetailModal "Record a Payment" panels now send,
 * end to end through the real /api/sales, /api/purchases,
 * /api/sales/{sale}/payments and /api/purchases/{purchase}/payments
 * routes. Previously the Sales page's "Record Sale" form posted a flat
 * amount straight to /api/transactions and there was no UI path to record
 * a follow-up payment against an existing sale/purchase at all - this
 * proves both gaps are actually closed, not just that the code compiles.
 */
class SaleAndPurchasePaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Product $rice;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());

        $piece = Unit::create(['name' => 'Piece', 'abbreviation' => 'pc']);
        $this->rice = Product::create([
            'base_unit_id' => $piece->id,
            'name' => 'Payment Workflow Rice',
            'sku' => 'PAY-RICE',
            'default_cost_price' => 10,
            'default_selling_price' => 15,
            'is_active' => true,
        ]);
        app(InventoryService::class)->record($this->rice, 200, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 10);

        $this->cash = Account::create([
            'name' => 'Payment Workflow Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_walk_in_cash_sale_is_fully_paid_with_no_customer(): void
    {
        $sale = $this->postJson('/api/sales', [
            'customer_id' => null,
            'amount_paid' => 150,
            'account_id' => $this->cash->id,
            'items' => [
                ['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 15, 'discount' => 0],
            ],
        ])->assertCreated()->json('sale');

        $this->assertSame('150.00', $sale['total']);
        $this->assertSame('0.00', $sale['balance_due']);
        $this->assertSame('paid', $sale['payment_status']);
        $this->assertNull($sale['customer_id'] ?? null);
    }

    public function test_credit_sale_to_a_customer_starts_unpaid(): void
    {
        $customer = Person::create([
            'name' => 'Payment Workflow Customer',
            'is_active' => true,
            'is_customer' => true,
            'roles' => ['customer'],
        ]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [
                ['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 15, 'discount' => 0],
            ],
        ])->assertCreated()->json('sale');

        $this->assertSame('150.00', $sale['balance_due']);
        $this->assertSame('unpaid', $sale['payment_status']);
    }

    public function test_partially_paid_sale_can_be_paid_off_via_the_invoice_payment_panel(): void
    {
        $customer = Person::create([
            'name' => 'Payment Workflow Partial Customer',
            'is_active' => true,
            'is_customer' => true,
            'roles' => ['customer'],
        ]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 50,
            'account_id' => $this->cash->id,
            'items' => [
                ['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 15, 'discount' => 0],
            ],
        ])->assertCreated()->json('sale');

        $this->assertSame('100.00', $sale['balance_due']);
        $this->assertSame('partial', $sale['payment_status']);

        // This is the exact request InvoiceModal's new "Record a Customer
        // Payment" panel sends.
        $paid = $this->postJson("/api/sales/{$sale['id']}/payments", [
            'amount' => 100,
            'account_id' => $this->cash->id,
        ])->assertOk()->json('sale');

        $this->assertSame('0.00', $paid['balance_due']);
        $this->assertSame('paid', $paid['payment_status']);
    }

    public function test_supplier_payable_can_be_paid_off_via_the_purchase_payment_panel(): void
    {
        $supplier = Supplier::create([
            'supplier_code' => 'PAY-SUP-1',
            'name' => 'Payment Workflow Supplier',
            'is_active' => true,
        ]);

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 40,
            'account_id' => $this->cash->id,
            'items' => [
                ['product_id' => $this->rice->id, 'quantity' => 20, 'unit_cost' => 10],
            ],
        ])->assertCreated()->json('purchase');

        $this->assertSame('200.00', $purchase['total']);
        $this->assertSame('160.00', $purchase['balance_due']);
        $this->assertSame('partial', $purchase['payment_status']);

        // This is the exact request PurchaseDetailModal's new "Record a
        // Supplier Payment" panel sends.
        $paid = $this->postJson("/api/purchases/{$purchase['id']}/payments", [
            'amount' => 160,
            'account_id' => $this->cash->id,
        ])->assertOk()->json('purchase');

        $this->assertSame('0.00', $paid['balance_due']);
        $this->assertSame('paid', $paid['payment_status']);
    }
}

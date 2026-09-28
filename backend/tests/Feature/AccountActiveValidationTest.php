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
 * Finding 4 (accounting audit): every money-posting path validated an
 * account with only `exists:accounts,id` - a direct request could use an
 * inactive account, since the frontend's own filtering (only showing
 * active accounts) is not authoritative. Every such path now validates
 * `exists:accounts,id,is_active,1` instead. This never touches read-only
 * historical references to an account that was deactivated after a real
 * transaction already used it - only new writes are gated.
 */
class AccountActiveValidationTest extends TestCase
{
    use RefreshDatabase;

    private Account $inactiveAccount;
    private Account $activeAccount;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());

        $this->inactiveAccount = Account::create([
            'name' => 'AAV Inactive Cash', 'type' => 'cash', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => false,
        ]);
        $this->activeAccount = Account::create([
            'name' => 'AAV Active Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true,
        ]);

        $unit = Unit::create(['name' => 'AAV Unit', 'abbreviation' => 'aavu' . uniqid()]);
        $this->product = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'AAV Product', 'sku' => 'AAV-' . uniqid(),
            'default_cost_price' => 10, 'default_selling_price' => 20, 'is_active' => true,
        ]);
        $this->inventory()->record($this->product, 1000, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);
    }

    private function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    // --- SALES ---

    public function test_fully_paid_cash_sale_rejects_an_inactive_account(): void
    {
        $customer = Person::create(['name' => 'AAV Customer 1', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 100, 'account_id' => $this->inactiveAccount->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);

        $this->assertSame(0, \App\Models\Sale::query()->count());
    }

    public function test_partial_sale_payment_rejects_an_inactive_account(): void
    {
        $customer = Person::create(['name' => 'AAV Customer 2', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 50, 'account_id' => $this->inactiveAccount->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);

        $this->assertSame(0, \App\Models\Sale::query()->count());
    }

    public function test_sale_accepts_an_active_account(): void
    {
        $customer = Person::create(['name' => 'AAV Customer 3', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 100, 'account_id' => $this->activeAccount->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated();
    }

    // --- CUSTOMER PAYMENTS ---

    public function test_customer_payment_rejects_an_inactive_receiving_account(): void
    {
        $customer = Person::create(['name' => 'AAV Payment Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated();

        $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 50, 'account_id' => $this->inactiveAccount->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);
    }

    // --- SALE RETURN REFUNDS ---

    public function test_sale_return_refund_rejects_an_inactive_refund_account(): void
    {
        $customer = Person::create(['name' => 'AAV Refund Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 100, 'account_id' => $this->activeAccount->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'test', 'settlement_method' => 'refund',
            'refund_account_id' => $this->inactiveAccount->id,
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 5, 'is_saleable' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors(['refund_account_id']);
    }

    // --- PURCHASES ---

    public function test_purchase_with_payment_rejects_an_inactive_account(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'AAV-SUP-1', 'name' => 'AAV Supplier 1', 'is_active' => true]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 50, 'account_id' => $this->inactiveAccount->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);

        $this->assertSame(0, \App\Models\Purchase::query()->count());
    }

    // --- SUPPLIER PAYMENTS ---

    public function test_supplier_payment_rejects_an_inactive_paying_account(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'AAV-SUP-2', 'name' => 'AAV Supplier 2', 'is_active' => true]);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 10]],
        ])->assertCreated();

        $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 50, 'account_id' => $this->inactiveAccount->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);
    }

    // --- PURCHASE RETURN REFUNDS ---

    public function test_purchase_return_refund_rejects_an_inactive_refund_account(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'AAV-SUP-3', 'name' => 'AAV Supplier 3', 'is_active' => true]);
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 100, 'account_id' => $this->activeAccount->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 10]],
        ])->assertCreated()->json('purchase');

        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'], 'reason' => 'test', 'settlement_method' => 'refund',
            'refund_account_id' => $this->inactiveAccount->id,
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors(['refund_account_id']);
    }

    // --- GENERIC TRANSACTIONS ENDPOINT ---

    public function test_generic_transaction_endpoint_rejects_an_inactive_account(): void
    {
        $this->postJson('/api/transactions', [
            'type' => 'income', 'account_id' => $this->inactiveAccount->id, 'amount' => 50, 'description' => 'test',
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);
    }

    public function test_account_transfer_rejects_an_inactive_destination_account(): void
    {
        $this->postJson('/api/transactions', [
            'type' => 'account_transfer', 'account_id' => $this->activeAccount->id,
            'destination_account_id' => $this->inactiveAccount->id, 'amount' => 50, 'description' => 'test',
        ])->assertStatus(422)->assertJsonValidationErrors(['destination_account_id']);
    }

    // --- Read-only historical references remain unaffected ---

    public function test_deactivating_an_account_after_use_does_not_break_reading_its_history(): void
    {
        $customer = Person::create(['name' => 'AAV History Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 100, 'account_id' => $this->activeAccount->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');

        $this->activeAccount->update(['is_active' => false]);

        // The historical sale (and its transactions) must still be fully
        // readable - only NEW writes are gated by the active check.
        $this->getJson("/api/sales/{$sale['id']}")->assertOk();
        $this->getJson('/api/transactions')->assertOk();
        $this->getJson('/api/accounts')->assertOk();
    }
}

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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves that a business event either fully applies or leaves no trace -
 * no half-applied sale, no inventory deducted for a rejected line, no
 * duplicate ledger row on a failed request. SaleService/PurchaseService/
 * TransactionService already wrap their work in DB::transaction(), so this
 * is verification that the wrapping actually does its job, not a fix in
 * itself - but it was never actually exercised by a test before.
 */
class FinancialAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_a_multi_item_sale_that_oversells_one_line_leaves_no_trace_at_all(): void
    {
        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);

        $rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Atomicity Rice',
            'sku' => 'ATOMIC-RICE',
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);
        $maize = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Atomicity Maize',
            'sku' => 'ATOMIC-MAIZE',
            'default_cost_price' => 15,
            'default_selling_price' => 22,
            'is_active' => true,
        ]);

        $inventory = app(InventoryService::class);
        $inventory->record($rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18);
        $inventory->record($maize, 5, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 15);

        $cash = Account::create([
            'name' => 'Atomicity Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $transactionCountBefore = DB::table('transactions')->count();
        $movementCountBefore = DB::table('inventory_movements')->count();

        // First line (rice) is well within stock; second line (maize)
        // requests far more than the 5 bags actually available.
        $this->postJson('/api/sales', [
            'amount_paid' => 1000,
            'account_id' => $cash->id,
            'items' => [
                ['product_id' => $rice->id, 'quantity' => 10, 'unit_price' => 27],
                ['product_id' => $maize->id, 'quantity' => 50, 'unit_price' => 22],
            ],
        ])->assertStatus(422);

        // No Sale/SaleItem rows, no partial rice deduction, no new ledger
        // rows at all - the whole request must have rolled back together.
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('sale_items')->count());
        $this->assertSame('100.0000', $inventory->currentStock($rice), 'Rice stock must be untouched even though it was the first, valid line.');
        $this->assertSame('5.0000', $inventory->currentStock($maize));
        $this->assertSame($transactionCountBefore, DB::table('transactions')->count());
        $this->assertSame($movementCountBefore, DB::table('inventory_movements')->count());
    }

    public function test_a_multi_item_purchase_with_an_invalid_line_leaves_no_trace_at_all(): void
    {
        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Atomicity Purchase Rice',
            'sku' => 'ATOMIC-PUR-RICE',
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);

        $supplier = Supplier::create(['supplier_code' => 'ATOMIC-SUP', 'name' => 'Atomicity Supplier', 'is_active' => true]);
        $cash = Account::create(['name' => 'Atomicity Purchase Cash', 'type' => 'cash', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => true]);

        $transactionCountBefore = DB::table('transactions')->count();
        $movementCountBefore = DB::table('inventory_movements')->count();

        // amount_paid greater than the computed total must be rejected -
        // and the first (valid-looking) item must not have been recorded.
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 100000,
            'account_id' => $cash->id,
            'items' => [
                ['product_id' => $rice->id, 'quantity' => 10, 'unit_cost' => 18],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('purchase_items')->count());
        $this->assertSame('0.0000', app(InventoryService::class)->currentStock($rice));
        $this->assertSame($transactionCountBefore, DB::table('transactions')->count());
        $this->assertSame($movementCountBefore, DB::table('inventory_movements')->count());
    }

    public function test_a_transaction_to_the_same_source_and_destination_account_is_rejected(): void
    {
        $cash = Account::create(['name' => 'Atomicity Transfer Cash', 'type' => 'cash', 'opening_balance' => 500, 'currency' => 'USD', 'is_active' => true]);

        $this->postJson('/api/transactions', [
            'type' => 'account_transfer',
            'account_id' => $cash->id,
            'destination_account_id' => $cash->id,
            'amount' => 100,
            'currency' => 'USD',
            'description' => 'Invalid same-account transfer',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('transactions')->where('type', 'account_transfer')->count());
    }

    public function test_customer_payment_cannot_exceed_the_outstanding_balance(): void
    {
        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Atomicity Overpay Rice',
            'sku' => 'ATOMIC-OVERPAY',
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);
        app(InventoryService::class)->record($rice, 50, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18);

        $customer = Person::create(['name' => 'Atomicity Overpay Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $cash = Account::create(['name' => 'Atomicity Overpay Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 10, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        // Sale total is 270; attempting to pay 500 must be rejected rather
        // than silently overpaying and leaving a negative balance_due.
        $this->postJson("/api/sales/{$sale['id']}/payments", [
            'amount' => 500,
            'account_id' => $cash->id,
        ])->assertStatus(422);

        $unchanged = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('270.00', $unchanged['balance_due']);
        $this->assertSame('unpaid', $unchanged['payment_status']);
    }
}

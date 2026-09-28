<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mirrors SaleVoidWorkflowTest for the purchase side. The key difference in
 * risk profile: a purchase void removes inventory rather than adding it, so
 * the safety guard here is "would this make stock negative" (because the
 * stock has already been sold/used) rather than an account-balance check.
 */
class PurchaseVoidWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private InventoryService $inventory;
    private Product $rice;
    private Supplier $supplier;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Voiding a purchase is Super-Admin-only now (see routes/api.php).
        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->balances = app(BalanceService::class);
        $this->inventory = app(InventoryService::class);

        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Purchase Void Rice',
            'sku' => 'PVOID-RICE',
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create(['supplier_code' => 'PVOID-SUP', 'name' => 'Purchase Void Supplier', 'is_active' => true]);
        $this->cash = Account::create([
            'name' => 'Purchase Void Cash',
            'type' => 'cash',
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_voiding_a_fully_paid_purchase_reverses_inventory_and_account_effects_exactly_once(): void
    {
        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 180,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('10.0000', $this->inventory->currentStock($this->rice));
        $this->assertSame(
            number_format($cashBefore - 180, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
        );

        $voided = $this->postJson("/api/purchases/{$purchase['id']}/void", [
            'reason' => 'Duplicate entry',
        ])->assertOk()->json('purchase');

        $this->assertSame('voided', $voided['status']);
        $this->assertSame('Duplicate entry', $voided['void_reason']);

        $this->assertSame('0.0000', $this->inventory->currentStock($this->rice), 'Stock restored to pre-purchase level.');
        $this->assertSame(
            number_format($cashBefore, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
            'Cash restored to pre-purchase level - the payment must be un-done, not double counted.',
        );

        $this->assertSame('voided', DB::table('transactions')->where('purchase_id', $purchase['id'])->value('status'));

        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'again'])->assertStatus(422);
        $this->assertSame('0.0000', $this->inventory->currentStock($this->rice), 'A second void must not remove stock twice.');
    }

    public function test_voiding_an_unpaid_purchase_reverses_the_supplier_payable(): void
    {
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('180.00', $this->balances->supplierBalance($this->supplier->fresh()));

        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'Unpaid purchase cancelled'])->assertOk();

        $this->assertSame('0.00', $this->balances->supplierBalance($this->supplier->fresh()));
    }

    public function test_voiding_is_refused_when_the_purchased_stock_has_already_been_sold(): void
    {
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('10.0000', $this->inventory->currentStock($this->rice));

        // Sell most of that stock elsewhere, so it's no longer available
        // to remove.
        $this->postJson('/api/sales', [
            'amount_paid' => 189,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 7, 'unit_price' => 27]],
        ])->assertCreated();

        $this->assertSame('3.0000', $this->inventory->currentStock($this->rice), '10 - 7 sold = 3 remaining.');

        // Voiding the purchase would need to remove 10, but only 3 remain.
        $response = $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'test']);
        $response->assertStatus(422);

        $unchanged = $this->getJson("/api/purchases/{$purchase['id']}")->assertOk()->json();
        $this->assertSame('posted', $unchanged['status']);
        $this->assertSame('3.0000', $this->inventory->currentStock($this->rice), 'Stock must be untouched by the refused void.');
    }

    // --- C4/H4 (accounting audit): once ANY of this purchase's stock has
    // left inventory (a sale, a return, or a negative adjustment) - even if
    // a LATER, unrelated replenishment makes pooled current stock look
    // sufficient again - voiding must be refused outright, never silently
    // corrupt the remaining weighted-average cost. See PurchaseService::
    // void()'s own doc comment for why a precise per-purchase reversal
    // (like the sale-side C2 fix) is not attempted here: weighted-average
    // inventory has no per-purchase provenance once pooled. ---

    public function test_voiding_is_refused_after_a_sale_even_if_a_later_purchase_replenishes_stock(): void
    {
        // Purchase A: 10 @ $10.
        $purchaseA = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 10]],
        ])->assertCreated()->json('purchase');

        // Sell 8 - only 2 of Purchase A's own units are left.
        $customer1 = \App\Models\Person::create(['name' => 'C4 Customer 1', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer1->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 8, 'unit_price' => 27]],
        ])->assertCreated();

        $this->assertSame('2.0000', $this->inventory->currentStock($this->rice));

        // Purchase B (unrelated): 10 @ $20 - pooled stock is now 12, which
        // would incorrectly satisfy the OLD "quantity <= current stock"
        // guard for voiding Purchase A (10 <= 12).
        $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated();

        $this->assertSame('12.0000', $this->inventory->currentStock($this->rice));
        $valueBeforeVoidAttempt = $this->inventory->inventoryValue($this->rice);

        // Voiding Purchase A must be refused - stock left the pool (the
        // sale) since Purchase A was recorded, regardless of the later
        // replenishment.
        $this->postJson("/api/purchases/{$purchaseA['id']}/void", ['reason' => 'test'])->assertStatus(422);

        // Nothing changed - no phantom removal, weighted-average cost basis
        // untouched.
        $this->assertSame('12.0000', $this->inventory->currentStock($this->rice));
        $this->assertSame($valueBeforeVoidAttempt, $this->inventory->inventoryValue($this->rice));
    }

    public function test_voiding_is_refused_after_an_unrelated_positive_inventory_adjustment(): void
    {
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        // Sell all 10, then a positive stock-count adjustment brings stock
        // back up from an unrelated source (found stock, a separate lot).
        $customer2 = \App\Models\Person::create(['name' => 'C4 Customer 2', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer2->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 27]],
        ])->assertCreated();

        $this->assertSame('0.0000', $this->inventory->currentStock($this->rice));

        $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->rice->id,
            'physical_quantity' => 15,
            'unit_cost' => 25,
            'reason' => 'Found stock during recount',
        ])->assertCreated();

        $this->assertSame('15.0000', $this->inventory->currentStock($this->rice));

        // Voiding the original purchase must still be refused - none of
        // its own units are actually still present, the adjustment's
        // stock is unrelated.
        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'test'])->assertStatus(422);

        $this->assertSame('15.0000', $this->inventory->currentStock($this->rice));
    }

    public function test_voiding_is_refused_after_a_partial_purchase_return(): void
    {
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 20, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'],
            'reason' => 'Damaged on arrival',
            'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 5]],
        ])->assertCreated();

        $this->assertSame('15.0000', $this->inventory->currentStock($this->rice), '20 - 5 returned.');

        // Nothing was sold and the pooled stock (15) is well below the
        // original quantity (20), but the purchase must still be refused -
        // a return against it already happened.
        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'test'])->assertStatus(422);

        $this->assertSame('15.0000', $this->inventory->currentStock($this->rice));
    }

    public function test_voiding_is_refused_after_a_full_purchase_return(): void
    {
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'],
            'reason' => 'Entire order rejected',
            'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 10]],
        ])->assertCreated();

        $this->assertSame('0.0000', $this->inventory->currentStock($this->rice));

        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'test'])->assertStatus(422);
    }
}

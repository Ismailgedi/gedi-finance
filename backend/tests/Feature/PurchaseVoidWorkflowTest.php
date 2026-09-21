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
        $this->actingAs(User::factory()->create());
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

        $this->postJson("/api/purchases/{$purchase['id']}/void", [])->assertStatus(422);
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

        $this->postJson("/api/purchases/{$purchase['id']}/void", [])->assertOk();

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
}

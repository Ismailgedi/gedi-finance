<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Voiding a sale must reverse every effect exactly once (inventory, COGS
 * exclusion from reports, customer receivable, account balance) while
 * keeping the original sale and its transactions in the database as an
 * auditable record - never deleted, never silently double-reversed. Also
 * proves the safety guard: a sale whose money has already left the
 * account must refuse to auto-void rather than create a negative balance.
 */
class SaleVoidWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private InventoryService $inventory;
    private Product $rice;
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
            'name' => 'Void Test Rice',
            'sku' => 'VOID-RICE',
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);
        $this->inventory->record($this->rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18);

        $this->cash = Account::create([
            'name' => 'Void Test Cash',
            'type' => 'cash',
            'opening_balance' => 500,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_voiding_a_cash_sale_reverses_inventory_and_account_effects_exactly_once(): void
    {
        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());
        $stockBefore = $this->inventory->currentStock($this->rice);

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 270,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->assertSame('90.0000', $this->inventory->currentStock($this->rice), '100 - 10 bags sold.');
        $this->assertSame(
            number_format($cashBefore + 270, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
        );

        $voided = $this->postJson("/api/sales/{$sale['id']}/void", [
            'reason' => 'Wrong customer selected',
        ])->assertOk()->json('sale');

        $this->assertSame('voided', $voided['status']);
        $this->assertNotNull($voided['voided_at']);
        $this->assertSame('Wrong customer selected', $voided['void_reason']);

        // Inventory restored exactly to the pre-sale level.
        $this->assertSame($stockBefore, $this->inventory->currentStock($this->rice));

        // Account balance restored exactly to the pre-sale level - the
        // +270 effect must be gone, not double-removed.
        $this->assertSame(
            number_format($cashBefore, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
        );

        // The original transaction row(s) still exist (audit trail), just
        // no longer posted.
        $this->assertSame(1, DB::table('transactions')->where('sale_id', $sale['id'])->count());
        $this->assertSame('voided', DB::table('transactions')->where('sale_id', $sale['id'])->value('status'));

        // Voiding twice must be refused, not silently reverse everything
        // a second time.
        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'again'])->assertStatus(422);
        $this->assertSame($stockBefore, $this->inventory->currentStock($this->rice), 'A second void attempt must not add inventory back twice.');
        $this->assertSame(
            number_format($cashBefore, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
            'A second void attempt must not touch the account balance again.',
        );
    }

    public function test_voiding_an_unpaid_credit_sale_reverses_the_customer_receivable(): void
    {
        $customer = Person::create(['name' => 'Void Test Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->assertSame('270.00', $this->balances->customerReceivableBalance($customer->fresh()));

        $this->postJson("/api/sales/{$sale['id']}/void", [])->assertOk();

        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()));
        $this->assertSame('100.0000', $this->inventory->currentStock($this->rice));
    }

    public function test_a_voided_sale_is_excluded_from_active_business_reports(): void
    {
        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 270,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $summaryBefore = $this->getJson('/api/reports/business-summary?range=this_year')->assertOk()->json();
        $this->assertSame('270.00', $summaryBefore['financial']['total_sales']);

        $this->postJson("/api/sales/{$sale['id']}/void", [])->assertOk();

        $summaryAfter = $this->getJson('/api/reports/business-summary?range=this_year')->assertOk()->json();
        $this->assertSame('0.00', $summaryAfter['financial']['total_sales'], 'A voided sale must not count toward active revenue.');

        // But it must still be visible in the sales list (the audit view).
        $list = $this->getJson('/api/sales')->assertOk()->json();
        $found = collect($list['data'])->firstWhere('id', $sale['id']);
        $this->assertNotNull($found, 'A voided sale must remain visible in the sales list for audit purposes.');
        $this->assertSame('voided', $found['status']);
    }

    public function test_voiding_is_refused_when_the_account_would_go_negative(): void
    {
        $customer = Person::create(['name' => 'Void Safety Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        // A small opening balance account, so the sale's own payment is
        // most of what's in it.
        $tightAccount = Account::create([
            'name' => 'Void Safety Cash',
            'type' => 'cash',
            'opening_balance' => 10,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 270,
            'account_id' => $tightAccount->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->assertSame('280.00', $this->balances->accountBalance($tightAccount->fresh()), '10 opening + 270 sale.');

        // Spend most of that money elsewhere (an unrelated expense), so
        // the sale's contribution is no longer sitting in the account.
        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $tightAccount->id,
            'amount' => 250,
            'currency' => 'USD',
            'description' => 'Spent the sale proceeds on something else',
        ])->assertCreated();

        $this->assertSame('30.00', $this->balances->accountBalance($tightAccount->fresh()), '280 - 250 spent.');

        // Voiding would need to remove 270, which would take the account
        // to -240 - must be refused, not silently applied.
        $response = $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'test']);
        $response->assertStatus(422);

        // Nothing must have changed - the sale is still posted, stock
        // still reflects the sale, account balance untouched.
        $unchangedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('posted', $unchangedSale['status']);
        $this->assertSame('90.0000', $this->inventory->currentStock($this->rice));
        $this->assertSame('30.00', $this->balances->accountBalance($tightAccount->fresh()));
    }
}

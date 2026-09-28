<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
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
 * Purchase landed costs: transport/customs/other purchase-related costs
 * that become part of inventory cost rather than an ordinary expense - see
 * PurchaseAdditionalCost, PurchaseService::create()/void(). Every example
 * matches the spec's own scenario: 100 bags at $30 plus $200 transport =
 * $32/bag landed cost.
 */
class PurchaseLandedCostWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Supplier $supplier;
    private Product $maize;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Voiding a purchase is Super-Admin-only now (see routes/api.php).
        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->cash = Account::create([
            'name' => 'Landed Cost Cash',
            'type' => 'cash',
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'supplier_code' => 'LC-SUP',
            'name' => 'Landed Cost Supplier',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'Landed Cost Bag', 'abbreviation' => 'lcbag']);
        $this->maize = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Landed Cost Maize',
            'sku' => 'LC-MAIZE',
            'default_cost_price' => 30,
            'default_selling_price' => 40,
            'is_active' => true,
        ]);
    }

    private function purchase(array $overrides = []): array
    {
        return $this->postJson('/api/purchases', array_merge([
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [[
                'product_id' => $this->maize->id,
                'quantity' => 100,
                'unit_cost' => 30,
            ]],
        ], $overrides))->assertCreated()->json('purchase');
    }

    // 1. Single-product purchase with a supplier-bundled cost.
    public function test_single_product_purchase_with_supplier_bundled_cost(): void
    {
        $purchase = $this->purchase([
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 200, 'type' => 'supplier_bundled'],
            ],
        ]);

        $this->assertSame('3000.00', $purchase['subtotal']);
        $this->assertSame('3200.00', $purchase['total']);
        $this->assertSame('3200.00', $purchase['balance_due']);
    }

    // 2. Single-product purchase with a third-party cost.
    public function test_single_product_purchase_with_third_party_cost(): void
    {
        $purchase = $this->purchase([
            'additional_costs' => [
                ['description' => 'Truck company', 'amount' => 200, 'type' => 'third_party', 'account_id' => $this->cash->id],
            ],
        ]);

        $this->assertSame('3000.00', $purchase['total']);
        $this->assertSame('3000.00', $purchase['balance_due']);
    }

    // 3. Multi-product purchase with allocation by line value.
    public function test_multi_product_purchase_allocates_by_line_value(): void
    {
        $bag = Unit::create(['name' => 'Second Bag', 'abbreviation' => 'sbag']);
        $sugar = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Landed Cost Sugar',
            'sku' => 'LC-SUGAR',
            'default_cost_price' => 20,
            'is_active' => true,
        ]);

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [
                ['product_id' => $this->maize->id, 'quantity' => 10, 'unit_cost' => 10],
                ['product_id' => $sugar->id, 'quantity' => 5, 'unit_cost' => 20],
            ],
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 60, 'type' => 'supplier_bundled'],
            ],
        ])->assertCreated()->json('purchase');

        // Each line is $100 of the $200 subtotal (50/50), so each absorbs
        // $30 of the $60 additional cost.
        $maizeItem = collect($purchase['items'])->firstWhere('product_id', $this->maize->id);
        $sugarItem = collect($purchase['items'])->firstWhere('product_id', $sugar->id);

        $this->assertSame((float) 13, (float) $maizeItem['landed_unit_cost']); // 10 + 30/10
        $this->assertSame((float) 26, (float) $sugarItem['landed_unit_cost']); // 20 + 30/5
    }

    // 4. Correct landed unit cost (the spec's own example).
    public function test_correct_landed_unit_cost(): void
    {
        $purchase = $this->purchase([
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 200, 'type' => 'supplier_bundled'],
            ],
        ]);

        $this->assertSame((float) 32, (float) $purchase['items'][0]['landed_unit_cost']);
        $this->assertSame((float) 30, (float) $purchase['items'][0]['unit_cost']);
    }

    // 5. Weighted-average inventory cost uses the landed cost.
    public function test_weighted_average_inventory_cost_uses_landed_cost(): void
    {
        $this->purchase([
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 200, 'type' => 'third_party', 'account_id' => $this->cash->id],
            ],
        ]);

        $this->assertSame('32.0000', app(InventoryService::class)->weightedAverageCost($this->maize->fresh()));
    }

    // 6. Future COGS uses the landed cost.
    public function test_future_cogs_uses_landed_cost(): void
    {
        $this->purchase([
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 200, 'type' => 'supplier_bundled'],
            ],
        ]);

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 400,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');

        $this->assertSame('320.00', $sale['cost_of_goods_sold']);
    }

    // 7. Gross profit uses landed-cost COGS.
    public function test_gross_profit_uses_landed_cost_cogs(): void
    {
        $this->purchase([
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 200, 'type' => 'supplier_bundled'],
            ],
        ]);

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 400,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');

        // 400 total - 320 landed-cost COGS = 80.
        $this->assertSame('80.00', $sale['gross_profit']);
    }

    // 8. Supplier payable includes the supplier-bundled cost.
    public function test_supplier_payable_includes_bundled_cost(): void
    {
        $this->purchase([
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 200, 'type' => 'supplier_bundled'],
            ],
        ]);

        $balances = app(BalanceService::class);
        $this->assertSame('3200.00', $balances->supplierBalance($this->supplier->fresh()));
    }

    // 9. Supplier payable excludes the third-party cost.
    public function test_supplier_payable_excludes_third_party_cost(): void
    {
        $this->purchase([
            'additional_costs' => [
                ['description' => 'Truck company', 'amount' => 200, 'type' => 'third_party', 'account_id' => $this->cash->id],
            ],
        ]);

        $balances = app(BalanceService::class);
        $this->assertSame('3000.00', $balances->supplierBalance($this->supplier->fresh()));
    }

    // 10. Third-party payment reduces the selected account.
    public function test_third_party_payment_reduces_the_selected_account(): void
    {
        $balances = app(BalanceService::class);
        $before = (float) $balances->accountBalance($this->cash->fresh());

        $this->purchase([
            'additional_costs' => [
                ['description' => 'Truck company', 'amount' => 200, 'type' => 'third_party', 'account_id' => $this->cash->id],
            ],
        ]);

        $this->assertSame(
            number_format($before - 200, 2, '.', ''),
            $balances->accountBalance($this->cash->fresh()),
        );
    }

    // 11. Third-party landed cost is excluded from operating expenses.
    public function test_third_party_landed_cost_excluded_from_operating_expenses(): void
    {
        $this->purchase([
            'additional_costs' => [
                ['description' => 'Truck company', 'amount' => 200, 'type' => 'third_party', 'account_id' => $this->cash->id],
            ],
        ]);

        $profit = $this->getJson('/api/reports/profit?range=today')->assertOk()->json();

        $this->assertSame('0.00', $profit['operating_expenses']['total']);
        $this->assertSame(1, DB::table('transactions')->where('type', 'purchase_cost')->count());
        $this->assertSame(0, DB::table('transactions')->where('type', 'expense')->count());
    }

    // 12. Inventory valuation includes the (unsold) landed cost.
    public function test_inventory_valuation_includes_unsold_landed_cost(): void
    {
        $this->purchase([
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 200, 'type' => 'supplier_bundled'],
            ],
        ]);

        $inventory = $this->getJson('/api/reports/inventory')->assertOk()->json('data');
        $row = collect($inventory)->firstWhere('id', $this->maize->id);

        $this->assertSame('3200.00', $row['inventory_value']);
    }

    // 13. Purchase void correctly reverses landed inventory/accounting effects.
    public function test_purchase_void_reverses_landed_cost_effects(): void
    {
        $balances = app(BalanceService::class);
        $cashBefore = (float) $balances->accountBalance($this->cash->fresh());

        $purchase = $this->purchase([
            'additional_costs' => [
                ['description' => 'Truck company', 'amount' => 200, 'type' => 'third_party', 'account_id' => $this->cash->id],
            ],
        ]);

        $this->assertSame(
            number_format($cashBefore - 200, 2, '.', ''),
            $balances->accountBalance($this->cash->fresh()),
        );

        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'Additional cost recorded in error'])->assertOk();

        $this->assertSame('voided', DB::table('purchases')->where('id', $purchase['id'])->value('status'));
        $this->assertSame('0.00', $balances->supplierBalance($this->supplier->fresh()));
        $this->assertSame(
            number_format($cashBefore, 2, '.', ''),
            $balances->accountBalance($this->cash->fresh()),
        );
        $this->assertSame('0.0000', app(InventoryService::class)->currentStock($this->maize->fresh()));
        $this->assertSame(
            'voided',
            DB::table('transactions')->where('type', 'purchase_cost')->where('purchase_id', $purchase['id'])->value('status'),
        );
    }

    // 14. Multiple additional costs on one purchase.
    public function test_multiple_additional_costs_on_one_purchase(): void
    {
        $balances = app(BalanceService::class);
        $cashBefore = (float) $balances->accountBalance($this->cash->fresh());

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 50, 'unit_cost' => 10]],
            'additional_costs' => [
                ['description' => 'Customs', 'amount' => 100, 'type' => 'supplier_bundled'],
                ['description' => 'Truck company', 'amount' => 50, 'type' => 'third_party', 'account_id' => $this->cash->id],
            ],
        ])->assertCreated()->json('purchase');

        // Goods 500 + bundled 100 = 600 supplier payable.
        $this->assertSame('600.00', $purchase['total']);
        // Both costs (150 total) allocate into the single line's landed cost.
        $this->assertSame((float) 13, (float) $purchase['items'][0]['landed_unit_cost']); // 10 + 150/50
        $this->assertCount(2, $purchase['additional_costs']);

        $this->assertSame(
            number_format($cashBefore - 50, 2, '.', ''),
            $balances->accountBalance($this->cash->fresh()),
        );
    }

    // 15. Existing purchases without additional costs still behave exactly as before.
    public function test_existing_purchases_without_additional_costs_are_unaffected(): void
    {
        $purchase = $this->purchase();

        $this->assertSame('3000.00', $purchase['total']);
        $this->assertSame((float) 30, (float) $purchase['items'][0]['landed_unit_cost']);
        $this->assertSame((float) 30, (float) $purchase['items'][0]['unit_cost']);
        $this->assertSame('30.0000', app(InventoryService::class)->weightedAverageCost($this->maize->fresh()));

        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'Test void'])->assertOk();
        $this->assertSame('0.0000', app(InventoryService::class)->currentStock($this->maize->fresh()));
    }

    // 16. Existing sales/payments/reporting still work alongside this feature.
    public function test_existing_sale_and_payment_workflows_still_work(): void
    {
        $this->purchase();

        $customer = Person::create(['name' => 'Landed Cost Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $balances = app(BalanceService::class);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 5, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');

        $this->assertSame('200.00', $balances->customerReceivableBalance($customer->fresh()));

        $this->postJson("/api/sales/{$sale['id']}/payments", [
            'amount' => 200,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('0.00', $balances->customerReceivableBalance($customer->fresh()));

        $secondPurchase = $this->purchase();

        $payment = $this->postJson("/api/purchases/{$secondPurchase['id']}/payments", [
            'amount' => 3000,
            'account_id' => $this->cash->id,
        ])->assertOk()->json('purchase');

        $this->assertSame('0.00', $payment['balance_due']);
    }

    // Limitation: an unpaid third-party cost is rejected rather than
    // silently misclassified (no generic accounts-payable concept exists
    // for a third party today).
    public function test_third_party_cost_without_an_account_is_rejected(): void
    {
        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 100, 'unit_cost' => 30]],
            'additional_costs' => [
                ['description' => 'Truck company', 'amount' => 200, 'type' => 'third_party'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('purchases')->count());
    }

    // --- H3 (accounting audit): a purchase discount must reduce the
    // capitalized inventory cost, not just the supplier payable. ---

    public function test_purchase_discount_reduces_the_capitalized_inventory_cost(): void
    {
        // 100 @ $30 = $3,000 subtotal, discount $300 -> supplier payable
        // $2,700, and inventory must be capitalized at that same $2,700,
        // never the pre-discount $3,000 - a $27/bag landed cost.
        $purchase = $this->purchase(['discount' => 300]);

        $this->assertSame('2700.00', $purchase['total']);
        $this->assertSame('27.0000', $purchase['items'][0]['landed_unit_cost']);

        $this->assertSame(
            '2700.00',
            app(InventoryService::class)->inventoryValue($this->maize->fresh()),
            '100 bags at the discounted $27/bag landed cost.',
        );
        $this->assertSame('27.0000', app(InventoryService::class)->weightedAverageCost($this->maize->fresh()));
    }

    public function test_purchase_discount_and_landed_cost_combine_correctly(): void
    {
        // 100 @ $30 = $3,000 subtotal, discount $300, plus a $200
        // supplier-bundled transport cost -> capitalized basis is
        // (3000 - 300 + 200) = 2900, a $29/bag landed cost. The payable
        // ($2900) matches exactly, since the discount and the bundled cost
        // both flow through the same $total formula.
        $purchase = $this->purchase([
            'discount' => 300,
            'additional_costs' => [
                ['description' => 'Transport', 'amount' => 200, 'type' => 'supplier_bundled'],
            ],
        ]);

        $this->assertSame('2900.00', $purchase['total']);
        $this->assertSame('29.0000', $purchase['items'][0]['landed_unit_cost']);
        $this->assertSame('2900.00', app(InventoryService::class)->inventoryValue($this->maize->fresh()));
    }

    public function test_purchase_discount_reduces_cogs_on_a_later_sale(): void
    {
        $this->purchase(['discount' => 300]);
        // Capitalized at $27/bag (see above).

        $customer = Person::create(['name' => 'H3 Sale Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');

        // COGS for 10 bags at the discounted $27/bag basis = $270, never
        // the pre-discount $300.
        $this->assertSame('270.00', $sale['cost_of_goods_sold']);
    }
}

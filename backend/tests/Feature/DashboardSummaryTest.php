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
 * Verifies the additive fields GET /api/dashboard now returns
 * (receivables.customer_outstanding, payables.outstanding,
 * loans.outstanding_given/received) actually separate customer credit,
 * supplier payables and loans instead of collapsing them into one number -
 * this is the exact ambiguity the wholesale-business product refinement
 * was meant to remove from the dashboard.
 */
class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_dashboard_separates_customer_receivables_supplier_payables_and_loans(): void
    {
        $cash = Account::create([
            'name' => 'Dashboard Test Cash',
            'type' => 'cash',
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        // Customer receivable: a credit sale, partially paid.
        $piece = Unit::create(['name' => 'Piece', 'abbreviation' => 'pc']);
        $rice = Product::create([
            'base_unit_id' => $piece->id,
            'name' => 'Dashboard Test Rice',
            'sku' => 'DASH-RICE',
            'default_cost_price' => 10,
            'default_selling_price' => 15,
            'is_active' => true,
        ]);
        app(InventoryService::class)->record($rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 10);

        $customer = Person::create([
            'name' => 'Dashboard Test Customer',
            'is_active' => true,
            'is_customer' => true,
            'roles' => ['customer'],
        ]);

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 40,
            'account_id' => $cash->id,
            'items' => [
                ['product_id' => $rice->id, 'quantity' => 10, 'unit_price' => 15],
            ],
        ])->assertCreated();
        // total 150, paid 40 -> receivable of 110

        // Supplier payable: a loan/debt on the same Person model must NOT
        // leak into this - it is a completely separate concept.
        $supplier = Supplier::create([
            'supplier_code' => 'DASH-SUP-1',
            'name' => 'Dashboard Test Supplier',
            'is_active' => true,
        ]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 30,
            'account_id' => $cash->id,
            'items' => [
                ['product_id' => $rice->id, 'quantity' => 20, 'unit_cost' => 8],
            ],
        ])->assertCreated();
        // total 160, paid 30 -> payable of 130

        // Loan given to a person (not the customer above), partially repaid.
        $borrower = Person::create([
            'name' => 'Dashboard Test Borrower',
            'is_active' => true,
            'roles' => ['borrower'],
        ]);

        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $borrower->id,
            'account_id' => $cash->id,
            'amount' => 500,
            'currency' => 'USD',
            'description' => 'Loan given to borrower',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'loan_repayment',
            'person_id' => $borrower->id,
            'account_id' => $cash->id,
            'amount' => 200,
            'currency' => 'USD',
            'description' => 'Partial loan repayment',
        ])->assertCreated();
        // 500 - 200 = 300 still outstanding, owed to the business

        // A separate borrowing: the business itself borrowed money.
        $lender = Person::create([
            'name' => 'Dashboard Test Lender',
            'is_active' => true,
            'roles' => ['lender'],
        ]);

        $this->postJson('/api/transactions', [
            'type' => 'loan_received',
            'person_id' => $lender->id,
            'account_id' => $cash->id,
            'amount' => 250,
            'currency' => 'USD',
            'description' => 'Loan received from lender',
        ])->assertCreated();

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('110.00', $dashboard['receivables']['customer_outstanding']);
        $this->assertSame('130.00', $dashboard['payables']['outstanding']);
        $this->assertSame('300.00', $dashboard['loans']['outstanding_given']);
        $this->assertSame('250.00', $dashboard['loans']['outstanding_received']);

        // The customer's credit-sale receivable must not bleed into the
        // supplier payable figure, and the loan given/received must not be
        // netted into either - each stays in its own bucket.
        $this->assertNotSame($dashboard['receivables']['customer_outstanding'], $dashboard['payables']['outstanding']);

        // The old increases/decreases/outstanding trio (which mixed
        // customer receivables, loans and other receivables into one
        // number) is gone for good - `receivables` now only ever carries
        // the clearly-scoped customer_outstanding.
        $this->assertSame(['customer_outstanding'], array_keys($dashboard['receivables']));

        // money_position mirrors the same figures via BusinessCapitalService::
        // position(), each bucket still separate.
        $this->assertSame('110.00', $dashboard['money_position']['owed_to_gedi']['customer_receivables']);
        $this->assertSame('300.00', $dashboard['money_position']['owed_to_gedi']['loans_given']);
        $this->assertSame('130.00', $dashboard['money_position']['owed_by_gedi']['supplier_payables']);
        $this->assertSame('250.00', $dashboard['money_position']['owed_by_gedi']['loans_received']);
    }

    // --- Opening-balance-only loans (previously excluded by the dashboard's
    // hand-written SQL - see DashboardController's own doc comment) ---

    private function superAdmin(): User
    {
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        return $admin;
    }

    private function lockOpeningBalance(array $items, float $openingEquity): void
    {
        $current = $this->actingAs($this->superAdmin());

        $current->postJson('/api/admin/opening-balance', [
            'as_of_date' => '2026-01-01',
            'opening_equity' => $openingEquity,
            'items' => $items,
        ])->assertOk();

        $current->postJson('/api/admin/opening-balance/lock')->assertOk();
    }

    public function test_dashboard_includes_opening_balance_only_loan_given(): void
    {
        $borrower = Person::create(['name' => 'Dashboard OB Borrower', 'is_active' => true, 'roles' => []]);

        $this->lockOpeningBalance(
            [['category' => 'loan_given', 'person_id' => $borrower->id, 'amount' => 700]],
            700,
        );

        $this->actingAs(User::factory()->create());
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('700.00', $dashboard['loans']['outstanding_given']);
        $this->assertSame('700.00', $dashboard['money_position']['owed_to_gedi']['loans_given']);
    }

    public function test_dashboard_includes_opening_balance_only_loan_received(): void
    {
        $lender = Person::create(['name' => 'Dashboard OB Lender', 'is_active' => true, 'roles' => []]);

        $this->lockOpeningBalance(
            [['category' => 'loan_received', 'person_id' => $lender->id, 'amount' => 450]],
            -450,
        );

        $this->actingAs(User::factory()->create());
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('450.00', $dashboard['loans']['outstanding_received']);
        $this->assertSame('450.00', $dashboard['money_position']['owed_by_gedi']['loans_received']);
    }

    public function test_dashboard_shows_other_receivables_separately(): void
    {
        $debtor = Person::create(['name' => 'Dashboard Debtor', 'is_active' => true, 'roles' => []]);

        $this->postJson('/api/transactions', [
            'type' => 'debt_created',
            'person_id' => $debtor->id,
            'amount' => 275,
            'description' => 'Other receivable created',
        ])->assertCreated();

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('275.00', $dashboard['money_position']['owed_to_gedi']['other_receivables']);
        // Never bleeds into customer receivables or loans given.
        $this->assertSame('0.00', $dashboard['money_position']['owed_to_gedi']['customer_receivables']);
        $this->assertSame('0.00', $dashboard['money_position']['owed_to_gedi']['loans_given']);
    }

    public function test_dashboard_shows_customer_credit(): void
    {
        $cash = Account::create(['name' => 'Dashboard Credit Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Dashboard Credit Unit', 'abbreviation' => 'dcu' . uniqid()]);
        $product = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'Dashboard Credit Rice', 'sku' => 'DASH-CREDIT-' . uniqid(),
            'default_cost_price' => 25, 'default_selling_price' => 40, 'is_active' => true,
        ]);
        app(InventoryService::class)->record($product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 25);

        $customer = Person::create(['name' => 'Dashboard Credit Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 400, 'account_id' => $cash->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');

        // Fully paid, then a credit-settled return - the excess becomes a
        // customer credit (a negative receivable), a real liability.
        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'reason' => 'Customer changed their mind',
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2, 'is_saleable' => true]],
        ])->assertCreated();

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('80.00', $dashboard['money_position']['owed_by_gedi']['customer_credits']);
    }

    public function test_dashboard_shows_supplier_credit(): void
    {
        $cash = Account::create(['name' => 'Dashboard Supplier Credit Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Dashboard Supplier Credit Unit', 'abbreviation' => 'dscu' . uniqid()]);
        $product = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'Dashboard Supplier Credit Rice', 'sku' => 'DASH-SUP-CREDIT-' . uniqid(),
            'default_cost_price' => 18, 'default_selling_price' => 30, 'is_active' => true,
        ]);
        $supplier = Supplier::create(['supplier_code' => 'DASH-SUP-CREDIT-' . uniqid(), 'name' => 'Dashboard Supplier Credit', 'is_active' => true]);

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 360, 'account_id' => $cash->id,
            'items' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'],
            'reason' => 'Damaged on arrival',
            'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 5]],
        ])->assertCreated();

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('90.00', $dashboard['money_position']['owed_to_gedi']['supplier_credits']);
    }

    public function test_dashboard_shows_inventory_value_and_low_stock(): void
    {
        $unit = Unit::create(['name' => 'Dashboard Inv Unit', 'abbreviation' => 'diu' . uniqid()]);
        $product = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'Dashboard Inv Product', 'sku' => 'DASH-INV-' . uniqid(),
            'default_cost_price' => 12, 'default_selling_price' => 20, 'is_active' => true, 'minimum_stock' => 100,
        ]);
        app(InventoryService::class)->record($product, 10, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 12);

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('120.00', $dashboard['inventory']['inventory_value']);
        $this->assertGreaterThanOrEqual(1, $dashboard['inventory']['active_products']);
        $this->assertSame(1, $dashboard['inventory']['low_stock_products']);
        $this->assertGreaterThanOrEqual(1, $dashboard['inventory']['stock_requiring_attention']);
        $this->assertSame($dashboard['inventory']['low_stock_products'], $dashboard['attention']['low_stock_products']);
    }

    public function test_dashboard_shows_overdue_customer_receivables(): void
    {
        $unit = Unit::create(['name' => 'Dashboard Overdue Unit', 'abbreviation' => 'dou' . uniqid()]);
        $product = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'Dashboard Overdue Product', 'sku' => 'DASH-OVERDUE-' . uniqid(),
            'default_cost_price' => 10, 'default_selling_price' => 15, 'is_active' => true,
        ]);
        app(InventoryService::class)->record($product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);

        $customer = Person::create(['name' => 'Dashboard Overdue Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'due_date' => now()->subDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 15]],
        ])->assertCreated();

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('75.00', $dashboard['attention']['overdue_customer_balance']);
    }

    public function test_dashboard_net_profit_includes_other_income(): void
    {
        $cash = Account::create(['name' => 'Dashboard Profit Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        $person = Person::create(['name' => 'Dashboard Profit Debtor', 'is_active' => true, 'roles' => []]);
        $this->postJson('/api/transactions', [
            'type' => 'debt_created', 'person_id' => $person->id, 'amount' => 300, 'description' => 'Other receivable',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'expense', 'account_id' => $cash->id, 'amount' => 50, 'description' => 'Rent',
        ])->assertCreated();

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        // gross_profit(0) - operating_expenses(50) + other_income(300) = 250.
        $this->assertSame('300.00', $dashboard['performance']['current_period']['other_income']);
        $this->assertSame('50.00', $dashboard['performance']['current_period']['operating_expenses']);
        $this->assertSame('250.00', $dashboard['performance']['current_period']['net_profit']);

        // The same definition profit() uses - never the old
        // gross_profit-expenses-only formula.
        $profit = $this->getJson('/api/reports/profit')->assertOk()->json();
        $this->assertSame($profit['net_profit'], $dashboard['performance']['current_period']['net_profit']);
    }

    public function test_dashboard_today_figures_remain_correct(): void
    {
        $cash = Account::create(['name' => 'Dashboard Today Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Dashboard Today Unit', 'abbreviation' => 'dtu' . uniqid()]);
        $product = Product::create([
            'base_unit_id' => $unit->id, 'name' => 'Dashboard Today Product', 'sku' => 'DASH-TODAY-' . uniqid(),
            'default_cost_price' => 10, 'default_selling_price' => 20, 'is_active' => true,
        ]);
        app(InventoryService::class)->record($product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);

        $customer = Person::create(['name' => 'Dashboard Today Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 200, 'account_id' => $cash->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 20]],
        ])->assertCreated();

        $supplier = Supplier::create(['supplier_code' => 'DASH-TODAY-' . uniqid(), 'name' => 'Dashboard Today Supplier', 'is_active' => true]);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 10]],
        ])->assertCreated();

        $summary = $this->getJson('/api/reports/business-summary?range=today')->assertOk()->json();
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame($summary['sales']['today_sales'], $dashboard['today']['sales_total']);
        $this->assertSame($summary['purchases']['purchase_value'], $dashboard['today']['purchases_total']);
        $this->assertSame($summary['financial']['gross_profit'], $dashboard['today']['gross_profit']);
        $this->assertSame($summary['sales']['today_sales'], $dashboard['performance']['today']['sales']);
        $this->assertSame($summary['purchases']['purchase_value'], $dashboard['performance']['today']['purchases']);
    }

    public function test_dashboard_shows_open_financial_year_by_default(): void
    {
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertFalse($dashboard['attention']['financial_year']['is_closed']);
        $this->assertNull($dashboard['attention']['financial_year']['closure']);
    }

    public function test_dashboard_reflects_closed_financial_year_status(): void
    {
        // 2025 has already fully elapsed relative to any real "now" this
        // suite runs under, so it can be closed with no time travel.
        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/financial-years/2025/close')
            ->assertOk();

        // The dashboard's own snapshot always covers the CURRENT calendar
        // year (see DashboardController - it mirrors Business Position's own
        // default range), which can never itself be the year just closed -
        // so freeze "now" to land inside 2025 to exercise that snapshot
        // actually being closed, then restore it so no other test is
        // affected.
        \Illuminate\Support\Carbon::setTestNow('2025-06-15');
        try {
            $this->actingAs(User::factory()->create());
            $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }

        $this->assertTrue($dashboard['attention']['financial_year']['is_closed']);
        $this->assertSame(2025, $dashboard['attention']['financial_year']['closure']['financial_year']);
    }

    public function test_dashboard_shows_opening_balance_not_locked_by_default(): void
    {
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertFalse($dashboard['attention']['opening_balance']['configured']);
        $this->assertNull($dashboard['attention']['opening_balance']['status']);
    }

    public function test_dashboard_shows_opening_balance_locked_status(): void
    {
        $person = Person::create(['name' => 'Dashboard OB Status Person', 'is_active' => true, 'roles' => []]);
        $this->lockOpeningBalance(
            [['category' => 'other_receivable', 'person_id' => $person->id, 'amount' => 50]],
            50,
        );

        $this->actingAs(User::factory()->create());
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertTrue($dashboard['attention']['opening_balance']['configured']);
        $this->assertSame('locked', $dashboard['attention']['opening_balance']['status']);
        $this->assertSame('2026-01-01', $dashboard['attention']['opening_balance']['as_of_date']);
    }
}

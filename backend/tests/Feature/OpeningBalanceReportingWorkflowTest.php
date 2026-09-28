<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ReportExportService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * How the Opening Balance interacts with the rest of the reporting system:
 * aging, credit limits, Business Position, P&L, year-to-year continuity,
 * a closed financial year, and Excel export. OpeningBalanceWorkflowTest
 * already proves the posting mechanics themselves.
 */
class OpeningBalanceReportingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ReportExportService $reports;
    private Account $cash;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        Role::findOrCreate('Super Admin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->reports = app(ReportExportService::class);

        $this->cash = Account::create([
            'name' => 'OB Reporting Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'OB Reporting Bag ' . uniqid(), 'abbreviation' => 'obrb' . uniqid()]);
        $this->product = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'OB Reporting Rice',
            'sku' => 'OBREP-RICE-' . uniqid(),
            'default_cost_price' => 20,
            'default_selling_price' => 30,
            'is_active' => true,
        ]);
    }

    private function lockOpeningBalance(array $overrides = []): void
    {
        $payload = array_merge([
            'as_of_date' => '2026-01-01',
            'opening_equity' => 0,
            'items' => [],
        ], $overrides);

        $this->postJson('/api/admin/opening-balance', $payload)->assertOk();
        $this->postJson('/api/admin/opening-balance/lock')->assertOk();
    }

    // --- Aging ---

    public function test_opening_customer_receivable_appears_in_the_aging_bucket(): void
    {
        $customer = Person::create(['name' => 'Aging OB Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->lockOpeningBalance([
            'opening_equity' => 400,
            'items' => [['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 400]],
        ]);

        $response = $this->getJson('/api/reports/customer-receivables')->assertOk();
        $buckets = collect($response->json('aging.buckets'))->keyBy('label');

        $this->assertSame('400.00', $buckets['Opening Balance']['outstanding']);
        $this->assertSame('400.00', $response->json('aging.total'));
    }

    public function test_opening_supplier_payable_appears_in_the_aging_bucket(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'AGING-OB-SUP-' . uniqid(), 'name' => 'Aging OB Supplier', 'is_active' => true]);

        $this->lockOpeningBalance([
            'opening_equity' => -650,
            'items' => [['category' => 'payable', 'supplier_id' => $supplier->id, 'amount' => 650]],
        ]);

        $response = $this->getJson('/api/reports/supplier-payables')->assertOk();
        $buckets = collect($response->json('aging.buckets'))->keyBy('label');

        $this->assertSame('650.00', $buckets['Opening Balance']['outstanding']);
    }

    // --- Credit limits ---

    public function test_credit_limits_include_opening_customer_receivables(): void
    {
        $customer = Person::create([
            'name' => 'Credit Limit OB Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer'], 'credit_limit' => 1000,
        ]);

        $this->lockOpeningBalance([
            'opening_equity' => 900,
            'items' => [['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 900]],
        ]);

        // 900 already owed + a further 200 credit sale would exceed the
        // 1000 limit - must be refused, proving the opening receivable
        // counts toward the same limit check ordinary credit sales do.
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 20]],
        ])->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_credit_limits_include_opening_supplier_payables(): void
    {
        $supplier = Supplier::create([
            'supplier_code' => 'CREDIT-OB-SUP-' . uniqid(), 'name' => 'Credit Limit OB Supplier', 'is_active' => true, 'credit_limit' => 1000,
        ]);

        $this->lockOpeningBalance([
            'opening_equity' => -900,
            'items' => [['category' => 'payable', 'supplier_id' => $supplier->id, 'amount' => 900]],
        ]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    // --- Opening balances never become current-year revenue/expense/contribution ---

    public function test_opening_balances_never_become_current_year_revenue_or_expense(): void
    {
        $customer = Person::create(['name' => 'No Revenue Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $supplier = Supplier::create(['supplier_code' => 'NOEXP-SUP-' . uniqid(), 'name' => 'No Expense Supplier', 'is_active' => true]);
        $lender = Person::create(['name' => 'No Contribution Lender', 'is_active' => true, 'roles' => []]);

        $this->lockOpeningBalance([
            'opening_equity' => 100 + 200 - 300,
            'items' => [
                ['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 100],
                ['category' => 'other_receivable', 'person_id' => $lender->id, 'amount' => 200],
                ['category' => 'payable', 'supplier_id' => $supplier->id, 'amount' => 300],
            ],
        ]);

        $profit = $this->getJson('/api/reports/profit?range=custom&from=2026-01-01&to=2026-01-01')->assertOk();
        $this->assertSame('0.00', $profit->json('revenue.sales_revenue'));
        $this->assertSame('0.00', $profit->json('operating_expenses.total'));
        $this->assertSame('0.00', $profit->json('other_income'), 'opening_balance_other_receivable must never recognize income the way debt_created does.');
        $this->assertSame('0.00', $profit->json('net_profit'));
    }

    public function test_opening_capital_never_becomes_current_year_owner_contribution(): void
    {
        $owner = Person::create(['name' => 'No Contribution Owner', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);

        $this->lockOpeningBalance([
            'opening_equity' => 5000,
            'accounts' => [['account_id' => $this->cash->id, 'opening_balance' => 5000]],
            'items' => [['category' => 'capital', 'person_id' => $owner->id, 'amount' => 5000]],
        ]);

        $position = $this->getJson('/api/reports/business-position?range=custom&from=2026-01-02&to=2026-01-02')->assertOk();
        $this->assertSame('0.00', $position->json('equity.owner_contributions'));
        $this->assertSame('5000.00', $position->json('equity.opening_equity'));
    }

    // --- Year-to-year equity continuity ---

    public function test_opening_equity_flows_into_a_closed_first_year_and_then_the_next_year(): void
    {
        $customer = Person::create(['name' => 'Continuity Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        // as_of_date is the last day BEFORE normal history begins, so it
        // must be the day before the first real financial year starts
        // (2019-12-31, not 2020-01-01) - otherwise "no transaction on or
        // before as_of_date" would forbid the very first day of that year.
        $this->lockOpeningBalance([
            'as_of_date' => '2019-12-31',
            'opening_equity' => 1000,
            'items' => [['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 1000]],
        ]);

        // Stock via a real purchase (dated safely after the opening
        // balance) so the sale below has a cost basis to sell against.
        $this->postJson('/api/purchases', [
            'supplier_id' => Supplier::create(['supplier_code' => 'CONT-SUP-' . uniqid(), 'name' => 'Continuity Supplier', 'is_active' => true])->id,
            'amount_paid' => 0,
            'purchase_date' => '2020-02-01',
            'items' => [['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 20]],
        ])->assertCreated();

        // A normal credit sale within year 2020 (after the opening date,
        // a year already fully in the past so it is closeable below).
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'sale_date' => '2020-06-01',
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 30]],
        ])->assertCreated();

        // The opening balance's own transaction (dated 2019-12-31) is the
        // earliest posted activity, so 2019 must close before 2020 can -
        // the same "close in order" rule any other year follows.
        $this->postJson('/api/admin/financial-years/2019/close')->assertOk();
        $this->postJson('/api/admin/financial-years/2020/close')->assertOk();

        $closed = $this->getJson('/api/reports/business-position?range=custom&from=2020-01-01&to=2020-12-31')->assertOk();
        $this->assertTrue($closed->json('is_closed'));
        $this->assertSame('1000.00', $closed->json('equity.opening_equity'));
        $closingEquity2020 = $closed->json('equity.closing_equity');

        // Year 2021's opening equity must come from 2020's frozen closing
        // equity, not be recomputed from the opening balance a second time.
        $year2021 = $this->getJson('/api/reports/business-position?range=custom&from=2021-01-01&to=2021-12-31')->assertOk();
        $this->assertSame($closingEquity2020, $year2021->json('equity.opening_equity'));
    }

    public function test_opening_equity_remains_part_of_cumulative_equity_when_the_first_year_is_never_closed(): void
    {
        $customer = Person::create(['name' => 'Unclosed Continuity Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->lockOpeningBalance([
            'opening_equity' => 2000,
            'items' => [['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 2000]],
        ]);

        // No close at all - query a LATER period directly. The opening
        // equity must still be folded into cumulativeEquityAsOf(), not
        // lost just because no year was ever explicitly closed.
        $laterYear = $this->getJson('/api/reports/business-position?range=custom&from=2028-01-01&to=2028-12-31')->assertOk();
        $this->assertSame('2000.00', $laterYear->json('equity.opening_equity'));
    }

    // --- Closed financial year interaction ---

    public function test_reopening_the_opening_balance_is_blocked_once_its_year_is_closed(): void
    {
        $person = Person::create(['name' => 'Closed Year OB Person', 'is_active' => true, 'roles' => []]);

        $this->lockOpeningBalance([
            'as_of_date' => '2019-12-31',
            'opening_equity' => 400,
            'items' => [['category' => 'other_receivable', 'person_id' => $person->id, 'amount' => 400]],
        ]);

        $this->postJson('/api/admin/financial-years/2019/close')->assertOk();

        // The opening_balance_* transactions are dated 2019-12-31, inside
        // the now-closed 2019 financial year - voiding them (which
        // reopening an Opening Balance requires) must be refused by the
        // existing financial-year protection, exactly as it would for any
        // other transaction dated inside a closed year.
        $this->postJson('/api/admin/opening-balance/reopen')->assertStatus(422);
    }

    // --- Excel reporting ---

    public function test_business_position_excel_export_reflects_the_opening_balance(): void
    {
        $customer = Person::create(['name' => 'Excel OB Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->lockOpeningBalance([
            'opening_equity' => 750,
            'items' => [['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 750]],
        ]);

        $report = $this->reports->businessPositionReport(['range' => 'custom', 'from' => '2026-01-02', 'to' => '2026-01-02']);
        $row = collect($report['rows'])->first(fn ($r) => str_contains((string) $r[1], 'Opening Equity'));

        $this->assertNotNull($row);
        $this->assertStringContainsString('Opening Balance as of 2026-01-01', $row[1]);
        $this->assertSame('750.00', $row[6]);

        $this->get('/api/reports/business-position/excel?range=custom&from=2026-01-02&to=2026-01-02')->assertOk();
    }
}

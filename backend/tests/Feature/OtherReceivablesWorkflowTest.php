<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use App\Services\ReportExportService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Other Receivables: the debt_created/debt_payment workflow (a receivable
 * created and settled with no sale, no inventory, and - for debt_created -
 * no account movement) as its own, separate asset line in the Business
 * Position report - never merged into Customer Receivables (strictly
 * credit_sale/customer_payment) or Loans Given (strictly the loan_* types).
 * See BusinessCapitalService::assetsAsOf().
 */
class OtherReceivablesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Product $maize;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // The owner_contribution test below needs Super Admin now (see
        // TransactionService::assertAuthorizedForCapitalMovement).
        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->cash = Account::create(['name' => 'OR Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        $unit = Unit::create(['name' => 'OR Bag', 'abbreviation' => 'orbag']);
        $this->maize = Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'OR Maize',
            'sku' => 'OR-MAIZE',
            'default_cost_price' => 30,
            'default_selling_price' => 40,
            'is_active' => true,
        ]);
    }

    private function position(?string $from = null, ?string $to = null): array
    {
        $query = array_filter(['from' => $from, 'to' => $to]);

        return $this->getJson('/api/reports/business-position?' . http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function debtCreated(Person $person, float $amount, ?string $date = null): void
    {
        $this->postJson('/api/transactions', array_filter([
            'type' => 'debt_created',
            'person_id' => $person->id,
            'amount' => $amount,
            'description' => 'Other receivable created',
            'transaction_date' => $date,
        ]))->assertCreated();
    }

    private function debtPayment(Person $person, float $amount, ?string $date = null): void
    {
        $this->postJson('/api/transactions', array_filter([
            'type' => 'debt_payment',
            'person_id' => $person->id,
            'account_id' => $this->cash->id,
            'amount' => $amount,
            'description' => 'Other receivable payment',
            'transaction_date' => $date,
        ]))->assertCreated();
    }

    // 1. debt_created increases Other Receivables.
    public function test_debt_created_increases_other_receivables(): void
    {
        $person = Person::create(['name' => 'OR Person', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($person, 150);

        $position = $this->position();
        $this->assertSame('150.00', $position['assets']['other_receivables']);
    }

    // 2. debt_payment reduces Other Receivables.
    public function test_debt_payment_reduces_other_receivables(): void
    {
        $person = Person::create(['name' => 'OR Person 2', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($person, 150);
        $this->debtPayment($person, 60);

        $position = $this->position();
        $this->assertSame('90.00', $position['assets']['other_receivables']);
    }

    // 3. Other Receivables appears in Business Position with the expected shape.
    public function test_other_receivables_appears_in_business_position(): void
    {
        $position = $this->position();

        $this->assertArrayHasKey('other_receivables', $position['assets']);
        $this->assertSame('0.00', $position['assets']['other_receivables']);
    }

    // 4. Customer Receivables remain separate from Other Receivables.
    public function test_customer_receivables_remain_separate(): void
    {
        app(InventoryService::class)->record($this->maize, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 30);

        $customer = Person::create(['name' => 'OR Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 5, 'unit_price' => 40]],
        ])->assertCreated();

        $debtor = Person::create(['name' => 'OR Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 75);

        $position = $this->position();

        $this->assertSame('200.00', $position['assets']['customer_receivables']);
        $this->assertSame('75.00', $position['assets']['other_receivables']);
    }

    // 5. Loans Given remain separate from Other Receivables.
    public function test_loans_given_remain_separate(): void
    {
        $borrower = Person::create(['name' => 'OR Borrower', 'is_active' => true, 'roles' => []]);
        $this->postJson('/api/transactions', [
            'type' => 'loan_given', 'person_id' => $borrower->id, 'account_id' => $this->cash->id,
            'amount' => 500, 'description' => 'Loan given',
        ])->assertCreated();

        $debtor = Person::create(['name' => 'OR Debtor 2', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 75);

        $position = $this->position();

        $this->assertSame('500.00', $position['assets']['loans_given']);
        $this->assertSame('75.00', $position['assets']['other_receivables']);
    }

    // 6. No double counting: each bucket reflects only its own transaction
    // types, and the combined total equals the exact sum of the three.
    public function test_no_double_counting_across_receivable_buckets(): void
    {
        app(InventoryService::class)->record($this->maize, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 30);

        $customer = Person::create(['name' => 'OR Combo Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 4, 'unit_price' => 40]],
        ])->assertCreated();

        $borrower = Person::create(['name' => 'OR Combo Borrower', 'is_active' => true, 'roles' => []]);
        $this->postJson('/api/transactions', [
            'type' => 'loan_given', 'person_id' => $borrower->id, 'account_id' => $this->cash->id,
            'amount' => 200, 'description' => 'Loan given',
        ])->assertCreated();

        $debtor = Person::create(['name' => 'OR Combo Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 50);

        $position = $this->position();

        $this->assertSame('160.00', $position['assets']['customer_receivables']);
        $this->assertSame('200.00', $position['assets']['loans_given']);
        $this->assertSame('50.00', $position['assets']['other_receivables']);

        $expectedTotal = 160.00 + 200.00 + 50.00 + (float) $position['assets']['cash_and_bank'] + (float) $position['assets']['inventory_at_cost'];
        $this->assertSame(number_format($expectedTotal, 2, '.', ''), $position['assets']['total']);

        // Cross-check: the DB-level sum of debt_* effects equals exactly
        // other_receivables, and never bleeds into the credit_sale/loan
        // sums (the three type-string sets are disjoint by construction).
        $rawDebtSum = (float) DB::table('transactions')->whereIn('type', ['debt_created', 'debt_payment'])->sum('person_balance_effect');
        $this->assertSame(number_format($rawDebtSum, 2, '.', ''), $position['assets']['other_receivables']);
    }

    // 7. Reconciliation still holds with Other Receivables included.
    public function test_assets_minus_liabilities_equals_closing_equity(): void
    {
        $owner = Person::create(['name' => 'OR Owner', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);
        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution', 'person_id' => $owner->id, 'account_id' => $this->cash->id,
            'amount' => 1000, 'description' => 'Capital',
        ])->assertCreated();

        $supplier = \App\Models\Supplier::create(['supplier_code' => 'OR-SUP', 'name' => 'OR Supplier', 'is_active' => true]);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 20, 'unit_cost' => 30]],
        ])->assertCreated();

        $customer = Person::create(['name' => 'OR Recon Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 5, 'unit_price' => 40]],
        ])->assertCreated();

        $position = $this->position();

        $this->assertTrue($position['check']['matches']);
    }

    /**
     * debt_created now recognizes Other Income at the same moment it
     * creates the receivable (see BusinessReportService::profit()), so the
     * asset it creates is always offset by an equal increase in equity -
     * unlike before, a standalone debt_created (nothing else recorded)
     * reconciles cleanly on its own.
     */
    public function test_debt_created_alone_reconciles_cleanly(): void
    {
        $debtor = Person::create(['name' => 'OR Gap Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 120);

        $position = $this->position();

        $this->assertTrue($position['check']['matches']);
        $this->assertSame('120.00', $position['equity']['other_income']);
        $this->assertSame('120.00', $position['equity']['profit_or_loss']);
        $this->assertSame('120.00', $position['equity']['closing_equity']);
    }

    // 2. debt_created creates Other Income.
    public function test_debt_created_creates_other_income(): void
    {
        $person = Person::create(['name' => 'OR Income Person', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($person, 200);

        $position = $this->position();
        $this->assertSame('200.00', $position['equity']['other_income']);

        $profit = $this->getJson('/api/reports/profit?range=this_year')->assertOk()->json();
        $this->assertSame('200.00', $profit['other_income']);
    }

    // 3. debt_created does not move cash.
    public function test_debt_created_does_not_move_cash(): void
    {
        $balances = app(BalanceService::class);
        $before = (float) $balances->accountBalance($this->cash->fresh());

        $person = Person::create(['name' => 'OR No Cash Person', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($person, 200);

        $this->assertSame(number_format($before, 2, '.', ''), $balances->accountBalance($this->cash->fresh()));
    }

    // 5. debt_payment increases the selected account.
    public function test_debt_payment_increases_the_selected_account(): void
    {
        $balances = app(BalanceService::class);
        $before = (float) $balances->accountBalance($this->cash->fresh());

        $person = Person::create(['name' => 'OR Payment Account Person', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($person, 200);
        $this->debtPayment($person, 80);

        $this->assertSame(number_format($before + 80, 2, '.', ''), $balances->accountBalance($this->cash->fresh()));
    }

    // 6. debt_payment does not create additional income.
    public function test_debt_payment_does_not_create_additional_income(): void
    {
        $person = Person::create(['name' => 'OR No Double Income Person', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($person, 200);
        $this->debtPayment($person, 80);
        $this->debtPayment($person, 40);

        $position = $this->position();

        // Income was recognized once, at creation - collecting it in one
        // or several payments never adds more.
        $this->assertSame('200.00', $position['equity']['other_income']);
    }

    // 9. Other Income remains separate from normal sales revenue.
    public function test_other_income_remains_separate_from_sales_revenue(): void
    {
        app(InventoryService::class)->record($this->maize, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 30);

        $customer = Person::create(['name' => 'OR Revenue Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 5, 'unit_price' => 40]],
        ])->assertCreated();

        $debtor = Person::create(['name' => 'OR Revenue Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 300);

        $profit = $this->getJson('/api/reports/profit?range=this_year')->assertOk()->json();

        $this->assertSame('200.00', $profit['revenue']['sales_revenue']);
        $this->assertSame('300.00', $profit['other_income']);
        // Gross profit is revenue(200) - COGS(5*30=150) only, never touched
        // by Other Income.
        $this->assertSame('50.00', $profit['gross_profit']);
        // Net profit = gross profit(50) + other income(300) - expenses(0).
        $this->assertSame('350.00', $profit['net_profit']);
    }

    // 11. Business Position reconciles after a partial payment.
    public function test_business_position_reconciles_after_partial_payment(): void
    {
        $debtor = Person::create(['name' => 'OR Partial Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 200);
        $this->debtPayment($debtor, 75);

        $position = $this->position();

        $this->assertSame('125.00', $position['assets']['other_receivables']);
        $this->assertTrue($position['check']['matches']);
    }

    // 12. Business Position reconciles after full payment.
    public function test_business_position_reconciles_after_full_payment(): void
    {
        $debtor = Person::create(['name' => 'OR Full Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 200);
        $this->debtPayment($debtor, 200);

        $position = $this->position();

        $this->assertSame('0.00', $position['assets']['other_receivables']);
        $this->assertTrue($position['check']['matches']);
    }

    // 8. Open-year live calculation.
    public function test_open_year_calculation_includes_other_receivables(): void
    {
        $debtor = Person::create(['name' => 'OR Live Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 80);

        $position = $this->position();
        $this->assertFalse($position['is_closed']);
        $this->assertSame('80.00', $position['assets']['other_receivables']);

        $this->debtCreated($debtor, 20);

        $updated = $this->position();
        $this->assertSame('100.00', $updated['assets']['other_receivables']);
    }

    // 9. Closed-year snapshot freezes Other Receivables.
    public function test_closed_year_snapshot_includes_other_receivables(): void
    {
        $debtor = Person::create(['name' => 'OR Closed Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 90, '2019-06-01');

        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)->postJson('/api/admin/financial-years/2019/close')->assertOk();

        $closed = $this->position('2019-01-01', '2019-12-31');
        $this->assertTrue($closed['is_closed']);
        $this->assertSame('90.00', $closed['assets']['other_receivables']);
        $this->assertSame('90.00', $closed['equity']['other_income']);
        $this->assertTrue($closed['check']['matches']);

        // Later activity must never change the frozen figures.
        $this->debtCreated($debtor, 500);

        $stillClosed = $this->position('2019-01-01', '2019-12-31');
        $this->assertSame('90.00', $stillClosed['assets']['other_receivables']);
        $this->assertSame('90.00', $stillClosed['equity']['other_income']);
    }

    // 10. Excel export includes Other Receivables.
    public function test_excel_export_includes_other_receivables(): void
    {
        $debtor = Person::create(['name' => 'OR Excel Debtor', 'is_active' => true, 'roles' => []]);
        $this->debtCreated($debtor, 65);

        $response = $this->get('/api/reports/business-position/excel');
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', strtolower($response->headers->get('content-type')));

        $report = app(ReportExportService::class)->businessPositionReport(['range' => 'this_year']);
        $otherReceivablesRow = collect($report['rows'])->first(fn (array $row) => $row[1] === 'Other Receivables');
        $otherIncomeRow = collect($report['rows'])->first(fn (array $row) => str_contains($row[1], 'Other Income'));

        $this->assertNotNull($otherReceivablesRow);
        $this->assertSame('65.00', $otherReceivablesRow[6]);
        $this->assertNotNull($otherIncomeRow);
        $this->assertSame('65.00', $otherIncomeRow[6]);
    }

    // 11. Existing loan/debt transaction workflows still work exactly as before.
    public function test_existing_loan_and_debt_transactions_still_work(): void
    {
        $balances = app(BalanceService::class);
        $person = Person::create(['name' => 'OR Regression Person', 'is_active' => true, 'roles' => []]);

        $this->postJson('/api/transactions', [
            'type' => 'loan_given', 'person_id' => $person->id, 'account_id' => $this->cash->id,
            'amount' => 100, 'description' => 'Loan given',
        ])->assertCreated();
        $this->assertSame('100.00', $balances->personBalance($person->fresh()));

        $this->postJson('/api/transactions', [
            'type' => 'loan_repayment', 'person_id' => $person->id, 'account_id' => $this->cash->id,
            'amount' => 40, 'description' => 'Loan repayment',
        ])->assertCreated();
        $this->assertSame('60.00', $balances->personBalance($person->fresh()));

        $this->debtCreated($person, 30);
        $this->assertSame('90.00', $balances->personBalance($person->fresh()));

        $this->debtPayment($person, 10);
        $this->assertSame('80.00', $balances->personBalance($person->fresh()));
    }
}

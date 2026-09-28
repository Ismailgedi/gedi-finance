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
 * Business Capital + Year-End Financial Position. Owners are Business
 * Contacts flagged is_owner=true (see 2026_09_25_000001_add_is_owner_to_people_table.php),
 * contributions/withdrawals are ordinary Transaction rows of type
 * owner_contribution/owner_withdrawal posted through the existing
 * POST /api/transactions endpoint, and GET /api/reports/business-position
 * (BusinessCapitalService) is the balance-sheet/year-end report:
 * Assets = Liabilities + Equity, with
 * Closing Equity = Opening Equity + Profit/Loss + Contributions - Withdrawals.
 */
class BusinessCapitalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Owner capital contributions/withdrawals are Super-Admin-only (see
        // TransactionService::assertAuthorizedForCapitalMovement) - this
        // file is about the accounting those movements produce, not about
        // that permission itself, so the acting user needs the role.
        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->cash = Account::create([
            'name' => 'Capital Test Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'Capital Bag', 'abbreviation' => 'cbag']);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Capital Workflow Rice',
            'sku' => 'CAP-WF-RICE',
            'default_cost_price' => 20,
            'default_selling_price' => 30,
            'is_active' => true,
        ]);
    }

    private function stockRice(float $quantity = 50, float $unitCost = 20): void
    {
        app(InventoryService::class)->record(
            $this->rice, $quantity, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', $unitCost
        );
    }

    private function makeOwner(string $name): Person
    {
        return Person::create([
            'name' => $name,
            'is_active' => true,
            'is_owner' => true,
            'roles' => ['owner'],
        ]);
    }

    private function contribute(Person $owner, float $amount, ?string $date = null): void
    {
        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution',
            'person_id' => $owner->id,
            'account_id' => $this->cash->id,
            'amount' => $amount,
            'description' => "Capital contribution from {$owner->name}",
            'transaction_date' => $date,
        ])->assertCreated();
    }

    private function withdraw(Person $owner, float $amount, ?string $date = null): void
    {
        $this->postJson('/api/transactions', [
            'type' => 'owner_withdrawal',
            'person_id' => $owner->id,
            'account_id' => $this->cash->id,
            'amount' => $amount,
            'description' => "Owner drawing for {$owner->name}",
            'transaction_date' => $date,
        ])->assertCreated();
    }

    private function position(?string $start = null, ?string $end = null): array
    {
        $query = array_filter(['from' => $start, 'to' => $end]);

        return $this->getJson('/api/reports/business-position?' . http_build_query($query))
            ->assertOk()
            ->json();
    }

    // 1. Initial owner capital contribution.
    public function test_single_owner_initial_capital_contribution(): void
    {
        $aisha = $this->makeOwner('Aisha');
        $this->contribute($aisha, 5000);

        $position = $this->position();

        $this->assertSame('5000.00', $position['equity']['owner_contributions']);
        $this->assertSame('5000.00', $position['equity']['closing_equity']);
        $this->assertSame('5000.00', $position['assets']['cash_and_bank']);
        $this->assertTrue($position['check']['matches']);
    }

    // 2. Multiple owners.
    public function test_multiple_owners_capital_contributions(): void
    {
        $owners = collect(['Aisha', 'Faarax', 'Hodan', 'Yusuf'])->map(fn ($name) => $this->makeOwner($name));

        foreach ($owners as $owner) {
            $this->contribute($owner, 5000);
        }

        $position = $this->position();

        $this->assertSame('20000.00', $position['equity']['owner_contributions']);
        $this->assertSame('20000.00', $position['equity']['closing_equity']);

        $this->assertSame(4, DB::table('transactions')->where('type', 'owner_contribution')->count());
        $this->assertSame(4, DB::table('transactions')->where('type', 'owner_contribution')->distinct()->count('person_id'));
    }

    // 3. Additional capital contribution.
    public function test_additional_capital_contribution_increases_equity(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000);
        $this->contribute($owner, 2000);

        $position = $this->position();

        $this->assertSame('7000.00', $position['equity']['owner_contributions']);
        $this->assertSame('7000.00', $position['equity']['closing_equity']);
    }

    // 4. Owner withdrawal.
    public function test_owner_withdrawal_reduces_account_and_equity(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000);
        $this->withdraw($owner, 1200);

        $position = $this->position();

        $this->assertSame('1200.00', $position['equity']['owner_withdrawals']);
        $this->assertSame('3800.00', $position['equity']['closing_equity']);
        $this->assertSame('3800.00', $position['assets']['cash_and_bank']);
    }

    // 5. Contribution is not counted as revenue.
    public function test_owner_contribution_is_not_counted_as_revenue(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000);
        $this->stockRice();

        $this->postJson('/api/sales', [
            'amount_paid' => 150,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 30]],
        ])->assertCreated();

        $profit = $this->getJson('/api/reports/profit?range=this_month')->assertOk()->json();

        $this->assertSame('150.00', $profit['revenue']['sales_revenue']);
    }

    // 6. Withdrawal is not counted as ordinary operating expense.
    public function test_owner_withdrawal_is_not_counted_as_operating_expense(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000);
        $this->withdraw($owner, 1200);

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 80,
            'description' => 'Rent',
        ])->assertCreated();

        $profit = $this->getJson('/api/reports/profit?range=this_month')->assertOk()->json();

        $this->assertSame('80.00', $profit['operating_expenses']['total']);
    }

    // 7. Opening equity (with no prior history).
    public function test_opening_equity_is_zero_with_no_prior_history(): void
    {
        $position = $this->position('2026-01-01', '2026-12-31');

        $this->assertSame('0.00', $position['equity']['opening_equity']);
        $this->assertSame('0.00', $position['equity']['closing_equity']);
    }

    // 8. Closing equity formula.
    public function test_closing_equity_matches_the_formula(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000);
        $this->withdraw($owner, 800);
        $this->stockRice();

        $this->postJson('/api/sales', [
            'amount_paid' => 150,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 30]],
        ])->assertCreated();

        $position = $this->position();

        $opening = (float) $position['equity']['opening_equity'];
        $profit = (float) $position['equity']['profit_or_loss'];
        $contributions = (float) $position['equity']['owner_contributions'];
        $withdrawals = (float) $position['equity']['owner_withdrawals'];
        $closing = (float) $position['equity']['closing_equity'];

        $this->assertEqualsWithDelta($opening + $profit + $contributions - $withdrawals, $closing, 0.01);
    }

    // 9. Previous year's closing equity becomes next year's opening equity.
    public function test_previous_years_closing_equity_becomes_next_years_opening_equity(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 10000, '2025-03-01');
        $this->withdraw($owner, 1000, '2025-11-01');

        $year1 = $this->position('2025-01-01', '2025-12-31');
        $year2 = $this->position('2026-01-01', '2026-12-31');

        $this->assertSame($year1['equity']['closing_equity'], $year2['equity']['opening_equity']);
        $this->assertSame('9000.00', $year1['equity']['closing_equity']);
    }

    // 10. Profit calculation with no contributions/withdrawals.
    public function test_profit_calculation_with_no_contributions_or_withdrawals(): void
    {
        $this->stockRice();

        $this->postJson('/api/sales', [
            'amount_paid' => 150,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 30]],
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 40,
            'description' => 'Transport',
        ])->assertCreated();

        $position = $this->position();

        // Gross profit 150 - 100 cogs = 50; net = 50 - 40 expense = 10.
        $this->assertSame('0.00', $position['equity']['owner_contributions']);
        $this->assertSame('0.00', $position['equity']['owner_withdrawals']);
        $this->assertSame('10.00', $position['equity']['profit_or_loss']);
        $this->assertSame('10.00', $position['equity']['closing_equity']);
    }

    // 11. Profit calculation with additional contributions.
    public function test_profit_calculation_with_additional_contributions(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 3000);
        $this->stockRice();

        $this->postJson('/api/sales', [
            'amount_paid' => 150,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 30]],
        ])->assertCreated();

        $position = $this->position();

        // Gross profit 150 - 100 cogs = 50, no expenses -> net 50.
        $this->assertSame('50.00', $position['equity']['profit_or_loss']);
        $this->assertSame('3050.00', $position['equity']['closing_equity']);
    }

    // 12. Profit calculation with withdrawals.
    public function test_profit_calculation_with_withdrawals(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 3000);
        $this->withdraw($owner, 500);
        $this->stockRice();

        $this->postJson('/api/sales', [
            'amount_paid' => 150,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 30]],
        ])->assertCreated();

        $position = $this->position();

        // opening 0 + profit 50 + contributions 3000 - withdrawals 500 = 2550.
        $this->assertSame('50.00', $position['equity']['profit_or_loss']);
        $this->assertSame('2550.00', $position['equity']['closing_equity']);
    }

    // 13. Assets - liabilities = closing equity, across a fully mixed scenario.
    public function test_assets_minus_liabilities_equals_closing_equity(): void
    {
        $owner = $this->makeOwner('Aisha');
        $lender = Person::create(['name' => 'Lender Co', 'is_active' => true, 'roles' => []]);
        $customer = Person::create(['name' => 'Credit Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $supplier = Supplier::create(['supplier_code' => 'CAP-SUP', 'name' => 'Capital Supplier', 'is_active' => true]);

        $this->contribute($owner, 5000);

        // Credit purchase (payable) - restocks first so the sales below have stock.
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 50, 'unit_cost' => 20]],
        ])->assertCreated();

        // Cash sale.
        $this->postJson('/api/sales', [
            'amount_paid' => 150,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 30]],
        ])->assertCreated();

        // Credit sale (receivable).
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 30]],
        ])->assertCreated();

        // Loan given (asset) and loan received (liability).
        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $lender->id,
            'account_id' => $this->cash->id,
            'amount' => 300,
            'description' => 'Loan to a business partner',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'loan_received',
            'person_id' => $lender->id,
            'account_id' => $this->cash->id,
            'amount' => 200,
            'description' => 'Short-term loan from a supplier',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 25,
            'description' => 'Utilities',
        ])->assertCreated();

        $position = $this->position();

        $this->assertTrue(
            $position['check']['matches'],
            "Assets-liabilities ({$position['check']['assets_minus_liabilities']}) must equal closing equity ({$position['check']['closing_equity']})."
        );
    }

    // 14. Inventory is included at cost.
    public function test_inventory_is_included_at_cost(): void
    {
        app(InventoryService::class)->record(
            $this->rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 20
        );

        $position = $this->position();

        $this->assertSame('2000.00', $position['assets']['inventory_at_cost']);
    }

    // 15. Customer receivables are included.
    public function test_customer_receivables_are_included_in_assets(): void
    {
        app(InventoryService::class)->record(
            $this->rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 20
        );

        $customer = Person::create(['name' => 'Receivable Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 30]],
        ])->assertCreated();

        $position = $this->position();

        $this->assertSame('120.00', $position['assets']['customer_receivables']);
    }

    // 16. Supplier payables are included.
    public function test_supplier_payables_are_included_in_liabilities(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'CAP-SUP-2', 'name' => 'Payable Supplier', 'is_active' => true]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated();

        $position = $this->position();

        $this->assertSame('200.00', $position['liabilities']['supplier_payables']);
    }

    // 17. Loans affect the position correctly.
    public function test_loans_affect_the_position_correctly(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 1000);

        $borrower = Person::create(['name' => 'Loan Recipient', 'is_active' => true, 'roles' => []]);
        $lenderBank = Person::create(['name' => 'Bank Lender', 'is_active' => true, 'roles' => []]);

        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $borrower->id,
            'account_id' => $this->cash->id,
            'amount' => 300,
            'description' => 'Loan given to a partner',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'loan_received',
            'person_id' => $lenderBank->id,
            'account_id' => $this->cash->id,
            'amount' => 500,
            'description' => 'Loan received from the bank',
        ])->assertCreated();

        $position = $this->position();

        $this->assertSame('300.00', $position['assets']['loans_given']);
        $this->assertSame('500.00', $position['liabilities']['loans_received']);
        // Cash: opening 0 + 1000 contribution - 300 lent out + 500 borrowed = 1200.
        $this->assertSame('1200.00', $position['assets']['cash_and_bank']);
        $this->assertTrue($position['check']['matches']);
    }

    // 18. Existing sales/purchases/payments/loans still work.
    public function test_existing_sale_purchase_payment_and_loan_workflows_still_work(): void
    {
        $balances = app(BalanceService::class);

        $customer = Person::create(['name' => 'Regression Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $supplier = Supplier::create(['supplier_code' => 'CAP-SUP-3', 'name' => 'Regression Supplier', 'is_active' => true]);

        $this->stockRice();

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 30]],
        ])->assertCreated()->json('sale');

        $this->assertSame('120.00', $balances->customerReceivableBalance($customer->fresh()));

        $this->postJson("/api/sales/{$sale['id']}/payments", [
            'amount' => 120,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('0.00', $balances->customerReceivableBalance($customer->fresh()));

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('200.00', $balances->supplierBalance($supplier->fresh()));

        $this->postJson("/api/purchases/{$purchase['id']}/payments", [
            'amount' => 200,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('0.00', $balances->supplierBalance($supplier->fresh()));

        $loanPerson = Person::create(['name' => 'Loan Regression Person', 'is_active' => true, 'roles' => []]);

        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $loanPerson->id,
            'account_id' => $this->cash->id,
            'amount' => 150,
            'description' => 'Regression loan',
        ])->assertCreated();

        $this->assertSame('150.00', $balances->personBalance($loanPerson->fresh()));
    }

    // --- C6 (accounting audit): Business Position must never net one
    // customer's/supplier's credit against another's receivable/payable -
    // each bucket is calculated per entity, then summed. ---

    public function test_customer_receivables_and_credits_are_never_netted_across_different_customers(): void
    {
        app(InventoryService::class)->record($this->rice, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 20);

        // Customer A: owes $1,000 (an unpaid credit sale).
        $customerA = Person::create(['name' => 'C6 Customer A', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customerA->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 40, 'unit_price' => 25]],
        ])->assertCreated();
        // total = 40 * 25 = 1000, unpaid -> receivable of 1000.

        // Customer B: has a $300 credit (fully paid, then a full return
        // settled as credit).
        $customerB = Person::create(['name' => 'C6 Customer B', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $saleB = $this->postJson('/api/sales', [
            'customer_id' => $customerB->id,
            'amount_paid' => 300,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 12, 'unit_price' => 25]],
        ])->assertCreated()->json('sale');
        // total = 12 * 25 = 300, fully paid.

        $this->postJson('/api/sale-returns', [
            'sale_id' => $saleB['id'],
            'reason' => 'Customer changed their mind',
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $saleB['items'][0]['id'], 'quantity' => 12, 'is_saleable' => true]],
        ])->assertCreated();
        // Full return of a fully-paid sale settled as credit -> -300
        // receivable = a $300 customer credit.

        $position = $this->position();

        // Expected: each customer's own balance is calculated separately,
        // then summed into its own bucket - never netted together.
        $this->assertSame('1000.00', $position['assets']['customer_receivables']);
        $this->assertSame('300.00', $position['liabilities']['customer_credits']);

        // NOT the old, buggy netted result (1000 - 300 = 700 receivable,
        // 0 credit - which would silently hide Customer B's real credit).
        $this->assertNotSame('700.00', $position['assets']['customer_receivables']);
        $this->assertNotSame('0.00', $position['liabilities']['customer_credits']);
    }

    public function test_supplier_payables_and_credits_are_never_netted_across_different_suppliers(): void
    {
        // Supplier X: owed $1,000 (an unpaid purchase).
        $supplierX = Supplier::create(['supplier_code' => 'C6-SUP-X', 'name' => 'C6 Supplier X', 'is_active' => true]);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplierX->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 50, 'unit_cost' => 20]],
        ])->assertCreated();
        // total = 50 * 20 = 1000, unpaid -> payable of 1000.

        // Supplier Y: has a $300 credit (fully paid, then a full return
        // settled as credit).
        $supplierY = Supplier::create(['supplier_code' => 'C6-SUP-Y', 'name' => 'C6 Supplier Y', 'is_active' => true]);
        $purchaseY = $this->postJson('/api/purchases', [
            'supplier_id' => $supplierY->id,
            'amount_paid' => 300,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 15, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase');
        // total = 15 * 20 = 300, fully paid.

        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchaseY['id'],
            'reason' => 'Entire order rejected',
            'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $purchaseY['items'][0]['id'], 'quantity' => 15]],
        ])->assertCreated();
        // Full return of a fully-paid purchase settled as credit -> -300
        // payable = a $300 supplier credit.

        $position = $this->position();

        $this->assertSame('1000.00', $position['liabilities']['supplier_payables']);
        $this->assertSame('300.00', $position['assets']['supplier_credits']);

        // NOT the old, buggy netted result.
        $this->assertNotSame('700.00', $position['liabilities']['supplier_payables']);
        $this->assertNotSame('0.00', $position['assets']['supplier_credits']);
    }
}

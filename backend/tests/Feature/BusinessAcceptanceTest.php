<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\BusinessReportService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Final business acceptance test: walks the exact wholesale scenarios a
 * real Gedi Finance user would run (buy stock, sell for cash, sell on
 * credit, collect payment, pay a supplier, transfer money between
 * accounts, lend and collect a loan) through the real HTTP endpoints, and
 * asserts every financial effect - inventory, account balances, customer/
 * supplier balances, COGS/gross profit, and the Dashboard/report figures
 * derived from them - lands exactly once and exactly right. Every balance
 * assertion is checked against the real BalanceService, never a number
 * re-derived by the test itself, so this proves the actual business logic,
 * not just that an endpoint returns 2xx.
 */
class BusinessAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->balances = app(BalanceService::class);
        $this->inventory = app(InventoryService::class);
    }

    private function bagUnit(): Unit
    {
        return Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
    }

    /**
     * TEST 1 (A + B): buying goods, paid immediately vs. on supplier
     * credit, each producing their financial effect exactly once.
     */
    public function test_purchase_paid_immediately_and_purchase_on_supplier_credit(): void
    {
        $bag = $this->bagUnit();

        // --- A: paid immediately ---
        $riceA = Product::create([
            'base_unit_id' => $bag->id, 'name' => 'Acceptance Rice 25kg A', 'sku' => 'ACC-RICE-A',
            'default_cost_price' => 20, 'default_selling_price' => 27, 'is_active' => true,
        ]);
        $supplierA = Supplier::create(['supplier_code' => 'ACC-SUP-A', 'name' => 'Test Supplier A', 'is_active' => true]);
        $cash = Account::create(['name' => 'Acceptance Cash', 'type' => 'cash', 'opening_balance' => 5000, 'currency' => 'USD', 'is_active' => true]);
        $cashBefore = (float) $this->balances->accountBalance($cash);

        $purchaseA = $this->postJson('/api/purchases', [
            'supplier_id' => $supplierA->id,
            'amount_paid' => 2000,
            'account_id' => $cash->id,
            'items' => [['product_id' => $riceA->id, 'quantity' => 100, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('2000.00', $purchaseA['total']);
        $this->assertSame('paid', $purchaseA['payment_status']);
        $this->assertSame('0.00', $purchaseA['balance_due']);
        $this->assertSame('100.0000', $this->inventory->currentStock($riceA), 'Stock must increase by exactly 100 bags.');
        $this->assertSame('2000.00', $this->inventory->inventoryValue($riceA));
        $this->assertSame('20.0000', $this->inventory->weightedAverageCost($riceA));
        $this->assertSame(1, DB::table('inventory_movements')->where('product_id', $riceA->id)->count(), 'Exactly one stock movement, no duplicates.');
        $this->assertSame('0.00', $this->balances->supplierBalance($supplierA->fresh()), 'Fully paid: nothing owed.');
        $this->assertSame(
            number_format($cashBefore - 2000, 2, '.', ''),
            $this->balances->accountBalance($cash->fresh()),
            'Cash must decrease by exactly the amount paid, exactly once.',
        );
        $this->assertSame(1, DB::table('transactions')->where('type', 'purchase')->where('purchase_id', $purchaseA['id'])->count());
        $this->assertSame(1, DB::table('transactions')->where('type', 'supplier_payment')->where('purchase_id', $purchaseA['id'])->count());

        $report = app(BusinessReportService::class)->purchases([]);
        $this->assertSame(1, count($report['data']));
        $this->assertSame('2000.00', $report['summary']['total_purchases']);

        // --- B: on supplier credit ---
        $riceB = Product::create([
            'base_unit_id' => $bag->id, 'name' => 'Acceptance Rice 25kg B', 'sku' => 'ACC-RICE-B',
            'default_cost_price' => 20, 'default_selling_price' => 27, 'is_active' => true,
        ]);
        $supplierB = Supplier::create(['supplier_code' => 'ACC-SUP-B', 'name' => 'Test Supplier B', 'is_active' => true]);
        $cashBeforeB = (float) $this->balances->accountBalance($cash->fresh());

        $purchaseB = $this->postJson('/api/purchases', [
            'supplier_id' => $supplierB->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $riceB->id, 'quantity' => 100, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('unpaid', $purchaseB['payment_status']);
        $this->assertSame('2000.00', $purchaseB['balance_due']);
        $this->assertSame('100.0000', $this->inventory->currentStock($riceB));
        $this->assertSame('2000.00', $this->balances->supplierBalance($supplierB->fresh()));
        $this->assertSame(
            number_format($cashBeforeB, 2, '.', ''),
            $this->balances->accountBalance($cash->fresh()),
            'No account is touched by a fully unpaid credit purchase.',
        );

        // Dashboard must show this payable without conflating it with A.
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame('2000.00', $dashboard['payables']['outstanding']);
    }

    /**
     * TEST 2, 3, 4, 5, 7 (partial), 8, 12: the full customer lifecycle -
     * stock a product, sell some for cash, sell more on credit, collect a
     * partial payment, then collect the rest - checking inventory, COGS,
     * gross profit, the customer's real balance and the Dashboard at every
     * step.
     */
    public function test_full_customer_lifecycle_cash_sale_credit_sale_and_payments(): void
    {
        $bag = $this->bagUnit();
        $rice = Product::create([
            'base_unit_id' => $bag->id, 'name' => 'Acceptance Lifecycle Rice', 'sku' => 'ACC-LIFECYCLE-RICE',
            'default_cost_price' => 20, 'default_selling_price' => 27, 'is_active' => true,
        ]);
        $supplier = Supplier::create(['supplier_code' => 'ACC-LC-SUP', 'name' => 'Lifecycle Supplier', 'is_active' => true]);
        $customer = Person::create(['name' => 'Test Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $cash = Account::create(['name' => 'Lifecycle Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        // Stock 100 bags at exactly $20/bag so weighted-average cost is
        // predictable through the rest of the test.
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 2000,
            'account_id' => $cash->id,
            'items' => [['product_id' => $rice->id, 'quantity' => 100, 'unit_cost' => 20]],
        ])->assertCreated();
        $this->assertSame('100.0000', $this->inventory->currentStock($rice));

        // --- TEST 2: cash sale, 20 bags @ $27 = $540 ---
        $cashAfterPurchase = (float) $this->balances->accountBalance($cash->fresh());
        $cashSale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 540,
            'account_id' => $cash->id,
            'items' => [['product_id' => $rice->id, 'quantity' => 20, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->assertSame('540.00', $cashSale['total']);
        $this->assertSame('paid', $cashSale['payment_status']);
        $this->assertSame('80.0000', $this->inventory->currentStock($rice), '100 - 20 = 80.');
        $this->assertSame('400.00', $cashSale['cost_of_goods_sold'], '20 bags * $20 weighted-average cost.');
        $this->assertSame('140.00', $cashSale['gross_profit'], '540 - 400.');
        $this->assertSame(
            number_format($cashAfterPurchase + 540, 2, '.', ''),
            $this->balances->accountBalance($cash->fresh()),
        );
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()), 'A cash sale must not create a receivable.');

        // --- TEST 3: credit sale, 15 bags @ $27 = $405, $0 paid ---
        $cashAfterCashSale = (float) $this->balances->accountBalance($cash->fresh());
        $creditSale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 15, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->assertSame('405.00', $creditSale['total']);
        $this->assertSame('0.00', $creditSale['amount_paid']);
        $this->assertSame('405.00', $creditSale['balance_due']);
        $this->assertSame('unpaid', $creditSale['payment_status']);
        $this->assertSame('65.0000', $this->inventory->currentStock($rice), '80 - 15 = 65.');
        $this->assertSame('300.00', $creditSale['cost_of_goods_sold'], '15 bags * $20.');
        $this->assertSame('105.00', $creditSale['gross_profit']);
        $this->assertSame(
            number_format($cashAfterCashSale, 2, '.', ''),
            $this->balances->accountBalance($cash->fresh()),
            'No account may move for an unpaid credit sale.',
        );
        $this->assertSame('405.00', $this->balances->customerReceivableBalance($customer->fresh()));

        // Customer page must show the REAL backend balance, not a frontend guess.
        $personResponse = $this->getJson("/api/people/{$customer->id}")->assertOk()->json();
        $this->assertSame($this->balances->personBalance($customer->fresh()), $personResponse['balance']);

        // --- TEST 4: partial payment of $200 ---
        $cashBeforePartial = (float) $this->balances->accountBalance($cash->fresh());
        $partial = $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 200,
            'account_id' => $cash->id,
        ])->assertOk()->json();

        $this->assertSame('200.00', $partial['applied']);
        $this->assertSame('205.00', $this->balances->customerReceivableBalance($customer->fresh()), '405 - 200 = 205.');
        $this->assertSame(number_format($cashBeforePartial + 200, 2, '.', ''), $this->balances->accountBalance($cash->fresh()));

        $creditSaleAfterPartial = $this->getJson("/api/sales/{$creditSale['id']}")->assertOk()->json();
        $this->assertSame('205.00', $creditSaleAfterPartial['balance_due']);
        $this->assertSame('partial', $creditSaleAfterPartial['payment_status']);
        // The original sale's own figures must be untouched by the payment.
        $this->assertSame('405.00', $creditSaleAfterPartial['total']);
        $this->assertSame('300.00', $creditSaleAfterPartial['cost_of_goods_sold']);
        $this->assertSame('105.00', $creditSaleAfterPartial['gross_profit']);

        // --- TEST 5: pay the remaining $205 in full ---
        $cashBeforeFull = (float) $this->balances->accountBalance($cash->fresh());
        $full = $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 205,
            'account_id' => $cash->id,
        ])->assertOk()->json();

        $this->assertSame('205.00', $full['applied']);
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()));
        $this->assertSame(number_format($cashBeforeFull + 205, 2, '.', ''), $this->balances->accountBalance($cash->fresh()));

        $creditSaleFinal = $this->getJson("/api/sales/{$creditSale['id']}")->assertOk()->json();
        $this->assertSame('0.00', $creditSaleFinal['balance_due']);
        $this->assertSame('paid', $creditSaleFinal['payment_status']);

        // Reports must not still show this customer as owing money.
        $receivables = $this->getJson('/api/reports/customer-receivables')->assertOk()->json();
        $names = collect($receivables['data'])->pluck('name');
        $this->assertNotContains('Test Customer', $names, 'A fully paid customer must not appear in the receivables list.');

        // --- TEST 12: Dashboard reflects the whole lifecycle correctly ---
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame('0.00', $dashboard['receivables']['customer_outstanding']);
        $this->assertSame('945.00', $dashboard['today']['sales_total'], '540 + 405 sold today.');

        $salesReport = app(BusinessReportService::class)->sales([]);
        $this->assertSame('945.00', $salesReport['summary']['total_sales']);
        $this->assertSame('945.00', $salesReport['summary']['amount_collected'], 'Everything has now been collected.');
        $this->assertSame('0.00', $salesReport['summary']['outstanding']);
    }

    /**
     * TEST 6, 9: an existing supplier payable, partially paid down, with
     * the supplier statement and payables report checked against the real
     * balance afterward.
     */
    public function test_supplier_payable_and_partial_supplier_payment(): void
    {
        $bag = $this->bagUnit();
        $rice = Product::create([
            'base_unit_id' => $bag->id, 'name' => 'Acceptance Payable Rice', 'sku' => 'ACC-PAYABLE-RICE',
            'default_cost_price' => 20, 'default_selling_price' => 27, 'is_active' => true,
        ]);
        $supplier = Supplier::create(['supplier_code' => 'ACC-PAY-SUP', 'name' => 'Payable Test Supplier', 'is_active' => true]);
        $cash = Account::create(['name' => 'Payable Cash', 'type' => 'cash', 'opening_balance' => 5000, 'currency' => 'USD', 'is_active' => true]);

        // We owe the supplier $2,000 (100 bags @ $20, fully on credit).
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 100, 'unit_cost' => 20]],
        ])->assertCreated();
        $this->assertSame('2000.00', $this->balances->supplierBalance($supplier->fresh()));

        $cashBefore = (float) $this->balances->accountBalance($cash->fresh());
        $payment = $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 500,
            'account_id' => $cash->id,
        ])->assertOk()->json();

        $this->assertSame('500.00', $payment['applied']);
        $this->assertSame('1500.00', $this->balances->supplierBalance($supplier->fresh()), '2000 - 500 = 1500.');
        $this->assertSame(number_format($cashBefore - 500, 2, '.', ''), $this->balances->accountBalance($cash->fresh()));
        $this->assertSame(1, DB::table('transactions')->where('type', 'supplier_payment')->where('supplier_id', $supplier->id)->count());

        // Supplier page shows the real backend balance.
        $supplierResponse = $this->getJson("/api/suppliers/{$supplier->id}")->assertOk()->json();
        $this->assertSame($this->balances->supplierBalance($supplier->fresh()), $supplierResponse['balance']);
        $this->assertSame('1500.00', $supplierResponse['balance']);

        $payables = $this->getJson('/api/reports/supplier-payables')->assertOk()->json();
        $row = collect($payables['data'])->firstWhere('name', 'Payable Test Supplier');
        $this->assertNotNull($row);
        $this->assertSame('1500.00', $row['balance']);
    }

    /**
     * TEST 10: a Bank -> Cash transfer must move money between the two
     * accounts, leave total business money unchanged, and never be counted
     * as income or expense.
     */
    public function test_account_transfer_moves_money_without_being_income_or_expense(): void
    {
        $bank = Account::create(['name' => 'Acceptance Bank', 'type' => 'bank', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => true]);
        $cash = Account::create(['name' => 'Acceptance Transfer Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        $this->postJson('/api/transactions', [
            'type' => 'account_transfer',
            'account_id' => $bank->id,
            'destination_account_id' => $cash->id,
            'amount' => 200,
            'currency' => 'USD',
            'description' => 'Bank to cash transfer',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('800.00', $this->balances->accountBalance($bank->fresh()));
        $this->assertSame('200.00', $this->balances->accountBalance($cash->fresh()));

        $summary = app(BusinessReportService::class)->summary('today', null, null);
        $this->assertSame('0.00', $summary['financial']['total_sales'], 'A transfer must never be counted as a sale.');
        $this->assertSame('0.00', $summary['financial']['total_expenses'], 'A transfer must never be counted as an expense.');
        $this->assertSame('1000.00', $summary['financial']['accounts_balance'], 'Total business money is unchanged by an internal transfer.');
    }

    /**
     * TEST 11: giving a loan and collecting a repayment must move the
     * correct account money and track an "outstanding loan" balance
     * completely separately from ordinary sales/receivables.
     */
    public function test_loan_given_and_partially_repaid_is_never_counted_as_sales_revenue(): void
    {
        $borrower = Person::create(['name' => 'Test Loan Borrower', 'is_active' => true, 'is_customer' => false, 'roles' => ['other']]);
        $cash = Account::create(['name' => 'Loan Cash', 'type' => 'cash', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => true]);

        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $borrower->id,
            'account_id' => $cash->id,
            'amount' => 500,
            'currency' => 'USD',
            'description' => 'Loan to borrower',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('500.00', $this->balances->accountBalance($cash->fresh()), '1000 - 500 = 500.');
        $this->assertSame(500.0, $this->loanOutstanding($borrower));

        $this->postJson('/api/transactions', [
            'type' => 'loan_repayment',
            'person_id' => $borrower->id,
            'account_id' => $cash->id,
            'amount' => 200,
            'currency' => 'USD',
            'description' => 'Loan repayment',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('700.00', $this->balances->accountBalance($cash->fresh()), '500 + 200 = 700.');
        $this->assertSame(300.0, $this->loanOutstanding($borrower), '500 - 200 = 300.');

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame('300.00', $dashboard['loans']['outstanding_given']);

        // A loan is not a sale and must not leak into customer receivables
        // or sales revenue.
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($borrower->fresh()));
        $summary = app(BusinessReportService::class)->summary('today', null, null);
        $this->assertSame('0.00', $summary['financial']['total_sales']);
    }

    private function loanOutstanding(Person $person): float
    {
        $net = DB::table('transactions')
            ->where('person_id', $person->id)
            ->where('status', 'posted')
            ->whereIn('type', ['loan_given', 'loan_repayment'])
            ->selectRaw("SUM(CASE WHEN type = 'loan_given' THEN amount ELSE -amount END) AS net")
            ->value('net');

        return round((float) $net, 2);
    }

    /**
     * TEST 22: a credit sale that would push a customer over their credit
     * limit must be rejected cleanly, with zero effect - not silently
     * capped or partially applied.
     */
    public function test_a_credit_sale_exceeding_the_customers_credit_limit_is_rejected(): void
    {
        $bag = $this->bagUnit();
        $rice = Product::create([
            'base_unit_id' => $bag->id, 'name' => 'Acceptance Credit Limit Rice', 'sku' => 'ACC-CL-RICE',
            'default_cost_price' => 20, 'default_selling_price' => 27, 'is_active' => true,
        ]);
        $this->inventory->record($rice, 200, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 20);

        $customer = Person::create([
            'name' => 'Credit Limit Customer', 'is_active' => true, 'is_customer' => true,
            'roles' => ['customer'], 'credit_limit' => 1000,
        ]);

        // First credit sale of $700 is within the $1,000 limit.
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 25, 'unit_price' => 28]],
        ])->assertCreated();
        $this->assertSame('700.00', $this->balances->customerReceivableBalance($customer->fresh()));

        // A second credit sale of $400 would push the balance to $1,100 -
        // over the $1,000 limit - and must be rejected outright.
        $stockBefore = $this->inventory->currentStock($rice);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 20, 'unit_price' => 20]],
        ])->assertStatus(422);

        $this->assertSame('700.00', $this->balances->customerReceivableBalance($customer->fresh()), 'Rejected sale must not change the balance.');
        $this->assertSame($stockBefore, $this->inventory->currentStock($rice), 'Rejected sale must not touch inventory.');
    }

    /**
     * TEST 22: a purchase that would push a supplier payable over the
     * supplier's credit limit must likewise be rejected cleanly.
     */
    public function test_a_credit_purchase_exceeding_the_suppliers_credit_limit_is_rejected(): void
    {
        $bag = $this->bagUnit();
        $rice = Product::create([
            'base_unit_id' => $bag->id, 'name' => 'Acceptance Supplier Limit Rice', 'sku' => 'ACC-SL-RICE',
            'default_cost_price' => 20, 'default_selling_price' => 27, 'is_active' => true,
        ]);
        $supplier = Supplier::create([
            'supplier_code' => 'ACC-SL-SUP', 'name' => 'Credit Limit Supplier', 'is_active' => true, 'credit_limit' => 1000,
        ]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 35, 'unit_cost' => 20]],
        ])->assertCreated();
        $this->assertSame('700.00', $this->balances->supplierBalance($supplier->fresh()));

        $stockBefore = $this->inventory->currentStock($rice);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 20, 'unit_cost' => 20]],
        ])->assertStatus(422);

        $this->assertSame('700.00', $this->balances->supplierBalance($supplier->fresh()));
        $this->assertSame($stockBefore, $this->inventory->currentStock($rice), 'Rejected purchase must not touch inventory.');
    }
}

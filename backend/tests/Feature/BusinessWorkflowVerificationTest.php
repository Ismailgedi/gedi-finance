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
use Tests\TestCase;

/**
 * Runs the full wholesale business cycle as ONE continuous story - the same
 * order a real day of trading would happen in - and checks the actual
 * database/service state after every step, not just that an HTTP call
 * returned 201. This is the end-to-end proof that Gedi Finance's core
 * workflows (purchase -> inventory -> sale -> receivable -> payment ->
 * supplier payable -> loan -> transfer) all post the correct, non-duplicated
 * financial effects, requested as a full business-workflow verification
 * pass rather than a UI smoke test.
 */
class BusinessWorkflowVerificationTest extends TestCase
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

    public function test_full_wholesale_business_cycle_produces_correct_balances_at_every_step(): void
    {
        // ---- Setup: bag-based product, two accounts, a customer, two suppliers, a borrower ----
        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Rice 25kg',
            'sku' => 'WF-RICE-25',
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);

        $cash = Account::create(['name' => 'WF Cash', 'type' => 'cash', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => true]);
        $bank = Account::create(['name' => 'WF Bank', 'type' => 'bank', 'opening_balance' => 500, 'currency' => 'USD', 'is_active' => true]);

        $supplierA = Supplier::create(['supplier_code' => 'WF-SUP-A', 'name' => 'Workflow Supplier A', 'is_active' => true]);
        $supplierB = Supplier::create(['supplier_code' => 'WF-SUP-B', 'name' => 'Workflow Supplier B', 'is_active' => true]);
        $customer = Person::create(['name' => 'Workflow Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $borrower = Person::create(['name' => 'Workflow Borrower', 'is_active' => true, 'roles' => ['borrower']]);

        // ======================================================================
        // 1-2. Purchase goods from a supplier (cash, fully paid) -> inventory up
        // ======================================================================
        $purchase1 = $this->postJson('/api/purchases', [
            'supplier_id' => $supplierA->id,
            'amount_paid' => 900,
            'account_id' => $cash->id,
            'items' => [['product_id' => $rice->id, 'quantity' => 50, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('900.00', $purchase1['total']);
        $this->assertSame('paid', $purchase1['payment_status']);
        $this->assertSame('50.0000', $this->inventory->currentStock($rice));
        $this->assertSame('900.00', $this->inventory->inventoryValue($rice));
        $this->assertSame('100.00', $this->balances->accountBalance($cash->fresh()), '1000 opening - 900 paid.');

        // A second purchase at a different unit cost, to genuinely exercise
        // weighted-average blending rather than a single flat cost.
        $purchase2 = $this->postJson('/api/purchases', [
            'supplier_id' => $supplierA->id,
            'amount_paid' => 1100,
            'account_id' => $cash->id,
            'items' => [['product_id' => $rice->id, 'quantity' => 50, 'unit_cost' => 22]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('1100.00', $purchase2['total']);
        $this->assertSame('100.0000', $this->inventory->currentStock($rice), 'Stock must accumulate across purchases (100 bags total).');
        $this->assertSame('2000.00', $this->inventory->inventoryValue($rice), '(50*18)+(50*22) = 2000');
        $this->assertSame('20.0000', $this->inventory->weightedAverageCost($rice), 'Blended cost across both purchases must be exactly $20/bag.');

        $cashAfterPurchases = 1000 - 900 - 1100;
        $this->assertSame(number_format($cashAfterPurchases, 2, '.', ''), $this->balances->accountBalance($cash->fresh()));

        // ======================================================================
        // 3-5. Cash sale -> inventory down, cash/account balance up
        // ======================================================================
        $cashBeforeSale1 = (float) $this->balances->accountBalance($cash->fresh());

        $sale1 = $this->postJson('/api/sales', [
            'amount_paid' => 540,
            'account_id' => $cash->id,
            'items' => [['product_id' => $rice->id, 'quantity' => 20, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->assertSame('540.00', $sale1['total']);
        $this->assertSame('paid', $sale1['payment_status']);
        $this->assertSame('80.0000', $this->inventory->currentStock($rice), 'Cash sale must reduce stock by the 20 bags sold.');
        $this->assertSame('400.00', $sale1['cost_of_goods_sold'], '20 bags at the $20 weighted-average cost.');
        $this->assertSame('140.00', $sale1['gross_profit'], '540 revenue - 400 COGS.');

        $cashAfterSale1 = $cashBeforeSale1 + 540;
        $this->assertSame(number_format($cashAfterSale1, 2, '.', ''), $this->balances->accountBalance($cash->fresh()), 'A cash sale must increase the selected account by the full amount, with no double effect.');

        // ======================================================================
        // 6-8. Credit sale -> inventory down, customer receivable up
        // ======================================================================
        $sale2 = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 15, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->assertSame('405.00', $sale2['total']);
        $this->assertSame('unpaid', $sale2['payment_status']);
        $this->assertSame('65.0000', $this->inventory->currentStock($rice), 'Credit sale must still reduce stock even though unpaid.');
        $this->assertSame('300.00', $sale2['cost_of_goods_sold'], '15 bags at $20 (average unchanged since no new purchase happened).');
        $this->assertSame('105.00', $sale2['gross_profit']);
        $this->assertSame('405.00', $this->balances->customerReceivableBalance($customer->fresh()), 'The full unpaid sale must be reflected as a customer receivable.');

        // Cash-sale revenue must not leak into the customer's receivable, and
        // the credit sale must not touch cash - the two customer events are
        // independent.
        $this->assertSame(number_format($cashAfterSale1, 2, '.', ''), $this->balances->accountBalance($cash->fresh()), 'An unpaid credit sale must not move any account balance.');

        // ======================================================================
        // 9-11. Customer payment -> receivable down, selected account up
        // ======================================================================
        $bankBeforePayment = (float) $this->balances->accountBalance($bank->fresh());

        $paidSale2 = $this->postJson("/api/sales/{$sale2['id']}/payments", [
            'amount' => 200,
            'account_id' => $bank->id,
        ])->assertOk()->json('sale');

        $this->assertSame('205.00', $paidSale2['balance_due'], '405 - 200 = 205 still owed.');
        $this->assertSame('partial', $paidSale2['payment_status']);
        $this->assertSame('205.00', $this->balances->customerReceivableBalance($customer->fresh()), 'Receivable must drop by exactly the payment amount.');
        $this->assertSame(number_format($bankBeforePayment + 200, 2, '.', ''), $this->balances->accountBalance($bank->fresh()), 'The payment must land in the selected account (Bank), not Cash.');

        // ======================================================================
        // 12-13. Purchase goods on supplier credit -> inventory up, payable up
        // ======================================================================
        $purchase3 = $this->postJson('/api/purchases', [
            'supplier_id' => $supplierB->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $rice->id, 'quantity' => 30, 'unit_cost' => 19]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('570.00', $purchase3['total']);
        $this->assertSame('unpaid', $purchase3['payment_status']);
        $this->assertSame('95.0000', $this->inventory->currentStock($rice), '65 + 30 = 95 bags.');
        $this->assertSame('570.00', $this->balances->supplierBalance($supplierB->fresh()), 'The full unpaid purchase must be reflected as a supplier payable.');
        $this->assertSame('0.00', $this->balances->supplierBalance($supplierA->fresh()), 'Supplier A (already fully paid) must be unaffected by Supplier B\'s new payable.');

        // Weighted-average accounting identity: cost of everything still in
        // stock plus cost of everything already sold must equal cost of
        // everything ever purchased - this holds regardless of rounding,
        // so it's a stronger correctness check than any single decimal.
        $totalPurchasedCost = 900 + 1100 + 570;
        $totalCogsSoFar = (float) $sale1['cost_of_goods_sold'] + (float) $sale2['cost_of_goods_sold'];
        $remainingInventoryValue = (float) $this->inventory->inventoryValue($rice);
        $this->assertEqualsWithDelta($totalPurchasedCost, $totalCogsSoFar + $remainingInventoryValue, 0.01, 'purchased cost = COGS already recognized + remaining inventory value');

        // ======================================================================
        // 14-15. Supplier payment -> payable down
        // ======================================================================
        $cashBeforeSupplierPayment = (float) $this->balances->accountBalance($cash->fresh());

        $paidPurchase3 = $this->postJson("/api/purchases/{$purchase3['id']}/payments", [
            'amount' => 300,
            'account_id' => $cash->id,
        ])->assertOk()->json('purchase');

        $this->assertSame('270.00', $paidPurchase3['balance_due'], '570 - 300 = 270 still owed.');
        $this->assertSame('270.00', $this->balances->supplierBalance($supplierB->fresh()));
        $this->assertSame(number_format($cashBeforeSupplierPayment - 300, 2, '.', ''), $this->balances->accountBalance($cash->fresh()), 'Supplier payment must reduce Cash, not create a duplicate expense.');

        // ======================================================================
        // 16-17. Give a loan -> loan outstanding up, account down
        // ======================================================================
        $cashBeforeLoan = (float) $this->balances->accountBalance($cash->fresh());

        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $borrower->id,
            'account_id' => $cash->id,
            'amount' => 500,
            'currency' => 'USD',
            'description' => 'Workflow test loan given',
        ])->assertCreated();

        $this->assertSame('500.00', $this->balances->personBalance($borrower->fresh()), 'Loan given must increase what the borrower owes the business.');
        $this->assertSame(number_format($cashBeforeLoan - 500, 2, '.', ''), $this->balances->accountBalance($cash->fresh()));

        $dashboardAfterLoan = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame('500.00', $dashboardAfterLoan['loans']['outstanding_given'], 'Dashboard must reflect the loan as outstanding-given, separate from customer receivables.');
        $this->assertSame('205.00', $dashboardAfterLoan['receivables']['customer_outstanding'], 'The loan must NOT be merged into customer receivables.');

        // ======================================================================
        // 18-19. Receive loan repayment -> loan outstanding down, account up
        // ======================================================================
        $bankBeforeRepayment = (float) $this->balances->accountBalance($bank->fresh());

        $this->postJson('/api/transactions', [
            'type' => 'loan_repayment',
            'person_id' => $borrower->id,
            'account_id' => $bank->id,
            'amount' => 200,
            'currency' => 'USD',
            'description' => 'Workflow test loan repayment',
        ])->assertCreated();

        $this->assertSame('300.00', $this->balances->personBalance($borrower->fresh()), '500 - 200 = 300 still outstanding.');
        $this->assertSame(number_format($bankBeforeRepayment + 200, 2, '.', ''), $this->balances->accountBalance($bank->fresh()));

        // ======================================================================
        // 20-23. Transfer between two business accounts
        // ======================================================================
        $cashBeforeTransfer = (float) $this->balances->accountBalance($cash->fresh());
        $bankBeforeTransfer = (float) $this->balances->accountBalance($bank->fresh());
        $totalBeforeTransfer = $cashBeforeTransfer + $bankBeforeTransfer;

        $this->postJson('/api/transactions', [
            'type' => 'account_transfer',
            'account_id' => $cash->id,
            'destination_account_id' => $bank->id,
            'amount' => 150,
            'currency' => 'USD',
            'description' => 'Workflow test transfer Cash -> Bank',
        ])->assertCreated();

        $cashAfterTransfer = (float) $this->balances->accountBalance($cash->fresh());
        $bankAfterTransfer = (float) $this->balances->accountBalance($bank->fresh());

        $this->assertSame(number_format($cashBeforeTransfer - 150, 2, '.', ''), number_format($cashAfterTransfer, 2, '.', ''), 'Source account must decrease by exactly the transfer amount.');
        $this->assertSame(number_format($bankBeforeTransfer + 150, 2, '.', ''), number_format($bankAfterTransfer, 2, '.', ''), 'Destination account must increase by exactly the transfer amount.');
        $this->assertEqualsWithDelta($totalBeforeTransfer, $cashAfterTransfer + $bankAfterTransfer, 0.01, 'Total business money must be unchanged by a transfer between its own accounts.');

        // ======================================================================
        // Cross-checks: dashboard, reports and the ledger all agree, and no
        // financial effect was ever duplicated.
        // ======================================================================
        $finalDashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame('205.00', $finalDashboard['receivables']['customer_outstanding']);
        $this->assertSame('270.00', $finalDashboard['payables']['outstanding']);
        $this->assertSame('300.00', $finalDashboard['loans']['outstanding_given']);
        $this->assertSame('0.00', $finalDashboard['loans']['outstanding_received']);

        $summary = $this->getJson('/api/reports/business-summary?range=this_year')->assertOk()->json();
        $this->assertSame('205.00', $summary['credit']['accounts_receivable']);
        $this->assertSame('270.00', $summary['credit']['accounts_payable']);

        $totalAccountsBalance = (float) $this->balances->accountBalance($cash->fresh()) + (float) $this->balances->accountBalance($bank->fresh());
        $this->assertEqualsWithDelta($totalAccountsBalance, (float) $summary['financial']['accounts_balance'], 0.01);

        $receivablesReport = $this->getJson('/api/reports/customer-receivables')->assertOk()->json();
        $customerRow = collect($receivablesReport['receivables'] ?? $receivablesReport['data'] ?? [])->firstWhere('id', $customer->id);
        $this->assertNotNull($customerRow, 'The customer must appear on the receivables report while they still owe money.');
        $this->assertSame('205.00', (string) $customerRow['balance']);

        $payablesReport = $this->getJson('/api/reports/supplier-payables')->assertOk()->json();
        $supplierRow = collect($payablesReport['payables'] ?? $payablesReport['data'] ?? [])->firstWhere('id', $supplierB->id);
        $this->assertNotNull($supplierRow, 'Supplier B must appear on the payables report while they are still owed money.');
        $this->assertSame('270.00', (string) $supplierRow['balance']);

        // No duplicate financial effects: exactly the expected number of
        // ledger rows exist for each business event. Purchase 1 and 2 were
        // each fully paid at creation (2 implicit supplier_payment rows),
        // plus one explicit follow-up payment on purchase 3 = 3 total.
        $this->assertSame(3, DB::table('transactions')->where('type', 'purchase')->count(), 'One purchase-type row per purchase created.');
        $this->assertSame(3, DB::table('transactions')->where('type', 'supplier_payment')->count());
        $this->assertSame(1, DB::table('transactions')->where('type', 'cash_sale')->count());
        $this->assertSame(1, DB::table('transactions')->where('type', 'credit_sale')->count());
        $this->assertSame(1, DB::table('transactions')->where('type', 'customer_payment')->count(), 'Exactly one follow-up customer payment was made.');
        $this->assertSame(1, DB::table('transactions')->where('type', 'loan_given')->count());
        $this->assertSame(1, DB::table('transactions')->where('type', 'loan_repayment')->count());
        $this->assertSame(1, DB::table('transactions')->where('type', 'account_transfer')->count());

        // Every posted transaction's signed effects must still reconcile to
        // the account balances we already verified independently above -
        // this is the definitive "no duplicate effect anywhere" proof.
        $reconciledCash = (float) $cash->fresh()->opening_balance
            + (float) DB::table('transactions')->where('status', 'posted')->where('account_id', $cash->id)->sum('account_balance_effect')
            + (float) DB::table('transactions')->where('status', 'posted')->where('destination_account_id', $cash->id)->sum('destination_account_effect');
        $this->assertEqualsWithDelta($cashAfterTransfer, $reconciledCash, 0.01);
    }

    /**
     * The business's OWN borrowing (loan_received / loan_payment) is the
     * mirror image of giving a loan, but had zero test coverage anywhere -
     * every other loan-type test only exercised loan_given/loan_repayment.
     * Proves the business-as-borrower side actually works and stays
     * separate from the business-as-lender side on the same person.
     */
    public function test_business_borrowing_and_repaying_its_own_loan_is_tracked_separately_from_loans_given(): void
    {
        $cash = Account::create(['name' => 'WF Borrow Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
        $lender = Person::create(['name' => 'Workflow Lender', 'is_active' => true, 'roles' => ['lender']]);

        $this->postJson('/api/transactions', [
            'type' => 'loan_received',
            'person_id' => $lender->id,
            'account_id' => $cash->id,
            'amount' => 400,
            'currency' => 'USD',
            'description' => 'Business borrows from lender',
        ])->assertCreated();

        $this->assertSame('400.00', $this->balances->accountBalance($cash->fresh()), 'Borrowed money must increase the receiving account.');
        $this->assertSame('-400.00', $this->balances->personBalance($lender->fresh()), 'A negative person_balance means the business owes the person, not the other way round.');

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame('400.00', $dashboard['loans']['outstanding_received'], 'Dashboard must report this as business-owed, not business-owed-to.');
        $this->assertSame('0.00', $dashboard['loans']['outstanding_given'], 'Borrowing money must not be counted as a loan given.');

        $this->postJson('/api/transactions', [
            'type' => 'loan_payment',
            'person_id' => $lender->id,
            'account_id' => $cash->id,
            'amount' => 150,
            'currency' => 'USD',
            'description' => 'Business repays part of the borrowed loan',
        ])->assertCreated();

        $this->assertSame('250.00', $this->balances->accountBalance($cash->fresh()), '400 received - 150 repaid.');
        $this->assertSame('-250.00', $this->balances->personBalance($lender->fresh()), '400 owed - 150 repaid = 250 still owed.');

        $dashboardAfterRepayment = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame('250.00', $dashboardAfterRepayment['loans']['outstanding_received']);
    }
}

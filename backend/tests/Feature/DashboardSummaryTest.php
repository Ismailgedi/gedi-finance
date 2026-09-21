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
    }
}

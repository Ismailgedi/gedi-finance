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
use App\Services\ReportPdfService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the "Download PDF" export feature: the underlying data service
 * (ReportPdfService) produces exactly the filtered rows and business-accurate
 * totals - never the whole database when a filter is applied, and never a
 * balance re-derived from a date-limited row sum when the real, current
 * balance (BalanceService) is the correct figure to show. Also smoke-tests
 * that the HTTP endpoints actually return a PDF binary and enforce the same
 * authentication as the rest of the API.
 */
class ReportPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private ReportPdfService $reports;

    private BalanceService $balances;

    private Product $rice;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->reports = app(ReportPdfService::class);
        $this->balances = app(BalanceService::class);

        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'PDF Export Rice',
            'sku' => 'PDF-RICE',
            'default_cost_price' => 10,
            'default_selling_price' => 25,
            'is_active' => true,
        ]);
        app(InventoryService::class)->record($this->rice, 1000, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 10);

        $this->cash = Account::create([
            'name' => 'PDF Export Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_transactions_report_with_no_filters_includes_every_posted_and_voided_transaction(): void
    {
        $ali = Person::create(['name' => 'Ali Hassan', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $bilal = Person::create(['name' => 'Bilal', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/sales', [
            'customer_id' => $ali->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 25]],
        ])->assertCreated();

        $this->postJson('/api/sales', [
            'customer_id' => $bilal->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 25]],
        ])->assertCreated();

        $report = $this->reports->transactionsReport([]);

        $this->assertSame('BUSINESS TRANSACTION REPORT', $report['title']);
        $this->assertSame(2, $report['count'], 'Unfiltered export must include every transaction, not just one customer.');
    }

    public function test_transactions_report_filtered_by_one_customer_only_contains_their_records(): void
    {
        $ali = Person::create(['name' => 'Ali Hassan', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $bilal = Person::create(['name' => 'Bilal', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        // Ali: a credit sale for $300, then a $100 payment.
        $this->postJson('/api/sales', [
            'customer_id' => $ali->id,
            'sale_date' => now()->subDays(16)->toDateString(),
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 12, 'unit_price' => 25]],
        ])->assertCreated();
        $this->postJson("/api/people/{$ali->id}/payments", [
            'amount' => 100,
            'account_id' => $this->cash->id,
        ])->assertOk();

        // Bilal: unrelated sale that must NOT leak into Ali's statement.
        $this->postJson('/api/sales', [
            'customer_id' => $bilal->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 25]],
        ])->assertCreated();

        $report = $this->reports->transactionsReport(['person_id' => (string) $ali->id]);

        $this->assertSame('CUSTOMER STATEMENT', $report['title']);
        $this->assertSame('Customer: Ali Hassan', $report['subtitle']);
        $this->assertSame(2, $report['count'], 'Only Ali\'s 2 transactions (sale + payment), never Bilal\'s.');

        foreach ($report['rows'] as $row) {
            $this->assertStringNotContainsString('Bilal', json_encode($row));
        }

        $this->assertSame('300.00', $report['totals']['Total Credit Sales']);
        $this->assertSame('100.00', $report['totals']['Total Payments']);
        $this->assertSame(
            $this->balances->customerReceivableBalance($ali->fresh()),
            $report['totals']['Outstanding Balance'],
            'Outstanding balance must come from the real BalanceService figure, not a row sum.',
        );
        $this->assertSame('200.00', $report['totals']['Outstanding Balance']);
    }

    public function test_outstanding_balance_uses_real_balance_not_a_date_filtered_row_sum(): void
    {
        $ali = Person::create(['name' => 'Ali Hassan', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        // An OLD credit sale, outside the date filter window below.
        $this->postJson('/api/sales', [
            'customer_id' => $ali->id,
            'sale_date' => now()->subDays(90)->toDateString(),
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 8, 'unit_price' => 25]],
        ])->assertCreated(); // 200 owed, from 90 days ago

        // A RECENT credit sale, inside the date filter window.
        $this->postJson('/api/sales', [
            'customer_id' => $ali->id,
            'sale_date' => now()->subDays(2)->toDateString(),
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 25]],
        ])->assertCreated(); // 100 owed, from 2 days ago

        // Ali truly owes 300 total, but we filter to only the last 7 days.
        $report = $this->reports->transactionsReport([
            'person_id' => (string) $ali->id,
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertSame(1, $report['count'], 'Only the recent sale falls inside the date filter.');
        $this->assertSame('100.00', $report['totals']['Total Credit Sales'], 'Row-sum total correctly reflects only the filtered rows.');
        $this->assertSame(
            '300.00',
            $report['totals']['Outstanding Balance'],
            'Outstanding balance is the customer\'s REAL current balance, unaffected by the date filter excluding the older invoice.',
        );
    }

    public function test_a_customer_with_no_transactions_produces_an_empty_but_valid_report(): void
    {
        $noHistory = Person::create(['name' => 'No History Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $report = $this->reports->transactionsReport(['person_id' => (string) $noHistory->id]);

        $this->assertSame('CUSTOMER STATEMENT', $report['title']);
        $this->assertSame(0, $report['count']);
        $this->assertSame([], $report['rows']);
        $this->assertSame('0.00', $report['totals']['Outstanding Balance']);
    }

    public function test_transactions_report_type_and_account_filters_combine_with_person_and_date(): void
    {
        $ali = Person::create(['name' => 'Ali Hassan', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $otherCash = Account::create(['name' => 'Other Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        $this->postJson('/api/sales', [
            'customer_id' => $ali->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 12, 'unit_price' => 25]],
        ])->assertCreated();

        // Payment via the account we will filter FOR.
        $this->postJson("/api/people/{$ali->id}/payments", [
            'amount' => 150,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $report = $this->reports->transactionsReport([
            'person_id' => (string) $ali->id,
            'type' => 'customer_payment',
            'account_id' => (string) $this->cash->id,
        ]);

        $this->assertSame(1, $report['count'], 'Type + account filters together must leave only the one matching payment.');
        $this->assertSame('150.00', $report['rows'][0]['amount']);

        // Filtering by the OTHER account must exclude it entirely.
        $reportOtherAccount = $this->reports->transactionsReport([
            'person_id' => (string) $ali->id,
            'type' => 'customer_payment',
            'account_id' => (string) $otherCash->id,
        ]);
        $this->assertSame(0, $reportOtherAccount['count']);
    }

    public function test_sales_report_titles_and_totals_switch_based_on_customer_filter(): void
    {
        $ali = Person::create(['name' => 'Ali Hassan', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $bilal = Person::create(['name' => 'Bilal', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/sales', [
            'customer_id' => $ali->id,
            'amount_paid' => 100,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 12, 'unit_price' => 25]],
        ])->assertCreated(); // total 300, paid 100, balance 200

        $this->postJson('/api/sales', [
            'customer_id' => $bilal->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 25]],
        ])->assertCreated(); // total 100

        $general = $this->reports->salesReport([]);
        $this->assertSame('SALES REPORT', $general['title']);
        $this->assertSame(2, $general['count']);
        $this->assertSame('400.00', $general['totals']['Total Sales']);

        $forAli = $this->reports->salesReport(['customer_id' => (string) $ali->id]);
        $this->assertSame('CUSTOMER STATEMENT', $forAli['title']);
        $this->assertSame(1, $forAli['count']);
        $this->assertSame(
            $this->balances->customerReceivableBalance($ali->fresh()),
            $forAli['totals']['Outstanding Balance'],
        );
    }

    public function test_purchases_report_titles_and_totals_switch_based_on_supplier_filter(): void
    {
        $supplierA = Supplier::create(['supplier_code' => 'PDF-SUP-A', 'name' => 'Supplier A', 'is_active' => true]);
        $supplierB = Supplier::create(['supplier_code' => 'PDF-SUP-B', 'name' => 'Supplier B', 'is_active' => true]);
        $fundedCash = Account::create(['name' => 'Funded Cash', 'type' => 'cash', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => true]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplierA->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 40, 'unit_cost' => 10]],
        ])->assertCreated(); // 400 payable

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplierB->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 10]],
        ])->assertCreated(); // 100 payable

        $general = $this->reports->purchasesReport([]);
        $this->assertSame('PURCHASE REPORT', $general['title']);
        $this->assertSame(2, $general['count']);

        $forSupplierA = $this->reports->purchasesReport(['supplier_id' => (string) $supplierA->id]);
        $this->assertSame('SUPPLIER STATEMENT', $forSupplierA['title']);
        $this->assertSame(1, $forSupplierA['count']);
        $this->assertSame(
            $this->balances->supplierBalance($supplierA->fresh()),
            $forSupplierA['totals']['Outstanding Balance'],
        );
        $this->assertSame('400.00', $forSupplierA['totals']['Outstanding Balance']);
    }

    public function test_a_voided_sale_is_excluded_from_the_sales_report(): void
    {
        $ali = Person::create(['name' => 'Ali Hassan', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $ali->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 25]],
        ])->assertCreated()->json('sale');

        $this->postJson("/api/sales/{$sale['id']}/void", [])->assertOk();

        $report = $this->reports->salesReport([]);
        $this->assertSame(0, $report['count'], 'A voided sale must not appear in the active Sales Report.');
    }

    public function test_pdf_endpoints_require_authentication(): void
    {
        auth()->logout();

        $this->getJson('/api/reports/transactions/pdf')->assertUnauthorized();
    }

    public function test_transactions_pdf_endpoint_returns_a_real_pdf_binary_for_the_filtered_set(): void
    {
        $ali = Person::create(['name' => 'Ali Hassan', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $ali->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 25]],
        ])->assertCreated();

        $response = $this->get("/api/reports/transactions/pdf?person_id={$ali->id}");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent(), 'Response body must be an actual PDF binary.');
    }

    public function test_sales_pdf_endpoint_returns_a_real_pdf_binary(): void
    {
        $response = $this->get('/api/reports/sales/pdf');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_purchases_pdf_endpoint_returns_a_real_pdf_binary(): void
    {
        $response = $this->get('/api/reports/purchases/pdf');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pdf_export_rejects_an_id_for_a_person_that_does_not_exist(): void
    {
        $response = $this->getJson('/api/reports/transactions/pdf?person_id=999999');
        $response->assertStatus(422);
    }
}

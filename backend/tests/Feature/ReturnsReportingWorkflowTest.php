<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReportExportService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Returns as seen from Business Position, P&L, statements and exports -
 * SaleReturnWorkflowTest/PurchaseReturnWorkflowTest already prove the return
 * mechanics themselves; this file proves the rest of the app keeps reporting
 * them correctly, per the "smallest safe design" from the approved review
 * (mutate the original sale/purchase's own stored totals, add Customer/
 * Supplier Credits as new balance-sheet lines).
 */
class ReturnsReportingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ReportExportService $reports;
    private Product $rice;
    private Account $cash;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('User', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->reports = app(ReportExportService::class);

        // opening_balance stays 0 deliberately: it is not itself part of
        // the equity formula (see BusinessCapitalService::
        // cumulativeEquityAsOf(), which only sums profit + contributions -
        // withdrawals) - real starting cash has to come from an
        // owner_contribution transaction instead, or reconciliation breaks.
        $this->cash = Account::create([
            'name' => 'Reporting Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $owner = Person::create(['name' => 'Reporting Test Owner', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);
        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution',
            'person_id' => $owner->id,
            'account_id' => $this->cash->id,
            'amount' => 100000,
            'description' => 'Funding for reporting tests',
        ])->assertCreated();

        $this->supplier = Supplier::create([
            'supplier_code' => 'RREP-SUP-' . uniqid(),
            'name' => 'Reporting Return Supplier',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'Reporting Bag', 'abbreviation' => 'repbag' . uniqid()]);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Reporting Return Rice',
            'sku' => 'RRET-RICE-' . uniqid(),
            'default_cost_price' => 25,
            'default_selling_price' => 40,
            'is_active' => true,
        ]);

        // Stocked through a real, fully-paid purchase rather than a raw
        // InventoryService seed - so its inventory value always has a
        // matching cash effect and never breaks Business Position
        // reconciliation for any test in this file that checks it.
        $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 25000,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1000, 'unit_cost' => 25]],
        ])->assertCreated();
    }

    private function customer(string $name = 'Reporting Return Customer'): Person
    {
        return Person::create(['name' => $name, 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
    }

    private function position(?string $from = null, ?string $to = null): array
    {
        $query = [];
        if ($from) {
            $query['from'] = $from;
        }
        if ($to) {
            $query['to'] = $to;
        }
        $query['range'] = 'custom';

        return $this->getJson('/api/reports/business-position?' . http_build_query($query))->assertOk()->json();
    }

    // --- 27 & 28. Customer Credits as liabilities, Supplier Credits as assets ---

    public function test_customer_credit_appears_as_a_liability_not_inside_receivables(): void
    {
        $customer = $this->customer();
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 400,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');

        $item = $sale['items'][0];
        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'reason' => 'Reporting check',
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $item['id'], 'quantity' => 3]],
        ])->assertCreated();

        $today = now()->toDateString();
        $position = $this->position($today, $today);

        $this->assertSame('0.00', $position['assets']['customer_receivables'], 'A credit must never appear as a receivable.');
        $this->assertSame('120.00', $position['liabilities']['customer_credits']); // 3 * 40
    }

    public function test_supplier_credit_appears_as_an_asset_not_inside_payables(): void
    {
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 360,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 20, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $item = $purchase['items'][0];
        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'],
            'reason' => 'Reporting check',
            'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $item['id'], 'quantity' => 5]],
        ])->assertCreated();

        $today = now()->toDateString();
        $position = $this->position($today, $today);

        $this->assertSame('0.00', $position['liabilities']['supplier_payables'], 'A credit must never appear as a payable.');
        $this->assertSame('90.00', $position['assets']['supplier_credits']); // 5 * 18
    }

    // --- 29 & 30. No double counting, Business Position reconciles ---

    public function test_business_position_reconciles_with_mixed_returns_and_refunds(): void
    {
        // A fresh product, stocked entirely through real, fully-paid
        // purchases (never the raw InventoryService seed setUp() uses for
        // the other tests in this file) - every dollar of inventory value
        // here has a matching cash/payable effect, so this specifically
        // proves the RETURN feature reconciles, not an artifact of
        // unbacked test-fixture stock.
        $bag = Unit::create(['name' => 'Recon Bag', 'abbreviation' => 'reconbag' . uniqid()]);
        $product = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Reconciliation Rice',
            'sku' => 'RECON-RICE-' . uniqid(),
            'default_cost_price' => 25,
            'default_selling_price' => 40,
            'is_active' => true,
        ]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 250,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 25]],
        ])->assertCreated();

        $customer = $this->customer();

        // Fully paid sale, partial refund return.
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 400,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');
        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'reason' => 'Mixed reconciliation check',
            'settlement_method' => 'refund',
            'refund_account_id' => $this->cash->id,
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
        ])->assertCreated();

        // Fully paid purchase, partial credit return (no refund - a
        // lingering supplier credit).
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 360,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');
        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'],
            'reason' => 'Mixed reconciliation check',
            'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 4]],
        ])->assertCreated();

        $today = now()->toDateString();
        $position = $this->position($today, $today);

        $this->assertTrue($position['check']['matches'], 'Assets - Liabilities must equal Closing Equity even with mixed returns/refunds/credits.');
    }

    // --- 31. P&L remains correct ---

    public function test_profit_report_reflects_the_return(): void
    {
        $customer = $this->customer();
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');
        // Before return: revenue 400, cogs 250, gross profit 150.

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'reason' => 'P&L check',
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 4]],
        ])->assertCreated();
        // After: revenue 400-160=240, cogs 250-100=150, gross profit 90.

        $response = $this->getJson('/api/reports/profit?range=today')->assertOk();
        $this->assertSame('240.00', $response->json('revenue.sales_revenue'));
        $this->assertSame('150.00', $response->json('cost_of_goods_sold.cogs'));
        $this->assertSame('90.00', $response->json('gross_profit'));
    }

    // --- 32. Customer statement correct ---

    public function test_customer_statement_reflects_the_return(): void
    {
        $customer = $this->customer();
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'reason' => 'Statement check',
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
        ])->assertCreated();

        $statement = $this->reports->salesReport(['customer_id' => (string) $customer->id]);
        $this->assertSame('CUSTOMER STATEMENT', $statement['title']);
        $this->assertSame('320.00', $statement['totals']['Outstanding Balance']);
    }

    // --- 33. Supplier statement correct ---

    public function test_supplier_statement_reflects_the_return(): void
    {
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 20, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'],
            'reason' => 'Statement check',
            'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 3]],
        ])->assertCreated();

        $statement = $this->reports->purchasesReport(['supplier_id' => (string) $this->supplier->id]);
        $this->assertSame('SUPPLIER STATEMENT', $statement['title']);
        $this->assertSame('306.00', $statement['totals']['Outstanding Balance']); // 360 - 54
    }

    // --- 34. Excel export correct ---

    public function test_business_position_excel_export_includes_credit_lines(): void
    {
        $customer = $this->customer();
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 400,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');
        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'reason' => 'Excel export check',
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
        ])->assertCreated();

        $report = $this->reports->businessPositionReport(['range' => 'today']);
        $rows = collect($report['rows']);
        $this->assertNotNull($rows->first(fn ($row) => $row[1] === 'Customer Credits'));

        $this->get('/api/reports/business-position/excel')->assertOk();
    }

    // --- 35. Closed-year rules respected ---

    public function test_a_return_dated_inside_a_closed_financial_year_is_blocked(): void
    {
        $customer = $this->customer();
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'sale_date' => '2024-06-01',
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated()->json('sale');

        $this->postJson('/api/admin/financial-years/2024/close')->assertOk();

        $response = $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'return_date' => '2024-06-15',
            'reason' => 'Closed year check',
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Financial year 2024 is closed', $response->json('errors.financial_year.0'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    private function account(): Account
    {
        return Account::create([
            'name' => 'Report Test Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'supplier_code' => 'SUP-' . uniqid(),
            'name' => 'Report Test Supplier',
            'credit_limit' => 1000,
            'is_active' => true,
        ]);
    }

    private function customer(): Person
    {
        return Person::create([
            'name' => 'Report Test Customer',
            'roles' => ['customer'],
            'is_customer' => true,
            'customer_code' => 'CUST-' . uniqid(),
            'credit_limit' => 500,
            'is_active' => true,
        ]);
    }

    private function product(): Product
    {
        $unit = Unit::create(['name' => 'Piece-' . uniqid(), 'abbreviation' => 'pc' . rand(100, 999)]);

        return Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'Report Test Product',
            'sku' => 'SKU-' . uniqid(),
            'minimum_stock' => 2,
            'is_active' => true,
        ]);
    }

    /**
     * The controlled scenario from the task spec, corrected to be
     * internally consistent: the task text gave three numbers -
     * "5 x $20" for the first purchase, weighted average $21.3333, and
     * COGS $64.00 for a 3-unit sale - but 5x$20 + 5x$24 only produces a
     * $22.00 weighted average (COGS $66.00, gross profit $24.00), not
     * $21.3333/$64.00/$26.00. Those last two figures agree with each
     * other and with a *10*-unit first purchase (10x$20=$200 + 5x$24=$120
     * = $320 / 15 units = $21.3333/unit; 3 x $21.3333 = $64.00), so that's
     * the quantity used here - the two independently-stated results
     * outvote the single inconsistent one.
     */
    private function runControlledScenario(): array
    {
        $supplier = $this->supplier();
        $customer = $this->customer();
        $product = $this->product();
        $account = $this->account();

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated();

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 24]],
        ])->assertCreated();

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'account_id' => $account->id,
            'amount_paid' => 90,
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 30]],
        ])->assertCreated()->json('sale');

        return compact('supplier', 'customer', 'product', 'account', 'sale');
    }

    public function test_controlled_scenario_matches_known_weighted_average_and_cogs_values(): void
    {
        $scenario = $this->runControlledScenario();

        $this->assertEquals('21.3333', (new \App\Services\InventoryService())->weightedAverageCost($scenario['product']->fresh()));
        $this->assertEquals('64.00', $scenario['sale']['cost_of_goods_sold']);
        $this->assertEquals('26.00', $scenario['sale']['gross_profit']);
    }

    public function test_business_summary_reflects_the_controlled_scenario(): void
    {
        $this->runControlledScenario();

        $response = $this->getJson('/api/reports/business-summary?range=today')->assertOk();

        $response->assertJsonPath('financial.total_sales', '90.00');
        $response->assertJsonPath('financial.cash_sales', '90.00');
        $response->assertJsonPath('financial.credit_sales', '0.00');
        $response->assertJsonPath('financial.gross_profit', '26.00');
        $response->assertJsonPath('sales.invoices', 1);
        // Purchases: 200 + 120 = 320, fully unpaid by default (no amount_paid sent).
        $response->assertJsonPath('purchases.purchase_value', '320.00');
        $response->assertJsonPath('purchases.outstanding_supplier_balance', '320.00');
        $this->assertEquals(12.0, $response->json('inventory.current_stock_quantity'));
    }

    public function test_credit_sale_stays_classified_as_credit_even_after_being_fully_paid_later(): void
    {
        $supplier = $this->supplier();
        $customer = $this->customer();
        $product = $this->product();
        $account = $this->account();

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 20]],
        ])->assertCreated();

        // Sale with NO payment at all -> classified as a credit sale.
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 30]],
        ])->assertCreated()->json('sale');

        // Now pay it off in full.
        $this->postJson("/api/sales/{$sale['id']}/payments", [
            'account_id' => $account->id,
            'amount' => $sale['total'],
        ])->assertOk();

        $summary = $this->getJson('/api/reports/business-summary?range=this_month')->assertOk();

        // Even though amount_paid now equals total, this must still count
        // as a credit sale (that's what actually happened), not flip to a
        // cash sale just because it has since been collected.
        $summary->assertJsonPath('financial.credit_sales', $sale['total']);
        $summary->assertJsonPath('financial.cash_sales', '0.00');
        $summary->assertJsonPath('financial.customer_collections', $sale['total']);
    }

    public function test_date_range_presets_resolve_without_hardcoded_dates(): void
    {
        foreach (['today', 'this_week', 'this_month', 'last_month', 'this_year'] as $range) {
            $response = $this->getJson("/api/reports/business-summary?range={$range}")->assertOk();
            $this->assertNotEmpty($response->json('period.from'));
            $this->assertNotEmpty($response->json('period.to'));
            $this->assertEquals($range, $response->json('period.range'));
        }
    }

    public function test_custom_range_validates_the_date_pair(): void
    {
        $this->getJson('/api/reports/business-summary?from=2026-09-10&to=2026-09-01')
            ->assertStatus(422);

        $this->getJson('/api/reports/business-summary?from=not-a-date&to=2026-09-01')
            ->assertStatus(422);

        $this->getJson('/api/reports/business-summary?from=2026-09-01&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('period.range', 'custom');
    }

    public function test_sales_report_supports_filters_search_and_pagination(): void
    {
        $scenario = $this->runControlledScenario();

        $filtered = $this->getJson('/api/reports/sales?range=this_month&customer_id=' . $scenario['customer']->id)->assertOk();
        $filtered->assertJsonCount(1, 'data');
        $filtered->assertJsonPath('summary.total_sales', '90.00');
        $filtered->assertJsonPath('data.0.payment_status', 'paid');

        $searched = $this->getJson('/api/reports/sales?range=this_month&search=' . urlencode($scenario['sale']['invoice_number']))->assertOk();
        $searched->assertJsonCount(1, 'data');

        $paged = $this->getJson('/api/reports/sales?range=this_month&per_page=1&page=1')->assertOk();
        $this->assertSame(1, $paged->json('meta.per_page'));
    }

    public function test_purchases_report_summary_matches_totals(): void
    {
        $this->runControlledScenario();

        $response = $this->getJson('/api/reports/purchases?range=this_month')->assertOk();
        $response->assertJsonPath('summary.total_purchases', '320.00');
        $response->assertJsonCount(2, 'data');
    }

    public function test_profit_report_separates_revenue_from_collections_and_computes_net_profit(): void
    {
        $this->runControlledScenario();

        $category = Category::create([
            'name' => 'Report Test Expense',
            'type' => 'expense',
            'is_active' => true,
        ]);

        $account = $this->account();
        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => 10,
            'description' => 'Report test expense',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $response = $this->getJson('/api/reports/profit?range=today')->assertOk();

        $response->assertJsonPath('revenue.sales_revenue', '90.00');
        $response->assertJsonPath('cost_of_goods_sold.cogs', '64.00');
        $response->assertJsonPath('gross_profit', '26.00');
        $response->assertJsonPath('operating_expenses.total', '10.00');
        $response->assertJsonPath('net_profit', '16.00');
    }

    public function test_customer_receivables_report_uses_balance_service_and_sorts_by_outstanding(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated();

        $smallDebtor = $this->customer();
        $bigDebtor = $this->customer();

        $this->postJson('/api/sales', [
            'customer_id' => $smallDebtor->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 30]],
        ])->assertCreated();

        $this->postJson('/api/sales', [
            'customer_id' => $bigDebtor->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 30]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/customer-receivables')->assertOk();
        $data = $response->json('data');

        $this->assertSame($bigDebtor->id, $data[0]['id']);
        $this->assertSame('90.00', $data[0]['balance']);
        $this->assertSame('410.00', $data[0]['available_credit']); // 500 credit limit - 90
    }

    public function test_supplier_payables_report_uses_balance_service(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 20]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/supplier-payables')->assertOk();
        $response->assertJsonPath('data.0.balance', '100.00');
    }

    public function test_inventory_report_uses_inventory_service_and_flags_low_stock(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(); // minimum_stock = 2

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 20]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/inventory')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $product->id);

        // json_encode(3.0) serializes as "3" (no fractional part), so it
        // round-trips as an int - assertEquals treats that as equal to
        // 3.0, which is what actually matters for a JSON API consumer.
        $this->assertEquals(3.0, $row['stock']);
        $this->assertSame('60.00', $row['inventory_value']);
        $this->assertSame('in_stock', $row['status']); // 3 > minimum_stock 2

        // Sell enough to drop at/under the minimum stock threshold.
        $this->postJson('/api/sales', [
            'customer_id' => $this->customer()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 30]],
            'amount_paid' => 30,
            'account_id' => $this->account()->id,
        ])->assertCreated();

        $afterSale = $this->getJson('/api/reports/inventory')->assertOk();
        $rowAfter = collect($afterSale->json('data'))->firstWhere('id', $product->id);
        $this->assertSame('low_stock', $rowAfter['status']); // 2 <= minimum_stock 2
    }
}

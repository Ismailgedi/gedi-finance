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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The complete Business Position / Year-End report: cash breakdown,
 * receivables, per-product inventory (system vs physical vs recorded
 * adjustment), payables, equity and reconciliation - all sourced from
 * BusinessCapitalService::position(), the same call the on-screen report
 * and the Excel export both make, so none of these figures are computed
 * twice.
 */
class BusinessPositionCompleteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Account $bank;
    private Product $maize;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Owner contribution transactions below need Super Admin now (see
        // TransactionService::assertAuthorizedForCapitalMovement).
        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->cash = Account::create(['name' => 'BP Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
        $this->bank = Account::create(['name' => 'BP Bank', 'type' => 'bank', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);

        $unit = Unit::create(['name' => 'BP Bag', 'abbreviation' => 'bpbag']);
        $this->maize = Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'BP Maize',
            'sku' => 'BP-MAIZE',
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

    // 1. Cash totals: per-account breakdown and total.
    public function test_cash_accounts_breakdown_and_total(): void
    {
        $owner = Person::create(['name' => 'BP Owner', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);

        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution', 'person_id' => $owner->id, 'account_id' => $this->cash->id,
            'amount' => 1000, 'description' => 'Cash contribution',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution', 'person_id' => $owner->id, 'account_id' => $this->bank->id,
            'amount' => 500, 'description' => 'Bank contribution',
        ])->assertCreated();

        $position = $this->position();

        $accounts = collect($position['cash_accounts']);
        $this->assertSame('1000.00', $accounts->firstWhere('name', 'BP Cash')['balance']);
        $this->assertSame('500.00', $accounts->firstWhere('name', 'BP Bank')['balance']);
        $this->assertSame('1500.00', $position['assets']['cash_and_bank']);
    }

    // 2. Receivables: customer receivables + loans given.
    public function test_receivables_include_customer_and_loans_given(): void
    {
        app(InventoryService::class)->record($this->maize, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 30);

        $customer = Person::create(['name' => 'BP Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 5, 'unit_price' => 40]],
        ])->assertCreated();

        $borrower = Person::create(['name' => 'BP Borrower', 'is_active' => true, 'roles' => []]);
        $this->postJson('/api/transactions', [
            'type' => 'loan_given', 'person_id' => $borrower->id, 'account_id' => $this->cash->id,
            'amount' => 300, 'description' => 'Loan given',
        ])->assertCreated();

        $position = $this->position();

        $this->assertSame('200.00', $position['assets']['customer_receivables']);
        $this->assertSame('300.00', $position['assets']['loans_given']);
    }

    // 3. Inventory: system quantity and unit cost, no count yet.
    public function test_inventory_breakdown_shows_system_quantity_and_unit_cost(): void
    {
        app(InventoryService::class)->record($this->maize, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 30);

        $position = $this->position();
        $row = collect($position['inventory_breakdown'])->firstWhere('product_id', $this->maize->id);

        $this->assertSame('100.0000', $row['system_quantity']);
        $this->assertSame('30.0000', $row['unit_cost']);
        $this->assertSame('3000.00', $row['inventory_value']);
        $this->assertNull($row['physical_quantity']);
        $this->assertNull($row['difference']);
    }

    // 4. Inventory: a recorded physical count shown alongside a system
    // quantity that has since moved on - the count is supporting evidence,
    // never silently substituted for the accounting figure.
    public function test_inventory_breakdown_shows_physical_count_alongside_system_quantity(): void
    {
        app(InventoryService::class)->record($this->maize, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 30);

        $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->maize->id,
            'physical_quantity' => 96,
            'reason' => 'Physical count',
        ])->assertCreated();

        // More stock arrives after the count - system quantity moves on,
        // the count stays exactly what was recorded.
        app(InventoryService::class)->record($this->maize, 10, null, 'purchase', 'purchase', null, null, 'Restock', null, 'in', 30);

        $position = $this->position();
        $row = collect($position['inventory_breakdown'])->firstWhere('product_id', $this->maize->id);

        $this->assertSame('106.0000', $row['system_quantity']);
        $this->assertSame('96.0000', $row['physical_quantity']);
        $this->assertSame((float) -4, (float) $row['difference']);
        $this->assertNotNull($row['physical_count_date']);
    }

    // 5. Supplier payables.
    public function test_supplier_payables_included(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'BP-SUP', 'name' => 'BP Supplier', 'is_active' => true]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 10, 'unit_cost' => 30]],
        ])->assertCreated();

        $position = $this->position();
        $this->assertSame('300.00', $position['liabilities']['supplier_payables']);
    }

    // 6. Loans received.
    public function test_loans_received_included(): void
    {
        $lender = Person::create(['name' => 'BP Lender', 'is_active' => true, 'roles' => []]);
        $this->postJson('/api/transactions', [
            'type' => 'loan_received', 'person_id' => $lender->id, 'account_id' => $this->cash->id,
            'amount' => 400, 'description' => 'Loan received',
        ])->assertCreated();

        $position = $this->position();
        $this->assertSame('400.00', $position['liabilities']['loans_received']);
    }

    // 7 & 9. Reconciliation across a fully mixed scenario, open year.
    public function test_reconciliation_matches_for_a_mixed_open_year(): void
    {
        $owner = Person::create(['name' => 'BP Owner Mix', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);
        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution', 'person_id' => $owner->id, 'account_id' => $this->cash->id,
            'amount' => 5000, 'description' => 'Capital',
        ])->assertCreated();

        $supplier = Supplier::create(['supplier_code' => 'BP-SUP-2', 'name' => 'BP Supplier 2', 'is_active' => true]);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 50, 'unit_cost' => 30]],
        ])->assertCreated();

        $customer = Person::create(['name' => 'BP Customer Mix', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $this->maize->id, 'quantity' => 10, 'unit_price' => 40]],
        ])->assertCreated();

        $position = $this->position();

        $this->assertFalse($position['is_closed']);
        $this->assertTrue($position['check']['matches']);
    }

    // 8. Closed-year snapshot includes the cash/inventory breakdowns and
    // stays stable regardless of later activity.
    public function test_closed_year_snapshot_includes_cash_and_inventory_breakdowns(): void
    {
        $owner = Person::create(['name' => 'BP Closed Owner', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);
        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution', 'person_id' => $owner->id, 'account_id' => $this->cash->id,
            'amount' => 2000, 'description' => 'Capital', 'transaction_date' => '2020-06-01',
        ])->assertCreated();

        // InventoryService::record() always stamps occurred_at = now(); it
        // has no backdating parameter, so it's set directly here to place
        // this movement inside the 2020 financial year being closed.
        $movement = app(InventoryService::class)->record($this->maize, 50, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 30);
        DB::table('inventory_movements')->where('id', $movement->id)->update(['occurred_at' => '2020-06-15']);

        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)->postJson('/api/admin/financial-years/2020/close')->assertOk();

        $closed = $this->position('2020-01-01', '2020-12-31');
        $this->assertTrue($closed['is_closed']);
        $this->assertNotEmpty($closed['cash_accounts']);
        $this->assertNotEmpty($closed['inventory_breakdown']);

        $before = $closed;

        // Unrelated later activity must not change the frozen snapshot.
        app(InventoryService::class)->record($this->maize, 999, null, 'purchase', 'purchase', null, null, 'Later stock', null, 'in', 99);

        $after = $this->position('2020-01-01', '2020-12-31');
        $this->assertSame($before, $after);
    }

    // 10. Excel export returns a real workbook containing the same
    // sections shown on screen.
    public function test_excel_export_returns_a_workbook(): void
    {
        app(InventoryService::class)->record($this->maize, 20, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 30);

        $response = $this->get('/api/reports/business-position/excel');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            strtolower($response->headers->get('content-type')),
        );
    }
}

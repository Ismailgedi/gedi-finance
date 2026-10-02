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
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Financial Year Close: turns a Business Position report from a live,
 * always-recomputed view into a frozen historical record once a Super
 * Admin closes it (see FinancialYearCloseService, BusinessCapitalService::
 * position()/fromClosedSnapshot(), and the closed-year guards added to
 * TransactionService::create()/voidTransaction()).
 */
class FinancialYearCloseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        \Database\Seeders\DatabaseSeeder::seedRolesAndPermissions();

        $this->actingAs($this->superAdmin());

        $this->cash = Account::create([
            'name' => 'FY Close Test Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'FY Close Bag', 'abbreviation' => 'fybag']);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'FY Close Rice',
            'sku' => 'FY-CLOSE-RICE',
            'default_cost_price' => 20,
            'default_selling_price' => 30,
            'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['password' => Hash::make('admin-password')]);
        $admin->assignRole('Super Admin');

        return $admin;
    }

    private function regularUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        return $user;
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

    private function contribute(Person $owner, float $amount, string $date): void
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

    private function withdraw(Person $owner, float $amount, string $date): void
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

    private function stockRice(float $quantity = 100, float $unitCost = 20): void
    {
        app(InventoryService::class)->record(
            $this->rice, $quantity, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', $unitCost
        );
    }

    private function position(?string $from = null, ?string $to = null): array
    {
        $query = array_filter(['from' => $from, 'to' => $to]);

        return $this->getJson('/api/reports/business-position?' . http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function close(int $year): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/admin/financial-years/{$year}/close");
    }

    private function reopen(int $year): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/admin/financial-years/{$year}/reopen");
    }

    // 1 & 2. Closing a financial year stores the snapshot with the correct closing equity.
    public function test_closing_a_financial_year_stores_the_snapshot(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000, '2025-06-01');

        $this->close(2025)->assertOk();

        $this->assertDatabaseHas('financial_year_closes', [
            'financial_year' => 2025,
            'status' => 'closed',
            'closing_equity' => '5000.00',
        ]);
    }

    // 3. Next year's opening equity comes from the closed year.
    public function test_next_years_opening_equity_comes_from_the_closed_years_stored_closing_equity(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000, '2025-06-01');
        $this->withdraw($owner, 500, '2025-11-01');

        $this->close(2025)->assertOk();

        $year2026 = $this->position('2026-01-01', '2026-12-31');

        $this->assertSame('4500.00', $year2026['equity']['opening_equity']);
        $this->assertFalse($year2026['is_closed']);
    }

    // 4. A closed year's report remains stable despite later, unrelated activity.
    public function test_closed_year_report_remains_stable_despite_later_activity(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000, '2025-06-01');

        $this->close(2025)->assertOk();

        $before = $this->position('2025-01-01', '2025-12-31');
        $this->assertTrue($before['is_closed']);

        // Unrelated activity in the still-open following year.
        $this->contribute($owner, 999, '2026-03-01');

        $after = $this->position('2025-01-01', '2025-12-31');

        $this->assertSame($before, $after);
    }

    // 5. Backdated transaction blocked after close.
    public function test_backdated_transaction_is_blocked_after_close(): void
    {
        $this->close(2025)->assertOk();

        $response = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 10,
            'description' => 'Backdated rent',
            'transaction_date' => '2025-06-15',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Financial year 2025 is closed',
            $response->json('errors.financial_year.0'),
        );
    }

    // 6. Backdated sale blocked after close.
    public function test_backdated_sale_is_blocked_after_close(): void
    {
        $this->stockRice();
        $customer = Person::create(['name' => 'FY Close Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->close(2025)->assertOk();

        $response = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => '2025-06-15',
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 30]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Financial year 2025 is closed', $response->json('errors.financial_year.0'));
        $this->assertSame(0, DB::table('sales')->count());
    }

    // 7. Backdated purchase blocked after close.
    public function test_backdated_purchase_is_blocked_after_close(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'FY-SUP', 'name' => 'FY Close Supplier', 'is_active' => true]);
        $this->close(2025)->assertOk();

        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => '2025-06-15',
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Financial year 2025 is closed', $response->json('errors.financial_year.0'));
        $this->assertSame(0, DB::table('purchases')->count());
    }

    // 8. Voiding a transaction from a closed year is blocked.
    public function test_voiding_a_sale_from_a_closed_year_is_blocked(): void
    {
        $this->stockRice();
        $customer = Person::create(['name' => 'FY Close Void Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => '2025-06-15',
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_price' => 30]],
        ])->assertCreated()->json('sale');

        $this->close(2025)->assertOk();

        $response = $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'Testing closed-year guard']);

        $response->assertStatus(422);
        $this->assertStringContainsString('Financial year 2025 is closed', $response->json('errors.financial_year.0'));
        $this->assertSame('posted', DB::table('sales')->where('id', $sale['id'])->value('status'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'financial_year_closed']);
    }

    // 9. Current/new-period transactions still allowed after a prior year is closed.
    public function test_current_period_transactions_still_allowed_after_a_prior_year_is_closed(): void
    {
        $this->close(2025)->assertOk();

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 15,
            'description' => 'Current period expense',
            'transaction_date' => '2026-01-05',
        ])->assertCreated();
    }

    // 10. Only Super Admin can close.
    public function test_only_super_admin_can_close_a_financial_year(): void
    {
        $this->actingAs($this->regularUser());

        $this->close(2025)->assertStatus(403);
        $this->assertDatabaseMissing('financial_year_closes', ['financial_year' => 2025]);
    }

    // 11. Only Super Admin can reopen.
    public function test_only_super_admin_can_reopen_a_financial_year(): void
    {
        $this->close(2025)->assertOk();

        $this->actingAs($this->regularUser());

        $this->reopen(2025)->assertStatus(403);
        $this->assertDatabaseHas('financial_year_closes', ['financial_year' => 2025, 'status' => 'closed']);
    }

    // 12. Only the latest closed financial year can be reopened.
    public function test_only_the_latest_closed_financial_year_can_be_reopened(): void
    {
        $this->close(2024)->assertOk();
        $this->close(2025)->assertOk();

        $blocked = $this->reopen(2024);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('2025', $blocked->json('errors.financial_year.0'));
        $this->assertDatabaseHas('financial_year_closes', ['financial_year' => 2024, 'status' => 'closed']);

        $this->reopen(2025)->assertOk();
        $this->assertDatabaseHas('financial_year_closes', ['financial_year' => 2025, 'status' => 'reopened']);
    }

    // 13. Reopening restores live calculation.
    public function test_reopening_restores_live_calculation(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 5000, '2025-06-01');
        $this->close(2025)->assertOk();

        $this->reopen(2025)->assertOk();

        $reopened = $this->position('2025-01-01', '2025-12-31');
        $this->assertFalse($reopened['is_closed']);

        // Backdated posting into the now-reopened year succeeds again.
        $this->withdraw($owner, 500, '2025-11-01');

        $updated = $this->position('2025-01-01', '2025-12-31');
        $this->assertSame('4500.00', $updated['equity']['closing_equity']);
    }

    // 14. Close/reopen audit entries.
    public function test_close_and_reopen_actions_are_recorded_in_audit_logs(): void
    {
        $this->close(2025)->assertOk();
        $this->reopen(2025)->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'financial_year_closed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'financial_year_reopened']);
    }

    // 15. Cannot close a year before its period_end_date has passed.
    public function test_cannot_close_a_year_before_its_period_has_ended(): void
    {
        $currentYear = (int) now()->format('Y');

        $response = $this->close($currentYear);

        $response->assertStatus(422);
        $this->assertStringContainsString('has not yet ended', $response->json('errors.financial_year.0'));
        $this->assertDatabaseMissing('financial_year_closes', ['financial_year' => $currentYear]);
    }

    // 16. Cannot close 2021 while 2020 (the earliest year with activity) is still open.
    public function test_cannot_close_a_later_year_while_an_earlier_year_with_activity_is_open(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 1000, '2020-03-01');
        $this->contribute($owner, 500, '2021-03-01');

        $response = $this->close(2021);

        $response->assertStatus(422);
        $message = $response->json('errors.financial_year.0');
        $this->assertStringContainsString('2020', $message);
        $this->assertStringContainsString('must be closed before 2021', $message);
        $this->assertDatabaseMissing('financial_year_closes', ['financial_year' => 2021]);
    }

    // 17 & 18. Closing in order: 2020 first (after its period ended and with
    // no earlier activity), then 2021 becomes closable.
    public function test_can_close_years_in_chronological_order(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 1000, '2020-03-01');
        $this->contribute($owner, 500, '2021-03-01');

        $this->close(2020)->assertOk();
        $this->assertDatabaseHas('financial_year_closes', ['financial_year' => 2020, 'status' => 'closed']);

        $this->close(2021)->assertOk();
        $this->assertDatabaseHas('financial_year_closes', ['financial_year' => 2021, 'status' => 'closed']);
    }

    // 19. Cannot reopen an earlier year while a later year is still closed
    // (regression check for the existing "latest closed year only" rule,
    // now reachable via chronological closing).
    public function test_cannot_reopen_an_earlier_year_while_a_later_year_is_still_closed(): void
    {
        $owner = $this->makeOwner('Aisha');
        $this->contribute($owner, 1000, '2020-03-01');
        $this->contribute($owner, 500, '2021-03-01');

        $this->close(2020)->assertOk();
        $this->close(2021)->assertOk();

        $blocked = $this->reopen(2020);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('2021', $blocked->json('errors.financial_year.0'));
        $this->assertDatabaseHas('financial_year_closes', ['financial_year' => 2020, 'status' => 'closed']);

        $this->reopen(2021)->assertOk();
        $this->reopen(2020)->assertOk();
    }
}

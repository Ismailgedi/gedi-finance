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
 * Phase 2: the four-role, Spatie-permission-backed authorization model
 * (see DatabaseSeeder::ROLE_PERMISSIONS and routes/api.php). Exercises the
 * real HTTP routes directly - never the frontend's own nav-hiding, which
 * is a convenience only - so every assertion here is proof of what the
 * backend itself enforces.
 */
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Product $rice;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->cash = Account::create([
            'name' => 'Role Test Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'Role Bag ' . uniqid(), 'abbreviation' => 'rbag' . uniqid()]);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Role Test Rice',
            'sku' => 'ROLE-RICE-' . uniqid(),
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'supplier_code' => 'ROLE-SUP-' . uniqid(),
            'name' => 'Role Test Supplier',
            'is_active' => true,
        ]);

        app(InventoryService::class)->record(
            $this->rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18,
        );
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // --- SUPER ADMIN: everything ---

    public function test_super_admin_can_access_every_major_area(): void
    {
        $this->actingAs($this->userWithRole('Super Admin'));

        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/sales')->assertOk();
        $this->getJson('/api/purchases')->assertOk();
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/inventory-adjustments')->assertOk();
        $this->getJson('/api/people')->assertOk();
        $this->getJson('/api/suppliers')->assertOk();
        $this->getJson('/api/accounts')->assertOk();
        $this->getJson('/api/transactions')->assertOk();
        $this->getJson('/api/reports/business-summary')->assertOk();
        $this->getJson('/api/reports/inventory')->assertOk();
        $this->getJson('/api/audit-logs')->assertOk();
        $this->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/admin/opening-balance')->assertOk();
    }

    // --- MANAGER ---

    public function test_manager_can_run_day_to_day_business(): void
    {
        $this->actingAs($this->userWithRole('Manager'));

        $this->getJson('/api/sales')->assertOk();
        $this->getJson('/api/purchases')->assertOk();
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/inventory-adjustments')->assertOk();
        $this->getJson('/api/people')->assertOk();
        $this->getJson('/api/suppliers')->assertOk();
        $this->getJson('/api/transactions')->assertOk();
        $this->getJson('/api/accounts')->assertOk();
        $this->getJson('/api/reports/business-summary')->assertOk();

        $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated();

        $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_cost' => 18]],
        ])->assertCreated();
    }

    public function test_manager_cannot_manage_users_or_roles_or_reach_super_admin_functions(): void
    {
        $this->actingAs($this->userWithRole('Manager'));

        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'Manager'])->assertStatus(403);
        $this->getJson('/api/audit-logs')->assertStatus(403);
        $this->getJson('/api/admin/opening-balance')->assertStatus(403);
        $this->postJson('/api/admin/financial-years/2026/close')->assertStatus(403);

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');
        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'test'])->assertStatus(403);
    }

    // --- FINANCE ---

    public function test_finance_can_access_financial_and_people_data(): void
    {
        $this->actingAs($this->userWithRole('Finance'));

        $this->getJson('/api/accounts')->assertOk();
        $this->getJson('/api/transactions')->assertOk();
        $this->getJson('/api/people')->assertOk();
        $this->getJson('/api/suppliers')->assertOk();
        $this->getJson('/api/sales')->assertOk();
        $this->getJson('/api/purchases')->assertOk();
        $this->getJson('/api/reports/business-summary')->assertOk();
        $this->getJson('/api/reports/sales')->assertOk();
        $this->getJson('/api/reports/purchases')->assertOk();

        // Loans & Debts is just a Transaction against a Person - Finance's
        // view_transactions/manage_transactions is what actually gates it,
        // there is no separate "loans" permission (see DatabaseSeeder's
        // own comment on ROLE_PERMISSIONS).
        $borrower = Person::create(['name' => 'Finance Loan Borrower', 'is_active' => true, 'roles' => []]);
        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $borrower->id,
            'account_id' => $this->cash->id,
            'amount' => 200,
            'description' => 'Loan from Finance',
        ])->assertCreated();
    }

    public function test_finance_cannot_create_sales_or_purchases_or_manage_users(): void
    {
        $this->actingAs($this->userWithRole('Finance'));

        $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertStatus(403);

        $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_cost' => 18]],
        ])->assertStatus(403);

        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->getJson('/api/audit-logs')->assertStatus(403);
    }

    public function test_finance_cannot_access_technical_administration(): void
    {
        $this->actingAs($this->userWithRole('Finance'));

        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x2@example.test', 'role' => 'Finance'])->assertStatus(403);
        $this->getJson('/api/admin/opening-balance')->assertStatus(403);
    }

    // --- SALES & INVENTORY ---

    public function test_sales_and_inventory_can_sell_and_manage_stock(): void
    {
        $this->actingAs($this->userWithRole('Sales & Inventory'));

        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/inventory-adjustments')->assertOk();
        $this->getJson('/api/reports/inventory')->assertOk();
        $this->getJson('/api/people')->assertOk();

        $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated();

        // Can create a customer inline from the Sales form.
        $this->postJson('/api/people', [
            'name' => 'S&I Inline Customer',
            'is_customer' => true,
            'roles' => ['customer'],
        ])->assertCreated();
    }

    public function test_sales_and_inventory_cannot_see_financial_data(): void
    {
        $this->actingAs($this->userWithRole('Sales & Inventory'));

        // /api/accounts is reachable (create_sales needs the account list
        // to complete a cash sale - see AccountController::index()'s own
        // doc comment) but must never carry balances to this role.
        $accounts = $this->getJson('/api/accounts')->assertOk()->json();
        foreach ($accounts as $account) {
            $this->assertArrayNotHasKey('current_balance', $account);
        }

        $this->getJson('/api/transactions')->assertStatus(403);
        $this->getJson('/api/reports/business-summary')->assertStatus(403);
        $this->getJson('/api/reports/sales')->assertStatus(403);
        $this->getJson('/api/reports/purchases')->assertStatus(403);
        $this->getJson('/api/dashboard')->assertStatus(403);
    }

    public function test_sales_and_inventory_cannot_manage_users_or_access_admin(): void
    {
        $this->actingAs($this->userWithRole('Sales & Inventory'));

        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->getJson('/api/audit-logs')->assertStatus(403);
        $this->getJson('/api/admin/opening-balance')->assertStatus(403);
    }

    public function test_sales_and_inventory_cannot_create_purchases_or_manage_suppliers(): void
    {
        $this->actingAs($this->userWithRole('Sales & Inventory'));

        $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_cost' => 18]],
        ])->assertStatus(403);

        $this->getJson('/api/suppliers')->assertStatus(403);

        // Cannot flip is_supplier=true on the shared /people endpoint
        // either, even though manage_customers alone makes the route
        // itself reachable (see PersonController::assertCanSetRoleFlags).
        $this->postJson('/api/people', [
            'name' => 'S&I Attempted Supplier',
            'is_supplier' => true,
            'roles' => ['supplier'],
        ])->assertStatus(422);
    }

    public function test_sales_and_inventory_cannot_void_a_sale(): void
    {
        $this->actingAs($this->userWithRole('Sales & Inventory'));

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'test'])->assertStatus(403);
    }

    // --- Unauthenticated ---

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/sales')->assertUnauthorized();
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }
}

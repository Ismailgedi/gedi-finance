<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The financial audit trail + permissions feature: every transaction type
 * created through TransactionService::create() now writes one
 * 'transaction_created' AuditLog entry (see TransactionService::create()),
 * sale/purchase voids require a reason and it's included in the
 * 'transaction_voided' entry, GET /api/audit-logs is Super-Admin-only and
 * read-only, and voiding a sale/purchase plus recording owner capital
 * movements are now restricted to Super Admin while every other day-to-day
 * action (sales, purchases, payments, expenses, loans, inventory
 * adjustments) stays open to an ordinary User.
 */
class AuditTrailAndPermissionsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Product $rice;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        \Database\Seeders\DatabaseSeeder::seedRolesAndPermissions();

        $this->cash = Account::create([
            'name' => 'Audit Test Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'Audit Bag ' . uniqid(), 'abbreviation' => 'abag' . uniqid()]);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Audit Test Rice',
            'sku' => 'AUDIT-RICE-' . uniqid(),
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'supplier_code' => 'AUDIT-SUP-' . uniqid(),
            'name' => 'Audit Test Supplier',
            'is_active' => true,
        ]);

        app(InventoryService::class)->record(
            $this->rice, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 18,
        );
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        return $admin;
    }

    private function regularUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        return $user;
    }

    // --- AUDIT: transaction creation ---

    public function test_transaction_creation_creates_an_audit_log_entry(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $before = AuditLog::query()->where('action', 'transaction_created')->count();

        $transaction = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 50,
            'description' => 'Office supplies',
        ])->assertCreated()->json('transaction');

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'transaction_created')->count());

        $entry = AuditLog::query()->where('action', 'transaction_created')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('App\\Models\\Transaction', $entry->auditable_type);
        $this->assertSame((string) $transaction['id'], $entry->auditable_id);
    }

    public function test_audit_entry_contains_the_correct_user(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 20,
            'description' => 'Test expense for user attribution',
        ])->assertCreated();

        $entry = AuditLog::query()->where('action', 'transaction_created')->latest('id')->first();
        $this->assertSame($admin->id, $entry->user_id);
    }

    public function test_audit_entry_contains_correct_transaction_information(): void
    {
        $this->actingAs($this->superAdmin());

        $transaction = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 75.50,
            'description' => 'Delivery fuel',
        ])->assertCreated()->json('transaction');

        $entry = AuditLog::query()->where('action', 'transaction_created')->latest('id')->first();
        $this->assertSame($transaction['id'], $entry->new_values['transaction_id']);
        $this->assertSame('expense', $entry->new_values['type']);
        $this->assertSame('75.50', $entry->new_values['amount']);
        $this->assertSame($this->cash->id, $entry->new_values['account_id']);
        $this->assertSame('Delivery fuel', $entry->new_values['description']);
    }

    public function test_sale_creation_is_audited(): void
    {
        $this->actingAs($this->regularUser());

        $before = AuditLog::query()->where('action', 'transaction_created')->count();

        $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'transaction_created')->count());
    }

    public function test_purchase_creation_is_audited(): void
    {
        $this->actingAs($this->regularUser());

        $before = AuditLog::query()->where('action', 'transaction_created')->count();

        // Unpaid, so exactly one 'purchase' Transaction row is created -
        // a paid-in-full purchase would also create a linked
        // supplier_payment row, each correctly getting its own entry.
        $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'transaction_created')->count());
    }

    public function test_payment_creation_is_audited(): void
    {
        $this->actingAs($this->regularUser());

        $customer = Person::create(['name' => 'Audit Payment Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $before = AuditLog::query()->where('action', 'transaction_created')->count();

        $this->postJson("/api/sales/{$sale['id']}/payments", [
            'amount' => 27,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'transaction_created')->count());
    }

    public function test_expense_creation_is_audited(): void
    {
        $this->actingAs($this->regularUser());

        $before = AuditLog::query()->where('action', 'transaction_created')->count();

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 15,
            'description' => 'Stationery',
        ])->assertCreated();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'transaction_created')->count());
    }

    public function test_loan_creation_is_audited(): void
    {
        $this->actingAs($this->regularUser());

        $borrower = Person::create(['name' => 'Audit Loan Borrower', 'is_active' => true, 'roles' => []]);
        $before = AuditLog::query()->where('action', 'transaction_created')->count();

        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $borrower->id,
            'account_id' => $this->cash->id,
            'amount' => 200,
            'description' => 'Loan to a business contact',
        ])->assertCreated();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'transaction_created')->count());
    }

    public function test_owner_capital_creation_is_audited_and_captures_the_owner_and_recorder(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $owner = Person::create(['name' => 'Audit Owner', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);

        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution',
            'person_id' => $owner->id,
            'account_id' => $this->cash->id,
            'amount' => 1000,
            'description' => 'Capital injection',
        ])->assertCreated();

        $entry = AuditLog::query()->where('action', 'transaction_created')->latest('id')->first();
        $this->assertSame('owner_contribution', $entry->new_values['type']);
        // The owner the movement belongs to and the Super Admin who
        // recorded it must both be visible - never merged into one field.
        $this->assertSame($owner->id, $entry->new_values['person_id']);
        $this->assertSame($admin->id, $entry->new_values['created_by']);
        $this->assertSame($admin->id, $entry->user_id);
    }

    public function test_no_duplicate_audit_record_is_created_for_one_transaction(): void
    {
        $this->actingAs($this->superAdmin());

        $transaction = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 10,
            'description' => 'Single audit entry check',
        ])->assertCreated()->json('transaction');

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'transaction_created')
                ->where('auditable_type', 'App\\Models\\Transaction')
                ->where('auditable_id', (string) $transaction['id'])
                ->count(),
        );
    }

    // --- VOID ---

    public function test_void_reason_is_required(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->postJson("/api/sales/{$sale['id']}/void", [])->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'ok'])->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_void_reason_appears_in_the_audit_log(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'Wrong customer selected'])->assertOk();

        $entry = AuditLog::query()->where('action', 'transaction_voided')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('Wrong customer selected', $entry->new_values['reason']);
    }

    public function test_sale_void_is_audited(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $before = AuditLog::query()->where('action', 'transaction_voided')->count();

        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'Testing sale void audit'])->assertOk();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'transaction_voided')->count());
    }

    public function test_purchase_void_is_audited(): void
    {
        $this->actingAs($this->superAdmin());

        // Unpaid, so voiding only reverses the one linked 'purchase'
        // transaction (see the creation-audit test above for why).
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $before = AuditLog::query()->where('action', 'transaction_voided')->count();

        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'Testing purchase void audit'])->assertOk();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'transaction_voided')->count());
    }

    // --- AUDIT ACCESS ---

    public function test_super_admin_can_view_audit_logs(): void
    {
        $this->actingAs($this->superAdmin());

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 5,
            'description' => 'Audit access check',
        ])->assertCreated();

        $this->getJson('/api/audit-logs')->assertOk()->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);
    }

    public function test_ordinary_user_cannot_view_audit_logs(): void
    {
        $this->actingAs($this->regularUser());

        $this->getJson('/api/audit-logs')->assertStatus(403);
    }

    public function test_audit_log_filters_by_user_and_action(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 5,
            'description' => 'Filter check one',
        ])->assertCreated();

        $filteredByUser = $this->getJson("/api/audit-logs?user_id={$admin->id}")->assertOk()->json('data');
        $this->assertNotEmpty($filteredByUser);
        foreach ($filteredByUser as $row) {
            $this->assertSame($admin->id, $row['user']['id']);
        }

        $filteredByAction = $this->getJson('/api/audit-logs?action=transaction_created')->assertOk()->json('data');
        $this->assertNotEmpty($filteredByAction);
        foreach ($filteredByAction as $row) {
            $this->assertSame('transaction_created', $row['action']);
        }

        $filteredByDate = $this->getJson('/api/audit-logs?from=' . now()->addDay()->toDateString())->assertOk()->json('data');
        $this->assertEmpty($filteredByDate, 'A from-date in the future should match nothing.');
    }

    public function test_audit_logs_are_read_only(): void
    {
        $this->actingAs($this->superAdmin());

        // No store/update/destroy route exists for this resource at all -
        // POST hits the same URI as the GET route (405, method not
        // allowed), PUT/DELETE with an id match no route at all (404).
        $this->assertContains($this->postJson('/api/audit-logs', ['action' => 'fake'])->status(), [404, 405]);
        $this->assertContains($this->putJson('/api/audit-logs/1', ['action' => 'fake'])->status(), [404, 405]);
        $this->assertContains($this->deleteJson('/api/audit-logs/1')->status(), [404, 405]);
    }

    // --- PERMISSIONS ---

    public function test_ordinary_user_cannot_void_a_sale(): void
    {
        $this->actingAs($this->regularUser());

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');

        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'test'])->assertStatus(403);
        $this->assertSame('posted', \App\Models\Sale::find($sale['id'])->status);
    }

    public function test_ordinary_user_cannot_void_a_purchase(): void
    {
        $this->actingAs($this->regularUser());

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 180,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');

        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'test'])->assertStatus(403);
        $this->assertSame('posted', \App\Models\Purchase::find($purchase['id'])->status);
    }

    public function test_ordinary_user_cannot_create_owner_contribution(): void
    {
        $this->actingAs($this->regularUser());

        $owner = Person::create(['name' => 'Perm Owner Contribution', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);

        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution',
            'person_id' => $owner->id,
            'account_id' => $this->cash->id,
            'amount' => 500,
            'description' => 'Blocked contribution',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('transactions', ['type' => 'owner_contribution', 'amount' => 500]);
    }

    public function test_ordinary_user_cannot_create_owner_withdrawal(): void
    {
        $this->actingAs($this->regularUser());

        $owner = Person::create(['name' => 'Perm Owner Withdrawal', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);

        $this->postJson('/api/transactions', [
            'type' => 'owner_withdrawal',
            'person_id' => $owner->id,
            'account_id' => $this->cash->id,
            'amount' => 100,
            'description' => 'Blocked withdrawal',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('transactions', ['type' => 'owner_withdrawal', 'amount' => 100]);
    }

    public function test_super_admin_can_perform_all_four_restricted_actions(): void
    {
        $this->actingAs($this->superAdmin());

        $owner = Person::create(['name' => 'Perm Owner Admin', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);

        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution',
            'person_id' => $owner->id,
            'account_id' => $this->cash->id,
            'amount' => 500,
            'description' => 'Admin contribution',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'owner_withdrawal',
            'person_id' => $owner->id,
            'account_id' => $this->cash->id,
            'amount' => 100,
            'description' => 'Admin withdrawal',
        ])->assertCreated();

        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 1, 'unit_price' => 27]],
        ])->assertCreated()->json('sale');
        $this->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'Admin void'])->assertOk();

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 180,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 18]],
        ])->assertCreated()->json('purchase');
        $this->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'Admin void'])->assertOk();
    }

    // --- TRANSACTION HISTORY ---

    public function test_transaction_history_includes_the_creator(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 12,
            'description' => 'Creator visibility check',
        ])->assertCreated();

        $list = $this->getJson('/api/transactions')->assertOk()->json();
        $row = collect($list['data'])->firstWhere('description', 'Creator visibility check');

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row['creator']['id']);
        $this->assertSame($admin->name, $row['creator']['name']);
    }

    public function test_existing_transaction_history_still_works(): void
    {
        $this->actingAs($this->regularUser());

        $this->postJson('/api/transactions', [
            'type' => 'income',
            'account_id' => $this->cash->id,
            'amount' => 30,
            'description' => 'Existing history regression check',
        ])->assertCreated();

        $this->getJson('/api/transactions')->assertOk()->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);
    }
}

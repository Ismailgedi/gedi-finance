<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The one-time Opening Balance / Initial Business Position - how a
 * pre-existing business enters its starting assets/liabilities/equity
 * without fabricating historical sales, purchases, or loans. See
 * OpeningBalanceService's own doc comment for the DRAFT -> LOCKED state
 * machine and exactly how reopening reverses a locked record.
 */
class OpeningBalanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private InventoryService $inventory;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        \Database\Seeders\DatabaseSeeder::seedRolesAndPermissions();

        $this->balances = app(BalanceService::class);
        $this->inventory = app(InventoryService::class);

        $this->cash = Account::create([
            'name' => 'OB Test Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
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

    private function product(): Product
    {
        $bag = Unit::create(['name' => 'OB Bag ' . uniqid(), 'abbreviation' => 'obb' . uniqid()]);

        return Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'OB Test Rice',
            'sku' => 'OB-RICE-' . uniqid(),
            'default_cost_price' => 20,
            'default_selling_price' => 30,
            'is_active' => true,
        ]);
    }

    private function saveDraft(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'as_of_date' => '2026-01-01',
            'opening_equity' => 0,
            'items' => [],
        ], $overrides);

        return $this->postJson('/api/admin/opening-balance', $payload);
    }

    // --- Account opening balances ---

    public function test_account_opening_balances_are_integrated_not_double_counted(): void
    {
        $this->actingAs($this->superAdmin());

        $this->saveDraft([
            'opening_equity' => 10000,
            'accounts' => [['account_id' => $this->cash->id, 'opening_balance' => 10000]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->cash->refresh();
        $this->assertSame('10000.00', number_format((float) $this->cash->opening_balance, 2, '.', ''));

        // No transaction was created for the account itself - only the
        // existing accounts.opening_balance column moved.
        $this->assertSame(0, \App\Models\Transaction::query()->where('account_id', $this->cash->id)->count());

        $position = $this->getJson('/api/reports/business-position?range=custom&from=2026-01-02&to=2026-01-02')->assertOk()->json();
        $this->assertSame('10000.00', $position['assets']['cash_and_bank']);
        $this->assertSame('10000.00', $position['equity']['opening_equity']);
        $this->assertTrue($position['check']['matches']);
    }

    // --- Opening customer receivable ---

    public function test_opening_customer_receivable(): void
    {
        $this->actingAs($this->superAdmin());
        $customer = Person::create(['name' => 'OB Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->saveDraft([
            'opening_equity' => 5000,
            'items' => [['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 5000]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->assertSame('5000.00', $this->balances->customerReceivableBalance($customer->fresh()));

        $transaction = \App\Models\Transaction::query()->where('person_id', $customer->id)->first();
        $this->assertSame('opening_balance_receivable', $transaction->type->value);
        $this->assertSame('0.00', $transaction->account_balance_effect, 'No cash movement.');
        $this->assertNull($transaction->sale_id, 'No fake sale.');
    }

    // --- Opening other receivable ---

    public function test_opening_other_receivable(): void
    {
        $this->actingAs($this->superAdmin());
        $person = Person::create(['name' => 'OB Other Receivable Person', 'is_active' => true, 'roles' => []]);

        $this->saveDraft([
            'opening_equity' => 300,
            'items' => [['category' => 'other_receivable', 'person_id' => $person->id, 'amount' => 300]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $transaction = \App\Models\Transaction::query()->where('person_id', $person->id)->first();
        $this->assertSame('opening_balance_other_receivable', $transaction->type->value);
        $this->assertSame('300.00', $transaction->person_balance_effect);
        $this->assertSame('0.00', $transaction->account_balance_effect);

        // Distinct from a loan and from a normal debt_created - never
        // counted toward loans_given or Other Income.
        $profit = $this->getJson('/api/reports/profit?range=custom&from=2026-01-01&to=2026-01-01')->assertOk();
        $this->assertSame('0.00', $profit->json('other_income'));
    }

    // --- Opening supplier payable ---

    public function test_opening_supplier_payable(): void
    {
        $this->actingAs($this->superAdmin());
        $supplier = Supplier::create(['supplier_code' => 'OB-SUP-' . uniqid(), 'name' => 'OB Supplier', 'is_active' => true]);

        $this->saveDraft([
            'opening_equity' => -800,
            'items' => [['category' => 'payable', 'supplier_id' => $supplier->id, 'amount' => 800]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->assertSame('800.00', $this->balances->supplierBalance($supplier->fresh()));

        $transaction = \App\Models\Transaction::query()->where('supplier_id', $supplier->id)->first();
        $this->assertSame('opening_balance_payable', $transaction->type->value);
        $this->assertNull($transaction->purchase_id, 'No fake purchase.');
        $this->assertSame('0.00', $transaction->account_balance_effect);
    }

    // --- Opening loan given / received (signs verified against LoanGiven/LoanReceived) ---

    public function test_opening_loan_given(): void
    {
        $this->actingAs($this->superAdmin());
        $borrower = Person::create(['name' => 'OB Borrower', 'is_active' => true, 'roles' => []]);

        $this->saveDraft([
            'opening_equity' => 1000,
            'items' => [['category' => 'loan_given', 'person_id' => $borrower->id, 'amount' => 1000]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $transaction = \App\Models\Transaction::query()->where('person_id', $borrower->id)->first();
        $this->assertSame('opening_balance_loan_given', $transaction->type->value);
        // Mirrors LoanGiven's person effect sign (+amount, they owe us) -
        // never the account effect (no fresh cash left).
        $this->assertSame('1000.00', $transaction->person_balance_effect);
        $this->assertSame('0.00', $transaction->account_balance_effect);

        $position = $this->getJson('/api/reports/business-position?range=custom&from=2026-01-02&to=2026-01-02')->assertOk();
        $this->assertSame('1000.00', $position->json('assets.loans_given'));
    }

    public function test_opening_loan_received(): void
    {
        $this->actingAs($this->superAdmin());
        $lender = Person::create(['name' => 'OB Lender', 'is_active' => true, 'roles' => []]);

        $this->saveDraft([
            'opening_equity' => -1500,
            'items' => [['category' => 'loan_received', 'person_id' => $lender->id, 'amount' => 1500]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $transaction = \App\Models\Transaction::query()->where('person_id', $lender->id)->first();
        $this->assertSame('opening_balance_loan_received', $transaction->type->value);
        // Mirrors LoanReceived's person effect sign (-amount, we owe them) -
        // never the account effect (no fresh cash came in).
        $this->assertSame('-1500.00', $transaction->person_balance_effect);
        $this->assertSame('0.00', $transaction->account_balance_effect);

        $position = $this->getJson('/api/reports/business-position?range=custom&from=2026-01-02&to=2026-01-02')->assertOk();
        $this->assertSame('1500.00', $position->json('liabilities.loans_received'));
    }

    // --- Opening inventory ---

    public function test_opening_inventory_establishes_weighted_average_cost(): void
    {
        $this->actingAs($this->superAdmin());
        $product = $this->product();

        $this->saveDraft([
            'opening_equity' => 1000,
            'items' => [['category' => 'inventory', 'product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 20]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->assertSame('50.0000', $this->inventory->currentStock($product->fresh()));
        $this->assertSame('20.0000', $this->inventory->weightedAverageCost($product->fresh()));

        $movement = \Illuminate\Support\Facades\DB::table('inventory_movements')
            ->where('reference_type', 'opening_balance')
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame('opening_balance', $movement->movement_type);
        // No fake purchase/supplier payable was created for it.
        $this->assertSame(0, \App\Models\Transaction::query()->where('purchase_id', '!=', null)->count());
    }

    // --- Opening owner capital allocations ---

    public function test_opening_owner_capital_allocations_must_sum_to_opening_equity(): void
    {
        $this->actingAs($this->superAdmin());
        $ownerA = Person::create(['name' => 'Owner A', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);
        $ownerB = Person::create(['name' => 'Owner B', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);

        $this->saveDraft([
            'opening_equity' => 20000,
            'accounts' => [['account_id' => $this->cash->id, 'opening_balance' => 20000]],
            'items' => [
                ['category' => 'capital', 'person_id' => $ownerA->id, 'amount' => 12000],
                ['category' => 'capital', 'person_id' => $ownerB->id, 'amount' => 8000],
            ],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $txA = \App\Models\Transaction::query()->where('person_id', $ownerA->id)->first();
        $txB = \App\Models\Transaction::query()->where('person_id', $ownerB->id)->first();
        $this->assertSame('opening_balance_capital', $txA->type->value);
        $this->assertSame('12000.00', $txA->amount);
        $this->assertSame('8000.00', $txB->amount);
        // Never leaks into personBalance() - same as owner_contribution.
        $this->assertSame('0.00', $txA->person_balance_effect);
        $this->assertSame('0.00', $this->balances->personBalance($ownerA->fresh()));
    }

    public function test_opening_owner_capital_allocations_not_summing_to_equity_is_blocked(): void
    {
        $this->actingAs($this->superAdmin());
        $owner = Person::create(['name' => 'Owner Mismatch', 'is_active' => true, 'is_owner' => true, 'roles' => ['owner']]);

        $this->saveDraft([
            'opening_equity' => 20000,
            'accounts' => [['account_id' => $this->cash->id, 'opening_balance' => 20000]],
            'items' => [
                ['category' => 'capital', 'person_id' => $owner->id, 'amount' => 15000],
            ],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertStatus(422)
            ->assertJsonValidationErrors('opening_equity');
    }

    // --- Reconciliation ---

    public function test_opening_reconciliation_matches_assets_minus_liabilities(): void
    {
        $this->actingAs($this->superAdmin());
        $customer = Person::create(['name' => 'Recon Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $supplier = Supplier::create(['supplier_code' => 'RECON-SUP-' . uniqid(), 'name' => 'Recon Supplier', 'is_active' => true]);
        $product = $this->product();

        // Assets: 5000 cash + 2000 receivable + 1000 inventory (50*20) = 8000
        // Liabilities: 3000 payable
        // Equity: 5000
        $this->saveDraft([
            'opening_equity' => 5000,
            'accounts' => [['account_id' => $this->cash->id, 'opening_balance' => 5000]],
            'items' => [
                ['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 2000],
                ['category' => 'payable', 'supplier_id' => $supplier->id, 'amount' => 3000],
                ['category' => 'inventory', 'product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 20],
            ],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $position = $this->getJson('/api/reports/business-position?range=custom&from=2026-01-02&to=2026-01-02')->assertOk();
        $this->assertSame('8000.00', $position->json('assets.total'));
        $this->assertSame('3000.00', $position->json('liabilities.total'));
        $this->assertSame('5000.00', $position->json('equity.opening_equity'));
        $this->assertTrue($position->json('check.matches'));
    }

    // --- Unbalanced opening blocked ---

    public function test_unbalanced_opening_balance_is_blocked_from_locking(): void
    {
        $this->actingAs($this->superAdmin());
        $customer = Person::create(['name' => 'Unbalanced Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->saveDraft([
            'opening_equity' => 999, // wrong - should be 2000
            'items' => [
                ['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 2000],
            ],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertStatus(422)
            ->assertJsonValidationErrors('opening_equity');

        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()), 'Nothing must be posted when the lock is refused.');
    }

    // --- Duplicate opening balance blocked ---

    public function test_duplicate_opening_balance_is_blocked(): void
    {
        $this->actingAs($this->superAdmin());

        $this->saveDraft(['opening_equity' => 0])->assertOk();
        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        // A second attempt must be refused - correction goes through
        // reopen, never a second independent record.
        $this->saveDraft(['opening_equity' => 500])->assertStatus(422)
            ->assertJsonValidationErrors('opening_balance');

        $this->assertSame(1, \App\Models\OpeningBalance::query()->count());
    }

    // --- Early/historical transaction date blocked ---

    public function test_lock_is_blocked_when_a_posted_transaction_predates_the_opening_date(): void
    {
        $this->actingAs($this->superAdmin());

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 10,
            'description' => 'An expense before the opening date',
            'transaction_date' => '2025-12-15',
        ])->assertCreated();

        $this->saveDraft(['as_of_date' => '2026-01-01', 'opening_equity' => 0])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertStatus(422)
            ->assertJsonValidationErrors('as_of_date');
    }

    public function test_no_transaction_may_be_dated_on_or_before_a_locked_opening_balance(): void
    {
        $this->actingAs($this->superAdmin());

        $this->saveDraft(['as_of_date' => '2026-01-01', 'opening_equity' => 0])->assertOk();
        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $response = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 10,
            'description' => 'Backdated expense',
            'transaction_date' => '2026-01-01',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('transaction_date');

        // A date safely after the opening balance still works normally.
        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 10,
            'description' => 'Normal expense',
            'transaction_date' => '2026-01-02',
        ])->assertCreated();
    }

    // --- Lock/reopen permissions ---

    public function test_ordinary_user_cannot_access_opening_balance_endpoints(): void
    {
        $this->actingAs($this->regularUser());

        $this->getJson('/api/admin/opening-balance')->assertStatus(403);
        $this->postJson('/api/admin/opening-balance', ['as_of_date' => '2026-01-01', 'opening_equity' => 0])->assertStatus(403);
        $this->postJson('/api/admin/opening-balance/lock')->assertStatus(403);
        $this->postJson('/api/admin/opening-balance/reopen')->assertStatus(403);
    }

    // --- Reopen -> correct -> relock ---

    public function test_reopen_reverses_posted_items_and_allows_a_correction(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $customer = Person::create(['name' => 'Reopen Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $product = $this->product();

        $this->saveDraft([
            'opening_equity' => 3000,
            'items' => [
                ['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 2000],
                ['category' => 'inventory', 'product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 20],
            ],
        ])->assertOk();
        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->assertSame('2000.00', $this->balances->customerReceivableBalance($customer->fresh()));
        $this->assertSame('50.0000', $this->inventory->currentStock($product->fresh()));

        $this->postJson('/api/admin/opening-balance/reopen')->assertOk()
            ->assertJsonPath('opening_balance.status', 'reopened');

        // Reversed - the customer's balance and the product's stock go
        // back to exactly what they were before the original lock.
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()));
        $this->assertSame('0.0000', $this->inventory->currentStock($product->fresh()));

        // Correct and relock with a different figure.
        $this->saveDraft([
            'opening_equity' => 2500,
            'items' => [
                ['category' => 'receivable', 'person_id' => $customer->id, 'amount' => 2500],
            ],
        ])->assertOk();
        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->assertSame('2500.00', $this->balances->customerReceivableBalance($customer->fresh()));
        $this->assertSame(1, \App\Models\OpeningBalance::query()->count(), 'Still only one record, corrected in place.');
    }

    // --- Audit trail ---

    public function test_opening_balance_lifecycle_is_fully_audited(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->saveDraft(['opening_equity' => 0])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'opening_balance_created')->count());

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();
        $lockEntry = AuditLog::query()->where('action', 'opening_balance_locked')->latest('id')->first();
        $this->assertNotNull($lockEntry);
        $this->assertSame($admin->id, $lockEntry->user_id);
        $this->assertSame('2026-01-01', $lockEntry->new_values['as_of_date']);

        $this->postJson('/api/admin/opening-balance/reopen')->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'opening_balance_reopened')->count());
    }
}

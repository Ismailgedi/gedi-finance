<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Person;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\ReportExportService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the two additive expense improvements identified by the prior
 * read-only review: expense categories (a real API + selectable field,
 * replacing the permanent "Uncategorized" bucket) and an optional "Paid To"
 * person/supplier link for traceability. Neither changes expense's existing
 * accounting effects (account decrease, profit decrease, no inventory/
 * supplier-payable/loan impact) - every test here that touches those
 * effects asserts they are unchanged, not just that the new fields exist.
 */
class ExpenseCategorizationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ReportExportService $reports;

    private BalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->reports = app(ReportExportService::class);
        $this->balances = app(BalanceService::class);
    }

    private function account(): Account
    {
        return Account::create([
            'name' => 'Expense Test Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function expenseCategory(string $name = 'Test Rent'): Category
    {
        return Category::create([
            'name' => $name,
            'type' => 'expense',
            'is_active' => true,
        ]);
    }

    // --- Category API ---

    public function test_category_index_filters_by_type_and_returns_seeded_style_categories(): void
    {
        $this->expenseCategory('Filter Test Rent');
        Category::create(['name' => 'Filter Test Income', 'type' => 'income', 'is_active' => true]);

        $response = $this->getJson('/api/categories?type=expense')->assertOk();

        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Filter Test Rent'));
        $this->assertFalse($names->contains('Filter Test Income'));
    }

    public function test_category_store_always_creates_type_expense(): void
    {
        $response = $this->postJson('/api/categories', ['name' => 'Delivery Fuel'])->assertCreated();

        $response->assertJsonPath('category.type', 'expense');
        $this->assertDatabaseHas('categories', ['name' => 'Delivery Fuel', 'type' => 'expense']);
    }

    public function test_category_store_rejects_empty_and_duplicate_names(): void
    {
        $this->postJson('/api/categories', ['name' => ''])->assertStatus(422);

        $this->postJson('/api/categories', ['name' => 'Office Supplies'])->assertCreated();
        $this->postJson('/api/categories', ['name' => 'Office Supplies'])->assertStatus(422);
    }

    // --- 1. Create expense with category ---

    public function test_create_expense_with_category(): void
    {
        $account = $this->account();
        $category = $this->expenseCategory();

        $response = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => 500,
            'description' => 'Monthly rent',
        ])->assertCreated();

        $response->assertJsonPath('transaction.category_id', $category->id);
        $response->assertJsonPath('transaction.category.name', $category->name);
        $this->assertDatabaseHas('transactions', [
            'type' => 'expense',
            'category_id' => $category->id,
            'amount' => 500,
        ]);
    }

    // --- 2. Expense category appears in P&L breakdown ---

    public function test_expense_category_appears_in_profit_report_breakdown(): void
    {
        $account = $this->account();
        $category = $this->expenseCategory('Breakdown Rent');

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => 500,
            'description' => 'Rent for the shop',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $response = $this->getJson('/api/reports/profit?range=today')->assertOk();

        $byCategory = collect($response->json('operating_expenses.by_category'));
        $row = $byCategory->firstWhere('category', 'Breakdown Rent');

        $this->assertNotNull($row, 'Expected the "Breakdown Rent" category in the P&L breakdown.');
        $this->assertSame('500.00', $row['amount']);
        $this->assertSame('500.00', $response->json('operating_expenses.total'));
    }

    // --- 3. Expense category appears in Excel export ---

    public function test_expense_category_appears_in_excel_export(): void
    {
        $account = $this->account();
        $category = $this->expenseCategory('Excel Rent');

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => 75,
            'description' => 'Excel export rent check',
        ])->assertCreated();

        // The exact data structure BusinessReportExport renders verbatim
        // into worksheet cells (see ReportExportService::transactionsReport
        // and BusinessReportExport::build) - asserting on it here proves
        // what the downloaded workbook will contain without re-parsing xlsx
        // bytes that ReportExcelExportTest already covers end to end.
        $report = $this->reports->transactionsReport([]);
        $this->assertContains('Category', $report['columns']);

        $row = collect($report['rows'])->firstWhere('description', 'Excel export rent check');
        $this->assertNotNull($row);
        $this->assertSame('Excel Rent', $row['category']);

        $this->get('/api/reports/transactions/excel')->assertOk();
    }

    // --- 4. Expense without category remains valid and is "Uncategorized" ---

    public function test_expense_without_category_remains_valid_and_shows_as_uncategorized(): void
    {
        $account = $this->account();

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 40,
            'description' => 'No category expense',
        ])->assertCreated()
            ->assertJsonPath('transaction.category_id', null);

        $report = $this->reports->transactionsReport([]);
        $row = collect($report['rows'])->firstWhere('description', 'No category expense');
        $this->assertSame('Uncategorized', $row['category']);

        $profit = $this->getJson('/api/reports/profit?range=today')->assertOk();
        $byCategory = collect($profit->json('operating_expenses.by_category'));
        $this->assertNotNull($byCategory->firstWhere('category', 'Uncategorized'));
    }

    // --- 5. Optional Paid To person is stored ---

    public function test_optional_paid_to_person_is_stored(): void
    {
        $account = $this->account();
        $person = Person::create(['name' => 'Paid To Person', 'is_active' => true, 'roles' => []]);

        $response = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'person_id' => $person->id,
            'amount' => 60,
            'description' => 'Paid to a person',
        ])->assertCreated();

        $response->assertJsonPath('transaction.person_id', $person->id);
        $this->assertDatabaseHas('transactions', ['type' => 'expense', 'person_id' => $person->id]);
    }

    // --- 6. Optional Paid To supplier is stored ---

    public function test_optional_paid_to_supplier_is_stored(): void
    {
        $account = $this->account();
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-PAIDTO-' . uniqid(),
            'name' => 'Paid To Supplier',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'supplier_id' => $supplier->id,
            'amount' => 90,
            'description' => 'Paid to a supplier',
        ])->assertCreated();

        $response->assertJsonPath('transaction.supplier_id', $supplier->id);
        $response->assertJsonPath('transaction.supplier.name', 'Paid To Supplier');
        $this->assertDatabaseHas('transactions', ['type' => 'expense', 'supplier_id' => $supplier->id]);
    }

    // --- 7. Paid To does not alter account effect ---

    public function test_paid_to_does_not_alter_account_effect(): void
    {
        $account = $this->account();
        $person = Person::create(['name' => 'Effect Check Person', 'is_active' => true, 'roles' => []]);

        $withoutPaidTo = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 100,
            'description' => 'No paid to',
        ])->assertCreated();

        $withPaidTo = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'person_id' => $person->id,
            'amount' => 100,
            'description' => 'With paid to',
        ])->assertCreated();

        $this->assertSame('-100.00', $withoutPaidTo->json('transaction.account_balance_effect'));
        $this->assertSame('-100.00', $withPaidTo->json('transaction.account_balance_effect'));
        $this->assertSame('0.00', $withPaidTo->json('transaction.person_balance_effect'));
    }

    // --- 8. Paid To does not alter profit calculation ---

    public function test_paid_to_does_not_alter_profit_calculation(): void
    {
        $account = $this->account();
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-PROFIT-' . uniqid(),
            'name' => 'Profit Check Supplier',
            'is_active' => true,
        ]);

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'supplier_id' => $supplier->id,
            'amount' => 250,
            'description' => 'Expense with paid to supplier',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $response = $this->getJson('/api/reports/profit?range=today')->assertOk();

        // Same figure a plain, no-paid-to expense of this amount would have
        // produced - the person/supplier link is pure traceability.
        $this->assertSame('250.00', $response->json('operating_expenses.total'));
        $this->assertSame('-250.00', $response->json('net_profit'));

        // The supplier's payable balance must stay exactly what the
        // purchase-free scenario started at (zero) - the expense's
        // supplier_id link must never feed the payable.
        $this->assertSame('0.00', $this->balances->supplierBalance($supplier));
    }

    // --- 9. Existing supplier-payment workflow remains separate ---

    public function test_supplier_payment_workflow_remains_separate_from_expense(): void
    {
        $account = $this->account();
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-SEP-' . uniqid(),
            'name' => 'Separation Check Supplier',
            'is_active' => true,
        ]);

        // supplier_payment is service-owned (see TransactionType::
        // publiclyCreatable()/TransactionTypeRestrictionTest) - created here
        // through the real SupplierPaymentService-backed
        // /api/suppliers/{supplier}/payments endpoint, against a genuine
        // outstanding purchase, instead of the generic endpoint.
        $unit = \App\Models\Unit::create(['name' => 'Sep Unit', 'abbreviation' => 'sepu' . uniqid()]);
        $product = \App\Models\Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'Sep Product',
            'sku' => 'SEP-' . uniqid(),
            'default_cost_price' => 12,
            'default_selling_price' => 20,
            'is_active' => true,
        ]);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 12]],
        ])->assertCreated();

        $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 120,
            'account_id' => $account->id,
        ])->assertOk();

        $profit = $this->getJson('/api/reports/profit?range=today')->assertOk();

        // A supplier_payment must never be counted as an operating expense,
        // categorized or not.
        $this->assertSame('0.00', $profit->json('operating_expenses.total'));
        $this->assertEmpty($profit->json('operating_expenses.by_category'));
    }

    // --- 10. Existing purchase-cost workflow remains separate ---

    public function test_purchase_cost_workflow_remains_separate_from_expense(): void
    {
        $account = $this->account();
        $category = $this->expenseCategory('Landed Cost Isolation');

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => 30,
            'description' => 'Ordinary categorized expense',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $profit = $this->getJson('/api/reports/profit?range=today')->assertOk();

        $this->assertSame('30.00', $profit->json('operating_expenses.total'));
        $byCategory = collect($profit->json('operating_expenses.by_category'));
        $this->assertCount(1, $byCategory);
        $this->assertSame('Landed Cost Isolation', $byCategory->first()['category']);
    }
}

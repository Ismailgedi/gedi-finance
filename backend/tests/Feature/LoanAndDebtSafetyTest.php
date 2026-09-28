<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Services\BalanceService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Overpayment protection for the three Loans & Debts settlement types
 * (loan_repayment/loan_payment/debt_payment) - see
 * TransactionService::assertWithinOutstandingBalance() - plus the
 * PersonController::show() bucket-separated balances it shares its
 * calculation with (BalanceService::loanGivenBalance()/
 * loanReceivedBalance()/otherReceivableBalance()). A person can carry a
 * customer receivable, a loan given, a loan received and an other
 * receivable at once; each bucket is a disjoint debt, never combined for
 * either display or validation.
 */
class LoanAndDebtSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private BalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('User', 'web');

        $user = \App\Models\User::factory()->create();
        $user->assignRole('User');
        $this->actingAs($user);

        $this->cash = Account::create(['name' => 'LD Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
        $this->balances = app(BalanceService::class);
    }

    private function superAdmin(): \App\Models\User
    {
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Super Admin');

        return $admin;
    }

    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'is_active' => true, 'roles' => []]);
    }

    private function transact(string $type, Person $person, float $amount, ?string $extra = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/transactions', [
            'type' => $type,
            'person_id' => $person->id,
            'account_id' => $this->cash->id,
            'amount' => $amount,
            'description' => $extra ?? ucfirst(str_replace('_', ' ', $type)),
        ]);
    }

    private function openingBalanceItem(string $category, Person $person, float $amount, float $equity): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->postJson('/api/admin/opening-balance', [
            'as_of_date' => '2026-01-01',
            'opening_equity' => $equity,
            'items' => [['category' => $category, 'person_id' => $person->id, 'amount' => $amount]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();
    }

    // ================= OVERPAYMENT =================

    public function test_loan_repayment_cannot_exceed_loan_given_balance(): void
    {
        $person = $this->person('LD Borrower 1');
        $this->transact('loan_given', $person, 1000)->assertCreated();
        $this->transact('loan_repayment', $person, 400)->assertCreated();

        // Remaining is 600 - a repayment of 601 must be rejected.
        $this->transact('loan_repayment', $person, 601)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame('600.00', $this->balances->loanGivenBalance($person->fresh()));
    }

    public function test_loan_payment_cannot_exceed_loan_received_balance(): void
    {
        $person = $this->person('LD Lender 1');
        $this->transact('loan_received', $person, 800)->assertCreated();
        $this->transact('loan_payment', $person, 300)->assertCreated();

        $this->transact('loan_payment', $person, 501)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame('500.00', $this->balances->loanReceivedBalance($person->fresh()));
    }

    public function test_debt_payment_cannot_exceed_other_receivable_balance(): void
    {
        $person = $this->person('LD Debtor 1');
        $this->postJson('/api/transactions', [
            'type' => 'debt_created', 'person_id' => $person->id, 'amount' => 500, 'description' => 'Other receivable',
        ])->assertCreated();
        $this->transact('debt_payment', $person, 200)->assertCreated();

        $this->transact('debt_payment', $person, 301)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame('300.00', $this->balances->otherReceivableBalance($person->fresh()));
    }

    public function test_exact_full_repayment_succeeds(): void
    {
        $person = $this->person('LD Full Repay');
        $this->transact('loan_given', $person, 1000)->assertCreated();

        $this->transact('loan_repayment', $person, 1000)->assertCreated();

        $this->assertSame('0.00', $this->balances->loanGivenBalance($person->fresh()));
    }

    public function test_partial_repayment_succeeds(): void
    {
        $person = $this->person('LD Partial Repay');
        $this->transact('loan_given', $person, 1000)->assertCreated();

        $this->transact('loan_repayment', $person, 400)->assertCreated();

        $this->assertSame('600.00', $this->balances->loanGivenBalance($person->fresh()));
    }

    public function test_opening_balance_only_loan_repayment_succeeds(): void
    {
        $person = $this->person('LD Opening Loan Given');
        $this->openingBalanceItem('loan_given', $person, 1000, 1000);

        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        $this->transact('loan_repayment', $person, 600)->assertCreated();

        $this->assertSame('400.00', $this->balances->loanGivenBalance($person->fresh()));
    }

    public function test_opening_balance_only_loan_payment_succeeds(): void
    {
        $person = $this->person('LD Opening Loan Received');
        $this->openingBalanceItem('loan_received', $person, 800, -800);

        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        $this->transact('loan_payment', $person, 300)->assertCreated();

        $this->assertSame('500.00', $this->balances->loanReceivedBalance($person->fresh()));
    }

    public function test_opening_balance_only_other_receivable_payment_succeeds(): void
    {
        $person = $this->person('LD Opening Other Receivable');
        $this->openingBalanceItem('other_receivable', $person, 300, 300);

        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        $this->transact('debt_payment', $person, 150)->assertCreated();

        $this->assertSame('150.00', $this->balances->otherReceivableBalance($person->fresh()));
    }

    public function test_overpayment_against_opening_balance_is_rejected(): void
    {
        $person = $this->person('LD Opening Overpay');
        $this->openingBalanceItem('loan_given', $person, 500, 500);

        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        $this->transact('loan_repayment', $person, 501)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame('500.00', $this->balances->loanGivenBalance($person->fresh()));
    }

    // ================= SEPARATION =================

    public function test_customer_receivable_does_not_increase_allowed_loan_repayment(): void
    {
        $person = $this->person('LD Sep Customer+Loan');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);

        // Give them a customer receivable via an opening balance item, and a
        // much smaller loan given - the receivable must never widen the
        // allowed loan_repayment ceiling.
        $this->openingBalanceItem('receivable', $person, 5000, 5000);

        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        $this->transact('loan_given', $person, 100)->assertCreated();

        $this->transact('loan_repayment', $person, 101)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->transact('loan_repayment', $person, 100)->assertCreated();
    }

    public function test_loan_balance_does_not_increase_allowed_debt_payment(): void
    {
        $person = $this->person('LD Sep Loan+Debt');
        $this->transact('loan_given', $person, 5000)->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'debt_created', 'person_id' => $person->id, 'amount' => 100, 'description' => 'Other receivable',
        ])->assertCreated();

        $this->transact('debt_payment', $person, 101)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->transact('debt_payment', $person, 100)->assertCreated();
    }

    public function test_other_receivable_does_not_increase_allowed_customer_payment(): void
    {
        $person = $this->person('LD Sep Debt+Customer');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/transactions', [
            'type' => 'debt_created', 'person_id' => $person->id, 'amount' => 5000, 'description' => 'Other receivable',
        ])->assertCreated();

        $this->openingBalanceItem('receivable', $person, 300, 300);
        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        // customer_payment (service-owned - see
        // TransactionTypeRestrictionTest) is governed only by
        // CustomerPaymentService's own ceiling (customerReceivableBalance),
        // never by the $5000 other-receivable bucket or by
        // TransactionService::assertWithinOutstandingBalance() (which never
        // matches CustomerPayment at all) - a payment up to the customer
        // receivable succeeds, and the other-receivable bucket is untouched.
        $this->postJson("/api/people/{$person->id}/payments", [
            'amount' => 300, 'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('5000.00', $this->balances->otherReceivableBalance($person->fresh()));
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($person->fresh()));
    }

    public function test_mixed_balances_remain_separate(): void
    {
        $person = $this->person('LD Mixed');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);

        $this->openingBalanceItem('receivable', $person, 1000, 1000);

        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        $this->transact('loan_given', $person, 200)->assertCreated();
        $this->transact('loan_received', $person, 300)->assertCreated();
        $this->postJson('/api/transactions', [
            'type' => 'debt_created', 'person_id' => $person->id, 'amount' => 50, 'description' => 'Other receivable',
        ])->assertCreated();

        $person->refresh();
        $this->assertSame('1000.00', $this->balances->customerReceivableBalance($person));
        $this->assertSame('200.00', $this->balances->loanGivenBalance($person));
        $this->assertSame('300.00', $this->balances->loanReceivedBalance($person));
        $this->assertSame('50.00', $this->balances->otherReceivableBalance($person));

        // Each settlement is capped only by its own bucket.
        $this->transact('loan_repayment', $person, 201)->assertStatus(422);
        $this->transact('loan_payment', $person, 301)->assertStatus(422);
        $this->transact('debt_payment', $person, 51)->assertStatus(422);

        $this->transact('loan_repayment', $person, 200)->assertCreated();
        $this->transact('loan_payment', $person, 300)->assertCreated();
        $this->transact('debt_payment', $person, 50)->assertCreated();
    }

    // ================= PERSON DETAIL =================

    public function test_person_detail_shows_customer_receivable_separately(): void
    {
        $person = $this->person('LD Detail Customer');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);
        $this->openingBalanceItem('receivable', $person, 750, 750);

        $response = $this->getJson("/api/people/{$person->id}")->assertOk()->json();

        $this->assertSame('750.00', $response['balances']['customer_receivable']);
        $this->assertSame('0.00', $response['balances']['loans_given']);
    }

    public function test_person_detail_shows_loans_separately(): void
    {
        $person = $this->person('LD Detail Loans');
        $this->transact('loan_given', $person, 400)->assertCreated();
        $this->transact('loan_received', $person, 250)->assertCreated();

        $response = $this->getJson("/api/people/{$person->id}")->assertOk()->json();

        $this->assertSame('400.00', $response['balances']['loans_given']);
        $this->assertSame('250.00', $response['balances']['loans_received']);
        $this->assertSame('0.00', $response['balances']['customer_receivable']);
        $this->assertSame('0.00', $response['balances']['other_receivable']);
    }

    public function test_person_detail_shows_other_receivable_separately(): void
    {
        $person = $this->person('LD Detail Other Receivable');
        $this->postJson('/api/transactions', [
            'type' => 'debt_created', 'person_id' => $person->id, 'amount' => 120, 'description' => 'Other receivable',
        ])->assertCreated();

        $response = $this->getJson("/api/people/{$person->id}")->assertOk()->json();

        $this->assertSame('120.00', $response['balances']['other_receivable']);
    }

    public function test_person_detail_shows_negative_credit_balance_correctly(): void
    {
        $person = $this->person('LD Detail Credit');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);

        // customer_payment can no longer be created bare with no prior
        // receivable (it's service-owned now, and CustomerPaymentService
        // itself refuses a payment with nothing outstanding to apply it
        // to) - a customer credit is instead produced the same legitimate
        // way SaleReturnWorkflowTest does: a fully-paid sale, then a full
        // return settled as credit, leaving a negative receivable (a real
        // customer credit) that must not be clamped or hidden.
        $unit = \App\Models\Unit::create(['name' => 'LD Credit Unit', 'abbreviation' => 'ldcu' . uniqid()]);
        $product = \App\Models\Product::create([
            'base_unit_id' => $unit->id, 'name' => 'LD Credit Product', 'sku' => 'LD-CREDIT-' . uniqid(),
            'default_cost_price' => 10, 'default_selling_price' => 20, 'is_active' => true,
        ]);
        app(\App\Services\InventoryService::class)->record($product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $person->id, 'amount_paid' => 200, 'account_id' => $this->cash->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'reason' => 'Customer changed their mind',
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 10, 'is_saleable' => true]],
        ])->assertCreated();

        $response = $this->getJson("/api/people/{$person->id}")->assertOk()->json();

        $this->assertSame('-200.00', $response['balances']['customer_receivable']);
    }

    public function test_person_detail_never_returns_misleading_merged_balance_key(): void
    {
        $person = $this->person('LD Detail No Merge');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);
        $this->openingBalanceItem('receivable', $person, 300, 300);

        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        $this->transact('loan_given', $person, 500)->assertCreated();

        $response = $this->getJson("/api/people/{$person->id}")->assertOk()->json();

        // No top-level "balance" key - only the labeled, bucket-separated
        // "balances" object (with "combined" explicitly named as such).
        $this->assertArrayNotHasKey('balance', $response);
        $this->assertArrayHasKey('balances', $response);
        $this->assertArrayHasKey('combined', $response['balances']);
        $this->assertSame('800.00', $response['balances']['combined']);
        $this->assertSame('300.00', $response['balances']['customer_receivable']);
        $this->assertSame('500.00', $response['balances']['loans_given']);
    }

    // ================= DEAD LOAN API =================

    public function test_get_loans_route_no_longer_exists(): void
    {
        $this->getJson('/api/loans')->assertStatus(404);
    }

    // ================= REGRESSION =================

    public function test_customer_payment_is_not_gated_by_the_new_guard(): void
    {
        $person = $this->person('LD Regression Customer Payment');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);
        $this->openingBalanceItem('receivable', $person, 100, 100);

        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        // customer_payment is untouched by this task - a payment within the
        // actual outstanding balance still succeeds, governed only by
        // CustomerPaymentService's own pre-existing ceiling, never by
        // TransactionService::assertWithinOutstandingBalance() (which never
        // matches CustomerPayment at all).
        $this->postJson("/api/people/{$person->id}/payments", [
            'amount' => 100, 'account_id' => $this->cash->id,
        ])->assertOk();
    }

    public function test_supplier_payment_is_not_gated_by_the_new_guard(): void
    {
        $supplier = \App\Models\Supplier::create(['supplier_code' => 'LD-SUP', 'name' => 'LD Supplier', 'is_active' => true]);
        $supplier->update(['credit_limit' => null]);

        $product = \App\Models\Product::create([
            'base_unit_id' => \App\Models\Unit::create(['name' => 'LD Unit', 'abbreviation' => 'ldu'])->id,
            'name' => 'LD Product', 'sku' => 'LD-PROD', 'default_cost_price' => 10, 'default_selling_price' => 15, 'is_active' => true,
        ]);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 10]],
        ])->assertCreated();

        $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 500,
            'account_id' => $this->cash->id,
        ])->assertOk();
    }

    public function test_ordinary_user_can_still_perform_routine_repayment(): void
    {
        $regular = \App\Models\User::factory()->create();
        $regular->assignRole('User');
        $this->actingAs($regular);

        $person = $this->person('LD Ordinary User');
        $this->transact('loan_given', $person, 300)->assertCreated();
        $this->transact('loan_repayment', $person, 150)->assertCreated();

        $this->assertSame('150.00', $this->balances->loanGivenBalance($person->fresh()));
    }

    public function test_loan_given_and_debt_created_are_unaffected_by_the_guard(): void
    {
        // The guard only applies to settlement types - creating a new debt
        // has no outstanding balance to be "within."
        $person = $this->person('LD Creation Unaffected');
        $this->transact('loan_given', $person, 999999)->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'debt_created', 'person_id' => $person->id, 'amount' => 999999, 'description' => 'Other receivable',
        ])->assertCreated();
    }
}

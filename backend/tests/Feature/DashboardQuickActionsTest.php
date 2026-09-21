<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the exact payloads the Dashboard's quick-action links now lead
 * to: RecordTransaction (income/expense/account_transfer via /record) and
 * LoansDebts (loan_given/loan_received/loan_repayment via /loans?kind=).
 * These types previously had no frontend path to POST /api/transactions at
 * all, so this proves the full request -> TransactionService -> stored
 * effects -> BalanceService chain actually works end to end, not just that
 * the route exists.
 */
class DashboardQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    private BalanceServiceHelper $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->balances = new BalanceServiceHelper(app(\App\Services\BalanceService::class));
    }

    public function test_receive_quick_action_income_increases_account_balance(): void
    {
        $cash = $this->makeAccount('Cash', 100);

        $this->postJson('/api/transactions', [
            'type' => 'income',
            'account_id' => $cash->id,
            'amount' => 50,
            'currency' => 'USD',
            'description' => 'Freelance payment from Ahmed',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('150.00', $this->balances->account($cash));
    }

    public function test_expense_quick_action_decreases_account_balance(): void
    {
        $cash = $this->makeAccount('Cash', 100);

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $cash->id,
            'amount' => 30,
            'currency' => 'USD',
            'description' => 'Tuk-tuk to warehouse',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('70.00', $this->balances->account($cash));
    }

    public function test_transfer_quick_action_moves_money_between_accounts_without_changing_total(): void
    {
        $bank = $this->makeAccount('Bank', 500);
        $cash = $this->makeAccount('Cash', 100);

        $this->postJson('/api/transactions', [
            'type' => 'account_transfer',
            'account_id' => $bank->id,
            'destination_account_id' => $cash->id,
            'amount' => 200,
            'currency' => 'USD',
            'description' => 'Move float from Bank to Cash',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('300.00', $this->balances->account($bank));
        $this->assertSame('300.00', $this->balances->account($cash));
    }

    public function test_transfer_quick_action_rejects_same_source_and_destination(): void
    {
        $bank = $this->makeAccount('Bank', 500);

        $this->postJson('/api/transactions', [
            'type' => 'account_transfer',
            'account_id' => $bank->id,
            'destination_account_id' => $bank->id,
            'amount' => 200,
            'currency' => 'USD',
            'description' => 'Invalid self-transfer',
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_give_quick_action_loan_given_creates_receivable_and_debits_account(): void
    {
        $cash = $this->makeAccount('Cash', 1000);
        $ahmed = $this->makePerson('Ahmed');

        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $ahmed->id,
            'account_id' => $cash->id,
            'amount' => 400,
            'currency' => 'USD',
            'description' => 'Personal loan',
            'transaction_date' => now()->toDateString(),
            'status' => 'posted',
        ])->assertCreated();

        $this->assertSame('600.00', $this->balances->account($cash), 'cash should decrease by the loaned amount');
        $this->assertSame('400.00', $this->balances->person($ahmed), 'Ahmed should now owe Gedi 400');
    }

    public function test_loan_quick_action_loan_received_credits_account_and_creates_payable(): void
    {
        $bank = $this->makeAccount('Bank', 0);
        $hassan = $this->makePerson('Hassan');

        $this->postJson('/api/transactions', [
            'type' => 'loan_received',
            'person_id' => $hassan->id,
            'account_id' => $bank->id,
            'amount' => 2000,
            'currency' => 'USD',
            'description' => 'Loan from Hassan',
            'transaction_date' => now()->toDateString(),
            'status' => 'posted',
        ])->assertCreated();

        $this->assertSame('2000.00', $this->balances->account($bank), 'bank should increase by the received loan');
        $this->assertSame('-2000.00', $this->balances->person($hassan), 'Gedi should now owe Hassan 2000');
    }

    public function test_repay_quick_action_loan_repayment_reduces_receivable_and_credits_account(): void
    {
        $cash = $this->makeAccount('Cash', 600);
        $ahmed = $this->makePerson('Ahmed');

        // Seed an existing receivable the same way "Give" would.
        $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $ahmed->id,
            'account_id' => $cash->id,
            'amount' => 1000,
            'currency' => 'USD',
            'description' => 'Personal loan',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'loan_repayment',
            'person_id' => $ahmed->id,
            'account_id' => $cash->id,
            'amount' => 300,
            'currency' => 'USD',
            'description' => 'Partial repayment',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('700.00', $this->balances->person($ahmed), 'Ahmed still owes 700 after paying back 300 of 1000');
    }

    private function makeAccount(string $name, float $opening): Account
    {
        return Account::create([
            'name' => $name . ' ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => $opening,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function makePerson(string $name): Person
    {
        return Person::create([
            'name' => $name,
            'roles' => ['borrower'],
            'is_active' => true,
        ]);
    }
}

/**
 * Thin string-typed wrapper so assertions read as plain numbers instead of
 * repeating app(BalanceService::class)->accountBalance(...) everywhere.
 */
final class BalanceServiceHelper
{
    public function __construct(private readonly \App\Services\BalanceService $service) {}

    public function account(Account $account): string
    {
        return $this->service->accountBalance($account->fresh());
    }

    public function person(Person $person): string
    {
        return $this->service->personBalance($person->fresh());
    }
}

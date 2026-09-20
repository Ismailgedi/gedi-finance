<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function account(): Account
    {
        return Account::create([
            'name' => 'Receipt Test Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_receipt_is_available_for_an_income_transaction(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $this->actingAs($user);

        $created = $this->postJson('/api/transactions', [
            'type' => 'income',
            'account_id' => $this->account()->id,
            'amount' => 250,
            'currency' => 'USD',
            'description' => 'Consulting income',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated()->json('transaction');

        $response = $this->getJson("/api/transactions/{$created['id']}/receipt");

        $response->assertOk();
        $response->assertJsonPath('transaction.id', $created['id']);
        $response->assertJsonPath('transaction.type', 'income');
        $response->assertJsonPath('transaction.amount', '250.00');
        $response->assertJsonPath('transaction.transaction_number', $created['transaction_number']);
        $response->assertJsonPath('receipt_number', 'RCPT-' . $created['transaction_number']);
        $response->assertJsonPath('business_name', config('app.name'));
        $response->assertJsonPath('transaction.creator.id', $user->id);
        $response->assertJsonMissingPath('transaction.creator.password');
    }

    public function test_receipt_is_available_for_an_expense_transaction(): void
    {
        $this->actingAs(User::factory()->create());

        $created = $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->account()->id,
            'amount' => 75.5,
            'description' => 'Office supplies',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated()->json('transaction');

        $this->getJson("/api/transactions/{$created['id']}/receipt")
            ->assertOk()
            ->assertJsonPath('transaction.type', 'expense')
            ->assertJsonPath('transaction.amount', '75.50');
    }

    public function test_receipt_shows_person_for_a_customer_payment(): void
    {
        $this->actingAs(User::factory()->create());

        $person = Person::create([
            'name' => 'Receipt Customer',
            'roles' => ['customer'],
            'is_active' => true,
        ]);

        $created = $this->postJson('/api/transactions', [
            'type' => 'customer_payment',
            'person_id' => $person->id,
            'account_id' => $this->account()->id,
            'amount' => 40,
            'description' => 'Partial payment',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated()->json('transaction');

        $this->getJson("/api/transactions/{$created['id']}/receipt")
            ->assertOk()
            ->assertJsonPath('transaction.type', 'customer_payment')
            ->assertJsonPath('transaction.person.id', $person->id)
            ->assertJsonPath('transaction.person.name', 'Receipt Customer');
    }

    public function test_receipt_shows_a_loan_given_transaction(): void
    {
        $this->actingAs(User::factory()->create());

        $person = Person::create([
            'name' => 'Loan Recipient',
            'roles' => ['borrower'],
            'is_active' => true,
        ]);

        $created = $this->postJson('/api/transactions', [
            'type' => 'loan_given',
            'person_id' => $person->id,
            'account_id' => $this->account()->id,
            'amount' => 500,
            'description' => 'Loan to supplier partner',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated()->json('transaction');

        $this->getJson("/api/transactions/{$created['id']}/receipt")
            ->assertOk()
            ->assertJsonPath('transaction.type', 'loan_given')
            ->assertJsonPath('transaction.person.id', $person->id)
            ->assertJsonPath('transaction.person_balance_effect', '500.00')
            ->assertJsonPath('transaction.account_balance_effect', '-500.00');
    }

    public function test_receipt_shows_both_accounts_for_a_transfer(): void
    {
        $this->actingAs(User::factory()->create());

        $from = $this->account();
        $to = Account::create([
            'name' => 'Receipt Test Bank',
            'type' => 'bank',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $created = $this->postJson('/api/transactions', [
            'type' => 'account_transfer',
            'account_id' => $from->id,
            'destination_account_id' => $to->id,
            'amount' => 120,
            'description' => 'Move cash to bank',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated()->json('transaction');

        $response = $this->getJson("/api/transactions/{$created['id']}/receipt");

        $response->assertOk();
        $response->assertJsonPath('transaction.account.id', $from->id);
        $response->assertJsonPath('transaction.destination_account.id', $to->id);
        $response->assertJsonPath('transaction.account_balance_effect', '-120.00');
        $response->assertJsonPath('transaction.destination_account_effect', '120.00');
    }

    public function test_receipt_requires_authentication(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $created = $this->postJson('/api/transactions', [
            'type' => 'income',
            'account_id' => $this->account()->id,
            'amount' => 10,
            'description' => 'Auth check income',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated()->json('transaction');

        auth()->logout();

        $this->getJson("/api/transactions/{$created['id']}/receipt")
            ->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_person_related_transactions_require_an_active_person(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/transactions', [
            'type' => 'credit_sale',
            'amount' => 10,
            'description' => 'Expense without person',
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('errors.person_id.0', 'The person id field is required.');

        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => Account::create([
                'name' => 'Expense Cash',
                'type' => 'cash',
                'opening_balance' => 0,
                'currency' => 'USD',
                'is_active' => true,
            ])->id,
            'amount' => 10,
            'description' => 'Generic expense without person',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_cash_and_credit_sales_store_expected_effects(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $person = Person::create([
            'name' => 'Test Customer',
            'roles' => ['customer'],
            'is_active' => true,
        ]);
        $account = Account::create([
            'name' => 'Test Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $base = [
            'person_id' => $person->id,
            'amount' => 100,
            'currency' => 'USD',
            'description' => 'Test sale',
            'transaction_date' => now()->toDateString(),
        ];

        $this->postJson('/api/transactions', $base + [
            'type' => 'cash_sale',
            'account_id' => $account->id,
        ])->assertCreated();

        $this->postJson('/api/transactions', $base + [
            'type' => 'credit_sale',
        ])->assertCreated();

        $this->assertDatabaseHas('transactions', [
            'type' => 'cash_sale',
            'person_id' => $person->id,
            'account_balance_effect' => '100.00',
            'person_balance_effect' => '0.00',
        ]);
        $this->assertDatabaseHas('transactions', [
            'type' => 'credit_sale',
            'person_id' => $person->id,
            'account_balance_effect' => '0.00',
            'person_balance_effect' => '100.00',
        ]);
    }
}

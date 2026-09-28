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

    /**
     * config('app.name') resolves 'name' => env('APP_NAME', 'Laravel') in a
     * stock Laravel app - so any environment missing an APP_NAME variable
     * (this production app's own local .env sets it, but a deployment
     * environment's own configuration might not) would silently show the
     * framework's literal "Laravel" on a real customer-facing receipt
     * instead of "Gedi Finance". config/app.php's own default is the fix
     * (not a hardcoded string in TransactionController), so this asserts
     * the actual resolved value directly rather than the tautological
     * business_name === config('app.name') check above.
     */
    public function test_receipt_business_name_is_gedi_finance_never_the_laravel_default(): void
    {
        $this->actingAs(User::factory()->create());

        $created = $this->postJson('/api/transactions', [
            'type' => 'income',
            'account_id' => $this->account()->id,
            'amount' => 100,
            'currency' => 'USD',
            'description' => 'Branding regression check',
            'transaction_date' => now()->toDateString(),
        ])->assertCreated()->json('transaction');

        $response = $this->getJson("/api/transactions/{$created['id']}/receipt");

        $response->assertOk();
        $response->assertJsonPath('business_name', 'Gedi Finance');
        $this->assertNotSame('Laravel', $response->json('business_name'));
        $this->assertNotSame('Laravel', config('app.name'));
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

    /**
     * customer_payment is service-owned (see TransactionType::
     * publiclyCreatable()/TransactionTypeRestrictionTest) - created here
     * through the real CustomerPaymentService-backed
     * /api/people/{person}/payments endpoint instead of the generic one.
     */
    public function test_receipt_shows_person_for_a_customer_payment(): void
    {
        $this->actingAs(User::factory()->create());

        $person = Person::create([
            'name' => 'Receipt Customer',
            'roles' => ['customer'],
            'is_active' => true,
            'is_customer' => true,
        ]);

        $unit = \App\Models\Unit::create(['name' => 'Receipt Unit', 'abbreviation' => 'rcu' . uniqid()]);
        $product = \App\Models\Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'Receipt Product',
            'sku' => 'RCPT-' . uniqid(),
            'default_cost_price' => 5,
            'default_selling_price' => 10,
            'is_active' => true,
        ]);
        app(\App\Services\InventoryService::class)->record($product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 5);

        $this->postJson('/api/sales', [
            'customer_id' => $person->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]],
        ])->assertCreated();

        $this->postJson("/api/people/{$person->id}/payments", [
            'amount' => 40,
            'account_id' => $this->account()->id,
        ])->assertOk();

        $transaction = \App\Models\Transaction::query()->where('type', 'customer_payment')->firstOrFail();

        $this->getJson("/api/transactions/{$transaction->id}/receipt")
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

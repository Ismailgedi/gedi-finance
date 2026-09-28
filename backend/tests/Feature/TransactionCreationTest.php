<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
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

        // debt_created (Other Receivable) is publicly creatable and
        // person-required - credit_sale used to serve this purpose here,
        // but it is now service-owned (see TransactionType::
        // publiclyCreatable()) and can no longer be posted through this
        // endpoint at all (see TransactionTypeRestrictionTest).
        $this->postJson('/api/transactions', [
            'type' => 'debt_created',
            'amount' => 10,
            'description' => 'Other receivable without person',
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

    /**
     * cash_sale/credit_sale can no longer be created through the generic
     * endpoint (they are service-owned - see TransactionTypeRestrictionTest)
     * - this now verifies the exact same signed effects through the real
     * SaleService-backed /api/sales endpoint, which is the only legitimate
     * way either type is ever posted.
     */
    public function test_cash_and_credit_sales_store_expected_effects(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $person = Person::create([
            'name' => 'Test Customer',
            'roles' => ['customer'],
            'is_active' => true,
            'is_customer' => true,
        ]);
        $account = Account::create([
            'name' => 'Test Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $unit = Unit::create(['name' => 'TCT Unit', 'abbreviation' => 'tctu' . uniqid()]);
        $product = Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'TCT Product',
            'sku' => 'TCT-' . uniqid(),
            'default_cost_price' => 5,
            'default_selling_price' => 10,
            'is_active' => true,
        ]);
        $this->actingAs($user);
        app(InventoryService::class)->record($product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 5);

        // Fully paid at sale time -> cash_sale.
        $this->postJson('/api/sales', [
            'customer_id' => $person->id,
            'amount_paid' => 100,
            'account_id' => $account->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]],
        ])->assertCreated();

        // Unpaid -> credit_sale.
        $this->postJson('/api/sales', [
            'customer_id' => $person->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]],
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

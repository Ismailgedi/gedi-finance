<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * There was previously no way to correct a typo in a product name/price, a
 * supplier's phone number, a customer's credit limit, or an account's
 * display name anywhere in the app - create/list/show only. Verifies the
 * new PUT endpoints work, and that the fields deliberately excluded for
 * financial-integrity reasons (base_unit_id, opening_balance, supplier_code)
 * stay untouched even if a caller tries to send them.
 */
class MasterDataEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_product_can_be_edited_but_base_unit_cannot(): void
    {
        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $kg = Unit::create(['name' => 'Kilogram', 'abbreviation' => 'kg']);

        $product = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Edit Test Rice',
            'sku' => 'EDIT-RICE',
            'default_cost_price' => 18,
            'default_selling_price' => 25,
            'is_active' => true,
        ]);

        $updated = $this->putJson("/api/products/{$product->id}", [
            'name' => 'Edit Test Rice 25kg',
            'default_selling_price' => 27,
            // Attempting to smuggle in a base_unit_id change - must be ignored.
            'base_unit_id' => $kg->id,
        ])->assertOk()->json('product');

        $this->assertSame('Edit Test Rice 25kg', $updated['name']);
        $this->assertSame('27.0000', $updated['default_selling_price']);
        $this->assertSame($bag->id, $updated['base_unit_id'], 'base_unit_id must never change via update.');
    }

    public function test_supplier_can_be_edited_but_supplier_code_cannot(): void
    {
        $supplier = Supplier::create([
            'supplier_code' => 'EDIT-SUP-1',
            'name' => 'Edit Test Supplier',
            'phone' => '111',
            'is_active' => true,
        ]);

        $updated = $this->putJson("/api/suppliers/{$supplier->id}", [
            'name' => 'Edit Test Supplier Renamed',
            'phone' => '222',
            'credit_limit' => 5000,
            'supplier_code' => 'HACKED-CODE',
        ])->assertOk()->json('supplier');

        $this->assertSame('Edit Test Supplier Renamed', $updated['name']);
        $this->assertSame('222', $updated['phone']);
        $this->assertSame('5000.00', $updated['credit_limit']);
        $this->assertSame('EDIT-SUP-1', $updated['supplier_code'], 'supplier_code must never change via update.');
    }

    public function test_person_can_be_edited_including_customer_flag_and_credit_limit(): void
    {
        $person = Person::create([
            'name' => 'Edit Test Person',
            'is_active' => true,
            'is_customer' => false,
            'roles' => ['borrower'],
        ]);

        $updated = $this->putJson("/api/people/{$person->id}", [
            'name' => 'Edit Test Person Renamed',
            'is_customer' => true,
            'credit_limit' => 1000,
            'roles' => ['customer'],
        ])->assertOk()->json('person');

        $this->assertSame('Edit Test Person Renamed', $updated['name']);
        $this->assertTrue($updated['is_customer']);
        $this->assertSame('1000.00', $updated['credit_limit']);
    }

    public function test_account_can_be_edited_but_opening_balance_cannot(): void
    {
        $account = Account::create([
            'name' => 'Edit Test Cash',
            'type' => 'cash',
            'opening_balance' => 500,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $updated = $this->putJson("/api/accounts/{$account->id}", [
            'name' => 'Edit Test Cash Renamed',
            'type' => 'cash',
            'currency' => 'USD',
            'opening_balance' => 999999,
        ])->assertOk()->json('account');

        $this->assertSame('Edit Test Cash Renamed', $updated['name']);
        $this->assertSame('500.00', $updated['opening_balance'], 'opening_balance must never change via update - it would silently shift every historical balance calculation.');
    }
}

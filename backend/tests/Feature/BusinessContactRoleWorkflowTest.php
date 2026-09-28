<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A Business Contact (Person) is the single canonical identity for both
 * customers and suppliers. Sales already pointed customer_id straight at
 * people - there was never a separate customers table. Suppliers is a
 * one-to-one role profile (credit_limit, payment_terms_days, email,
 * notes - genuinely supplier-specific fields) linked to people via
 * suppliers.person_id, auto-provisioned the moment is_supplier=true, so
 * marking someone a supplier makes them immediately selectable in
 * Purchases without a separate manual "Add Supplier" step.
 */
class BusinessContactRoleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private Product $rice;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->balances = app(BalanceService::class);

        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Role Workflow Rice',
            'sku' => 'ROLE-WF-RICE',
            'default_cost_price' => 20,
            'default_selling_price' => 30,
            'is_active' => true,
        ]);
        app(InventoryService::class)->record($this->rice, 500, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 20);

        $this->cash = Account::create([
            'name' => 'Role Workflow Cash',
            'type' => 'cash',
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /**
     * The exact reported bug: a Business Contact created with the
     * supplier role must be selectable in Purchases without anyone
     * manually re-creating them on the separate Suppliers screen.
     */
    public function test_faarax_with_supplier_role_is_selectable_in_purchases_without_manual_recreation(): void
    {
        $faarax = $this->postJson('/api/people', [
            'name' => 'Faarax',
            'phone' => '615551234',
            'roles' => ['supplier'],
            'is_customer' => false,
            'is_supplier' => true,
        ])->assertCreated()->json('person');

        // Selectable in the Purchases supplier list immediately.
        $suppliers = $this->getJson('/api/suppliers')->assertOk()->json('data');
        $supplierEntry = collect($suppliers)->firstWhere('name', 'Faarax');
        $this->assertNotNull($supplierEntry, 'Faarax must appear in the supplier selector without manual creation.');
        $this->assertSame('615551234', $supplierEntry['phone']);

        // And a purchase can actually be posted against them.
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplierEntry['id'],
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase');

        $this->assertSame('200.00', $purchase['total']);

        // No duplicate Faarax was created anywhere in the process.
        $this->assertSame(1, Person::where('name', 'Faarax')->count());
        $this->assertSame(1, Supplier::where('name', 'Faarax')->count());
    }

    public function test_person_with_customer_role_can_be_selected_in_a_sale(): void
    {
        $customer = Person::create([
            'name' => 'Ahmed',
            'is_active' => true,
            'is_customer' => true,
            'is_supplier' => false,
            'roles' => ['customer'],
        ]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_price' => 30]],
        ])->assertCreated()->json('sale');

        $this->assertSame($customer->id, $sale['customer_id']);
        $this->assertSame('150.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    public function test_person_with_both_roles_works_in_both_purchase_and_sale_workflows(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Dual Role Trader',
            'roles' => ['customer', 'supplier'],
            'is_customer' => true,
            'is_supplier' => true,
        ])->assertCreated()->json('person');

        $this->assertTrue($person['is_customer']);
        $this->assertTrue($person['is_supplier']);
        $this->assertEqualsCanonicalizing(['customer', 'supplier'], $person['roles']);

        $supplierEntry = collect(
            $this->getJson('/api/suppliers')->assertOk()->json('data')
        )->firstWhere('name', 'Dual Role Trader');
        $this->assertNotNull($supplierEntry);

        // Works as a supplier...
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplierEntry['id'],
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 4, 'unit_cost' => 20]],
        ])->assertCreated();

        // ...and as a customer, for the SAME underlying person.
        $this->postJson('/api/sales', [
            'customer_id' => $person['id'],
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 3, 'unit_price' => 30]],
        ])->assertCreated();

        $this->assertSame('80.00', $this->balances->supplierBalance(Supplier::where('person_id', $person['id'])->firstOrFail()));
        $this->assertSame('90.00', $this->balances->customerReceivableBalance(Person::find($person['id'])));
        $this->assertSame(1, Person::where('name', 'Dual Role Trader')->count());
    }

    public function test_supplier_payable_is_created_after_a_credit_purchase(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Credit Purchase Supplier',
            'is_supplier' => true,
        ])->assertCreated()->json('person');

        $supplier = Supplier::where('person_id', $person['id'])->firstOrFail();

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 15, 'unit_cost' => 20]],
        ])->assertCreated();

        $this->assertSame('300.00', $this->balances->supplierBalance($supplier->fresh()));
    }

    public function test_supplier_payment_still_works_for_a_person_linked_supplier(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Paid Supplier',
            'is_supplier' => true,
        ])->assertCreated()->json('person');

        $supplier = Supplier::where('person_id', $person['id'])->firstOrFail();

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated();

        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $response = $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 120,
            'account_id' => $this->cash->id,
        ])->assertOk()->json();

        $this->assertSame('120.00', $response['applied']);
        $this->assertSame('80.00', $this->balances->supplierBalance($supplier->fresh()));
        // Paying a supplier is cash going OUT of the business account.
        $this->assertSame(
            number_format($cashBefore - 120, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
        );
    }

    public function test_customer_receivable_still_works_for_a_dual_role_person(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Receivable Check Person',
            'is_customer' => true,
            'is_supplier' => true,
        ])->assertCreated()->json('person');

        $this->postJson('/api/sales', [
            'customer_id' => $person['id'],
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 6, 'unit_price' => 30]],
        ])->assertCreated();

        $this->assertSame('180.00', $this->balances->customerReceivableBalance(Person::find($person['id'])));
    }

    public function test_customer_payment_still_works_for_a_person_created_via_the_new_workflow(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Payment Check Customer',
            'is_customer' => true,
        ])->assertCreated()->json('person');

        $this->postJson('/api/sales', [
            'customer_id' => $person['id'],
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 30]],
        ])->assertCreated();

        $response = $this->postJson("/api/people/{$person['id']}/payments", [
            'amount' => 100,
            'account_id' => $this->cash->id,
        ])->assertOk()->json();

        $this->assertSame('100.00', $response['applied']);
        $this->assertSame('200.00', $this->balances->customerReceivableBalance(Person::find($person['id'])));
    }

    /**
     * A supplier created before this change (no linked person) must
     * keep every historical purchase attached to the exact same
     * supplier_id - nothing about the FK relationship changes.
     */
    public function test_existing_purchase_relationships_remain_valid_for_a_pre_existing_unlinked_supplier(): void
    {
        $supplier = Supplier::create([
            'supplier_code' => 'LEGACY-SUP',
            'name' => 'Legacy Supplier',
            'is_active' => true,
        ]);

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 5, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase');

        $this->assertSame($supplier->id, $purchase['supplier_id']);
        $this->assertNull($supplier->fresh()->person_id, 'A pre-existing supplier without a matching contact stays unlinked until someone links it - it is never silently merged.');
        $this->assertSame(1, DB::table('purchases')->where('supplier_id', $supplier->id)->count());
    }

    public function test_editing_a_person_role_from_business_contacts_makes_them_selectable_in_purchases(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Promoted To Supplier',
            'is_customer' => true,
            'is_supplier' => false,
        ])->assertCreated()->json('person');

        $this->assertNull(Supplier::where('person_id', $person['id'])->first());

        $this->putJson("/api/people/{$person['id']}", [
            'name' => 'Promoted To Supplier',
            'is_customer' => true,
            'is_supplier' => true,
        ])->assertOk();

        $supplier = Supplier::where('person_id', $person['id'])->first();
        $this->assertNotNull($supplier, 'Turning on is_supplier from Business Contacts must provision a Supplier profile immediately.');
        $this->assertSame('Promoted To Supplier', $supplier->name);

        collect($this->getJson('/api/suppliers')->assertOk()->json('data'))
            ->firstWhere('id', $supplier->id)
            ?? $this->fail('Newly-promoted supplier must appear in the selector.');
    }

    public function test_editing_name_from_business_contacts_keeps_the_supplier_profile_in_sync(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Original Name',
            'is_supplier' => true,
        ])->assertCreated()->json('person');

        $this->putJson("/api/people/{$person['id']}", [
            'name' => 'Renamed Supplier',
            'phone' => '699998888',
            'is_supplier' => true,
        ])->assertOk();

        $supplier = Supplier::where('person_id', $person['id'])->firstOrFail();
        $this->assertSame('Renamed Supplier', $supplier->name);
        $this->assertSame('699998888', $supplier->phone);
    }

    public function test_editing_from_the_suppliers_screen_keeps_the_linked_person_in_sync(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Sync From Supplier Screen',
            'is_supplier' => true,
        ])->assertCreated()->json('person');

        $supplier = Supplier::where('person_id', $person['id'])->firstOrFail();

        $this->putJson("/api/suppliers/{$supplier->id}", [
            'name' => 'Renamed From Supplier Screen',
            'phone' => '611112222',
        ])->assertOk();

        $this->assertSame('Renamed From Supplier Screen', Person::find($person['id'])->name);
        $this->assertSame('611112222', Person::find($person['id'])->phone);
    }

    public function test_no_duplicate_person_is_created_when_marking_an_existing_contact_as_a_supplier(): void
    {
        $person = $this->postJson('/api/people', [
            'name' => 'Single Identity Contact',
            'is_customer' => true,
        ])->assertCreated()->json('person');

        $this->putJson("/api/people/{$person['id']}", [
            'name' => 'Single Identity Contact',
            'is_customer' => true,
            'is_supplier' => true,
        ])->assertOk();

        // Calling the defensive provisioning path again (as /api/suppliers
        // does on every request) must never create a second Person or a
        // second Supplier profile.
        $this->getJson('/api/suppliers')->assertOk();
        $this->getJson('/api/suppliers')->assertOk();

        $this->assertSame(1, Person::where('name', 'Single Identity Contact')->count());
        $this->assertSame(1, Supplier::where('person_id', $person['id'])->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C1 (accounting audit): the generic POST /api/transactions endpoint used
 * to accept every TransactionType, including the "service-owned" ones that
 * carry required linkage, inventory effects, or reconciliation logic only
 * their owning service performs (SaleService, PurchaseService,
 * SaleReturnService, PurchaseReturnService, CustomerPaymentService,
 * SupplierPaymentService, OpeningBalanceService). This let any
 * authenticated ordinary User fabricate a receivable/payable/return/opening
 * balance disconnected from any real Sale/Purchase/reconciliation, breaking
 * Business Position's Assets = Liabilities + Equity identity (credit_sale/
 * purchase/opening_balance_*) or bypassing the Opening Balance workflow's
 * Super-Admin gate entirely (opening_balance_*).
 *
 * The fix: TransactionType::publiclyCreatable() is now the single source of
 * truth for which types the generic endpoint may create (see
 * StoreTransactionRequest's `type` rule and TransactionService::create()'s
 * $internal flag, which only the owning services ever set to true). This
 * file proves the restriction from both directions - blocked for the public
 * path, still working for every legitimate internal path.
 */
class TransactionTypeRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        \Database\Seeders\DatabaseSeeder::seedRolesAndPermissions();

        $this->cash = Account::create(['name' => 'TTR Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
    }

    private function ordinaryUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        return $user;
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        return $admin;
    }

    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'is_active' => true, 'roles' => []]);
    }

    private function product(string $sku): Product
    {
        $unit = Unit::create(['name' => "TTR Unit {$sku}", 'abbreviation' => 'ttr' . uniqid()]);

        return Product::create([
            'base_unit_id' => $unit->id, 'name' => "TTR Product {$sku}", 'sku' => $sku,
            'default_cost_price' => 10, 'default_selling_price' => 20, 'is_active' => true,
        ]);
    }

    private function assertTypeRejected(array $payload): void
    {
        $this->postJson('/api/transactions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ================= BLOCKED: service-owned types =================

    public function test_ordinary_user_cannot_create_credit_sale_through_generic_endpoint(): void
    {
        $this->actingAs($this->ordinaryUser());
        $person = $this->person('TTR Credit Sale Person');

        $this->assertTypeRejected([
            'type' => 'credit_sale', 'person_id' => $person->id, 'amount' => 1000, 'description' => 'test',
        ]);

        $this->assertSame(0, Transaction::query()->where('type', 'credit_sale')->count());
    }

    public function test_ordinary_user_cannot_create_cash_sale_through_generic_endpoint(): void
    {
        $this->actingAs($this->ordinaryUser());
        $person = $this->person('TTR Cash Sale Person');

        $this->assertTypeRejected([
            'type' => 'cash_sale', 'person_id' => $person->id, 'account_id' => $this->cash->id, 'amount' => 500, 'description' => 'test',
        ]);

        $this->assertSame(0, Transaction::query()->where('type', 'cash_sale')->count());
    }

    public function test_ordinary_user_cannot_create_purchase_through_generic_endpoint(): void
    {
        $this->actingAs($this->ordinaryUser());
        $supplier = Supplier::create(['supplier_code' => 'TTR-SUP-1', 'name' => 'TTR Supplier 1', 'is_active' => true]);

        $this->assertTypeRejected([
            'type' => 'purchase', 'supplier_id' => $supplier->id, 'amount' => 5000, 'description' => 'test',
        ]);

        $this->assertSame(0, Transaction::query()->where('type', 'purchase')->count());
    }

    public function test_ordinary_user_cannot_create_sale_return_through_generic_endpoint(): void
    {
        $this->actingAs($this->ordinaryUser());
        $person = $this->person('TTR Sale Return Person');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);
        $product = $this->product('TTR-SR-1');
        app(InventoryService::class)->record($product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $person->id, 'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');

        $this->assertTypeRejected([
            'type' => 'sale_return', 'person_id' => $person->id, 'sale_id' => $sale['id'], 'amount' => 999999, 'description' => 'test',
        ]);

        $this->assertSame(0, Transaction::query()->where('type', 'sale_return')->count());
    }

    public function test_ordinary_user_cannot_create_purchase_return_through_generic_endpoint(): void
    {
        $this->actingAs($this->ordinaryUser());
        $supplier = Supplier::create(['supplier_code' => 'TTR-SUP-2', 'name' => 'TTR Supplier 2', 'is_active' => true]);
        $product = $this->product('TTR-PR-1');

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 10]],
        ])->assertCreated()->json('purchase');

        $this->assertTypeRejected([
            'type' => 'purchase_return', 'supplier_id' => $supplier->id, 'purchase_id' => $purchase['id'], 'amount' => 999999, 'description' => 'test',
        ]);

        $this->assertSame(0, Transaction::query()->where('type', 'purchase_return')->count());
    }

    public function test_ordinary_user_cannot_create_any_opening_balance_type_directly(): void
    {
        $this->actingAs($this->ordinaryUser());
        $person = $this->person('TTR Opening Balance Person');
        $supplier = Supplier::create(['supplier_code' => 'TTR-SUP-3', 'name' => 'TTR Supplier 3', 'is_active' => true]);

        $this->assertTypeRejected([
            'type' => 'opening_balance_receivable', 'person_id' => $person->id, 'amount' => 5000, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'opening_balance_other_receivable', 'person_id' => $person->id, 'amount' => 5000, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'opening_balance_payable', 'supplier_id' => $supplier->id, 'amount' => 5000, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'opening_balance_loan_given', 'person_id' => $person->id, 'amount' => 5000, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'opening_balance_loan_received', 'person_id' => $person->id, 'amount' => 5000, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'opening_balance_capital', 'person_id' => $person->id, 'amount' => 5000, 'description' => 'test',
        ]);

        // Even a Super Admin cannot use this shortcut - the legitimate
        // workflow (with its reconciliation math) is the only way in.
        $this->actingAs($this->superAdmin());
        $this->assertTypeRejected([
            'type' => 'opening_balance_receivable', 'person_id' => $person->id, 'amount' => 5000, 'description' => 'test',
        ]);

        $this->assertSame(0, Transaction::query()->where('type', 'like', 'opening_balance_%')->count());
    }

    // A few more service-owned types beyond the minimum requested set,
    // since they were explicitly named in the audit finding.
    public function test_ordinary_user_cannot_create_customer_payment_supplier_payment_or_purchase_cost_directly(): void
    {
        $this->actingAs($this->ordinaryUser());
        $person = $this->person('TTR Payment Person');
        $supplier = Supplier::create(['supplier_code' => 'TTR-SUP-4', 'name' => 'TTR Supplier 4', 'is_active' => true]);

        $this->assertTypeRejected([
            'type' => 'customer_payment', 'person_id' => $person->id, 'account_id' => $this->cash->id, 'amount' => 100, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'supplier_payment', 'supplier_id' => $supplier->id, 'account_id' => $this->cash->id, 'amount' => 100, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'purchase_cost', 'account_id' => $this->cash->id, 'amount' => 100, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'sale_return_refund', 'person_id' => $person->id, 'account_id' => $this->cash->id, 'amount' => 100, 'description' => 'test',
        ]);
        $this->assertTypeRejected([
            'type' => 'purchase_return_refund', 'supplier_id' => $supplier->id, 'account_id' => $this->cash->id, 'amount' => 100, 'description' => 'test',
        ]);
    }

    // ================= STILL WORKS: legitimate internal paths =================

    public function test_legitimate_sale_service_creation_still_works(): void
    {
        $this->actingAs($this->ordinaryUser());
        $person = $this->person('TTR Legit Sale Person');
        $person->update(['is_customer' => true, 'roles' => ['customer']]);
        $product = $this->product('TTR-LS-1');
        app(InventoryService::class)->record($product, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);

        $this->postJson('/api/sales', [
            'customer_id' => $person->id, 'amount_paid' => 100, 'account_id' => $this->cash->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated();

        $this->assertSame(1, Transaction::query()->where('type', 'cash_sale')->count());
    }

    public function test_legitimate_purchase_service_creation_still_works(): void
    {
        $this->actingAs($this->ordinaryUser());
        $supplier = Supplier::create(['supplier_code' => 'TTR-SUP-5', 'name' => 'TTR Supplier 5', 'is_active' => true]);
        $product = $this->product('TTR-LP-1');

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10]],
        ])->assertCreated();

        $this->assertSame(1, Transaction::query()->where('type', 'purchase')->count());
    }

    public function test_legitimate_return_services_still_work(): void
    {
        $this->actingAs($this->ordinaryUser());

        // Sale return.
        $customer = $this->person('TTR Legit Sale Return Customer');
        $customer->update(['is_customer' => true, 'roles' => ['customer']]);
        $saleProduct = $this->product('TTR-LR-SALE-1');
        app(InventoryService::class)->record($saleProduct, 100, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 10);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['product_id' => $saleProduct->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'], 'reason' => 'Wrong item', 'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2, 'is_saleable' => true]],
        ])->assertCreated();

        $this->assertSame(1, Transaction::query()->where('type', 'sale_return')->count());

        // Purchase return.
        $supplier = Supplier::create(['supplier_code' => 'TTR-SUP-6', 'name' => 'TTR Supplier 6', 'is_active' => true]);
        $purchaseProduct = $this->product('TTR-LR-PURCH-1');

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'amount_paid' => 0,
            'items' => [['product_id' => $purchaseProduct->id, 'quantity' => 20, 'unit_cost' => 10]],
        ])->assertCreated()->json('purchase');

        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'], 'reason' => 'Damaged', 'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(1, Transaction::query()->where('type', 'purchase_return')->count());
    }

    public function test_legitimate_opening_balance_service_lock_still_works(): void
    {
        $this->actingAs($this->superAdmin());
        $person = $this->person('TTR Legit Opening Balance Person');

        $this->postJson('/api/admin/opening-balance', [
            'as_of_date' => '2026-01-01',
            'opening_equity' => 750,
            'items' => [['category' => 'receivable', 'person_id' => $person->id, 'amount' => 750]],
        ])->assertOk();

        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->assertSame(1, Transaction::query()->where('type', 'opening_balance_receivable')->count());
    }

    public function test_normal_publicly_creatable_types_still_work(): void
    {
        $this->actingAs($this->ordinaryUser());
        $person = $this->person('TTR Normal Types Person');

        $this->postJson('/api/transactions', [
            'type' => 'income', 'account_id' => $this->cash->id, 'amount' => 50, 'description' => 'test',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'expense', 'account_id' => $this->cash->id, 'amount' => 20, 'description' => 'test',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'loan_given', 'person_id' => $person->id, 'account_id' => $this->cash->id, 'amount' => 100, 'description' => 'test',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'loan_repayment', 'person_id' => $person->id, 'account_id' => $this->cash->id, 'amount' => 40, 'description' => 'test',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'debt_created', 'person_id' => $person->id, 'amount' => 100, 'description' => 'test',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'debt_payment', 'person_id' => $person->id, 'account_id' => $this->cash->id, 'amount' => 30, 'description' => 'test',
        ])->assertCreated();

        $destination = Account::create(['name' => 'TTR Cash 2', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
        $this->postJson('/api/transactions', [
            'type' => 'account_transfer', 'account_id' => $this->cash->id, 'destination_account_id' => $destination->id, 'amount' => 10, 'description' => 'test',
        ])->assertCreated();
    }

    public function test_owner_contribution_and_withdrawal_authorization_remains_intact(): void
    {
        $owner = $this->person('TTR Owner');
        $owner->update(['is_owner' => true, 'roles' => ['owner']]);

        // Ordinary User still forbidden (unrelated to the type restriction -
        // this is TransactionService::assertAuthorizedForCapitalMovement(),
        // untouched by this fix).
        $this->actingAs($this->ordinaryUser());
        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution', 'person_id' => $owner->id, 'account_id' => $this->cash->id, 'amount' => 1000, 'description' => 'test',
        ])->assertStatus(403);

        // Super Admin still allowed - owner_contribution/owner_withdrawal
        // remain publicly creatable (see TransactionType::
        // publiclyCreatable()), gated only by the capital-movement check.
        $this->actingAs($this->superAdmin());
        $this->postJson('/api/transactions', [
            'type' => 'owner_contribution', 'person_id' => $owner->id, 'account_id' => $this->cash->id, 'amount' => 1000, 'description' => 'test',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'type' => 'owner_withdrawal', 'person_id' => $owner->id, 'account_id' => $this->cash->id, 'amount' => 200, 'description' => 'test',
        ])->assertCreated();
    }
}

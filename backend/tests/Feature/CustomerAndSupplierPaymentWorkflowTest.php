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
 * Verifies the Dashboard's "Receive Payment" and "Pay Supplier" quick
 * actions use the REAL customer receivable / supplier payable workflow
 * (POST /people/{person}/payments -> CustomerPaymentService::receiveForCustomer,
 * POST /suppliers/{supplier}/payments -> SupplierPaymentService::payForSupplier)
 * rather than a generic or disconnected transaction. Both apply oldest-first
 * across the person's/supplier's real outstanding sales/purchases, so a
 * customer or supplier with MULTIPLE open invoices is paid down correctly,
 * not just a single hardcoded one.
 */
class CustomerAndSupplierPaymentWorkflowTest extends TestCase
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
            'name' => 'Dashboard Payment Rice',
            'sku' => 'DASH-PAY-RICE',
            'default_cost_price' => 15,
            'default_selling_price' => 25,
            'is_active' => true,
        ]);
        app(InventoryService::class)->record($this->rice, 500, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 15);

        $this->cash = Account::create([
            'name' => 'Dashboard Payment Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_receive_payment_reduces_the_customer_receivable_and_increases_the_account(): void
    {
        $customer = Person::create(['name' => 'Ahmed', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        // Ahmed owes 500 on a single credit sale.
        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 20, 'unit_price' => 25]],
        ])->assertCreated();

        $this->assertSame('500.00', $this->balances->customerReceivableBalance($customer->fresh()));

        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $response = $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 200,
            'account_id' => $this->cash->id,
        ])->assertOk()->json();

        $this->assertSame('200.00', $response['applied']);

        // Ahmed owes: $500 -> $300.
        $this->assertSame('300.00', $this->balances->customerReceivableBalance($customer->fresh()));
        // Account increases by exactly $200.
        $this->assertSame(
            number_format($cashBefore + 200, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
        );

        // A real customer_payment transaction was created, linked to the sale.
        $this->assertSame(1, \DB::table('transactions')->where('type', 'customer_payment')->where('person_id', $customer->id)->count());

        // Reports reflect it.
        $summary = $this->getJson('/api/reports/business-summary?range=this_year')->assertOk()->json();
        $this->assertSame('300.00', $summary['credit']['accounts_receivable']);
    }

    public function test_receive_payment_applies_across_multiple_outstanding_sales_oldest_first(): void
    {
        $customer = Person::create(['name' => 'Multi Sale Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $older = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->subDays(10)->toDateString(),
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 8, 'unit_price' => 25]],
        ])->assertCreated()->json('sale'); // 200 owed

        $newer = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->subDays(1)->toDateString(),
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 8, 'unit_price' => 25]],
        ])->assertCreated()->json('sale'); // 200 owed

        $this->assertSame('400.00', $this->balances->customerReceivableBalance($customer->fresh()));

        // Pay 250 - should fully clear the older sale (200) and leave 50
        // applied to the newer one.
        $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 250,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $olderSale = $this->getJson("/api/sales/{$older['id']}")->assertOk()->json();
        $newerSale = $this->getJson("/api/sales/{$newer['id']}")->assertOk()->json();

        $this->assertSame('0.00', $olderSale['balance_due'], 'The OLDER sale must be paid off first.');
        $this->assertSame('paid', $olderSale['payment_status']);
        $this->assertSame('150.00', $newerSale['balance_due'], '200 - 50 applied = 150 remaining.');
        $this->assertSame('partial', $newerSale['payment_status']);

        $this->assertSame('150.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    public function test_receive_payment_cannot_exceed_the_customers_total_outstanding_balance(): void
    {
        $customer = Person::create(['name' => 'Overpay Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 8, 'unit_price' => 25]],
        ])->assertCreated();

        $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 5000,
            'account_id' => $this->cash->id,
        ])->assertStatus(422);

        $this->assertSame('200.00', $this->balances->customerReceivableBalance($customer->fresh()), 'Rejected overpayment must not change the balance.');
    }

    public function test_receive_payment_is_rejected_for_a_customer_with_no_outstanding_balance(): void
    {
        $customer = Person::create(['name' => 'No Balance Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 100,
            'account_id' => $this->cash->id,
        ])->assertStatus(422);
    }

    public function test_pay_supplier_reduces_the_payable_and_decreases_the_account(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'DASH-PAY-SUP', 'name' => 'Dashboard Payment Supplier', 'is_active' => true]);
        $fundedCash = Account::create(['name' => 'Funded Cash', 'type' => 'cash', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => true]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 40, 'unit_cost' => 20]],
        ])->assertCreated(); // 800 payable

        $this->assertSame('800.00', $this->balances->supplierBalance($supplier->fresh()));

        $cashBefore = (float) $this->balances->accountBalance($fundedCash->fresh());

        $response = $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 300,
            'account_id' => $fundedCash->id,
        ])->assertOk()->json();

        $this->assertSame('300.00', $response['applied']);
        $this->assertSame('500.00', $this->balances->supplierBalance($supplier->fresh()), '800 - 300 = 500.');
        $this->assertSame(
            number_format($cashBefore - 300, 2, '.', ''),
            $this->balances->accountBalance($fundedCash->fresh()),
            'Paying a supplier must DECREASE the account.',
        );

        $this->assertSame(1, \DB::table('transactions')->where('type', 'supplier_payment')->where('supplier_id', $supplier->id)->count());

        $summary = $this->getJson('/api/reports/business-summary?range=this_year')->assertOk()->json();
        $this->assertSame('500.00', $summary['credit']['accounts_payable']);
    }

    public function test_pay_supplier_applies_across_multiple_outstanding_purchases_oldest_first(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'MULTI-PUR-SUP', 'name' => 'Multi Purchase Supplier', 'is_active' => true]);
        $fundedCash = Account::create(['name' => 'Multi Purchase Cash', 'type' => 'cash', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => true]);

        $older = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->subDays(10)->toDateString(),
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase'); // 200 owed

        $newer = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->subDays(1)->toDateString(),
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase'); // 200 owed

        $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 250,
            'account_id' => $fundedCash->id,
        ])->assertOk();

        $olderPurchase = $this->getJson("/api/purchases/{$older['id']}")->assertOk()->json();
        $newerPurchase = $this->getJson("/api/purchases/{$newer['id']}")->assertOk()->json();

        $this->assertSame('0.00', $olderPurchase['balance_due']);
        $this->assertSame('paid', $olderPurchase['payment_status']);
        $this->assertSame('150.00', $newerPurchase['balance_due']);
        $this->assertSame('partial', $newerPurchase['payment_status']);
    }

    public function test_pay_supplier_cannot_exceed_the_total_outstanding_balance(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'OVERPAY-SUP', 'name' => 'Overpay Supplier', 'is_active' => true]);
        $fundedCash = Account::create(['name' => 'Overpay Supplier Cash', 'type' => 'cash', 'opening_balance' => 1000, 'currency' => 'USD', 'is_active' => true]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_cost' => 20]],
        ])->assertCreated();

        $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 5000,
            'account_id' => $fundedCash->id,
        ])->assertStatus(422);

        $this->assertSame('200.00', $this->balances->supplierBalance($supplier->fresh()));
    }
}

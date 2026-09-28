<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use App\Services\OpeningBalanceService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The confirmed Opening Balance payment gap: CustomerPaymentService::
 * receiveForCustomer() and SupplierPaymentService::payForSupplier() used to
 * look only at Sale::balance_due / Purchase::balance_due, so a customer or
 * supplier whose entire debt was an opening balance (no Sale/Purchase row
 * behind it) could never be paid down through the normal payment flow. Both
 * now use the real aggregate balance (BalanceService) as the ceiling and
 * apply any leftover, once every real Sale/Purchase is settled, as one
 * standalone customer_payment/supplier_payment - never a fabricated Sale
 * or Purchase.
 */
class OpeningBalancePaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private OpeningBalanceService $openingBalances;
    private InventoryService $inventory;
    private Account $cash;
    private Product $product;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->userId = $user->id;

        $this->balances = app(BalanceService::class);
        $this->openingBalances = app(OpeningBalanceService::class);
        $this->inventory = app(InventoryService::class);

        $this->cash = Account::create([
            'name' => 'OB Payment Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'OB Payment Bag ' . uniqid(), 'abbreviation' => 'obpb' . uniqid()]);
        $this->product = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'OB Payment Rice',
            'sku' => 'OBPAY-RICE-' . uniqid(),
            'default_cost_price' => 20,
            'default_selling_price' => 30,
            'is_active' => true,
        ]);
    }

    private function lockOpeningReceivable(Person $customer, float $amount): void
    {
        $this->openingBalances->saveDraft([
            'as_of_date' => '2020-01-01',
            'opening_equity' => $amount,
            'items' => [['category' => 'receivable', 'person_id' => $customer->id, 'amount' => $amount]],
        ], $this->userId);

        $this->openingBalances->lock($this->userId);
    }

    private function lockOpeningPayable(Supplier $supplier, float $amount): void
    {
        $this->openingBalances->saveDraft([
            'as_of_date' => '2020-01-01',
            'opening_equity' => -$amount,
            'items' => [['category' => 'payable', 'supplier_id' => $supplier->id, 'amount' => $amount]],
        ], $this->userId);

        $this->openingBalances->lock($this->userId);
    }

    // unit_price/unit_cost of 20 keeps every $total in these tests an
    // exact whole-number quantity (total / 20), so no test needs to worry
    // about fractional-quantity rounding.
    private function creditSale(Person $customer, float $total): array
    {
        $quantity = $total / 20;

        // A sale needs an existing cost basis to sell against - stocked via
        // a real, throwaway purchase dated safely after the opening
        // balance (2020-01-02) but before the sale itself (2020-06-01).
        $this->postJson('/api/purchases', [
            'supplier_id' => Supplier::create([
                'supplier_code' => 'STOCK-SUP-' . uniqid(), 'name' => 'Stocking Supplier', 'is_active' => true,
            ])->id,
            'amount_paid' => 0,
            'purchase_date' => '2020-01-02',
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => 15]],
        ])->assertCreated();

        return $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'sale_date' => '2020-06-01',
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');
    }

    private function creditPurchase(Supplier $supplier, float $total): array
    {
        return $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'amount_paid' => 0,
            'purchase_date' => '2020-06-01',
            'items' => [['product_id' => $this->product->id, 'quantity' => $total / 20, 'unit_cost' => 20]],
        ])->assertCreated()->json('purchase');
    }

    // --- 1. Payment against opening customer receivable only ---

    public function test_payment_against_opening_customer_receivable_only(): void
    {
        $customer = Person::create(['name' => 'OB Pay Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->lockOpeningReceivable($customer, 5000);

        $response = $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 1000,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('1000.00', $response->json('applied'));
        $this->assertSame('4000.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 2. Payment against opening supplier payable only ---

    public function test_payment_against_opening_supplier_payable_only(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'OBPAY-SUP-' . uniqid(), 'name' => 'OB Pay Supplier', 'is_active' => true]);
        $this->lockOpeningPayable($supplier, 5000);

        $response = $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 1000,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('1000.00', $response->json('applied'));
        $this->assertSame('4000.00', $this->balances->supplierBalance($supplier->fresh()));
    }

    // --- 3. Mixed opening receivable + real sale ---

    public function test_mixed_opening_receivable_and_real_sale(): void
    {
        $customer = Person::create(['name' => 'Mixed Receivable Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->lockOpeningReceivable($customer, 5000);
        $sale = $this->creditSale($customer, 2000);

        $this->assertSame('7000.00', $this->balances->customerReceivableBalance($customer->fresh()));

        $response = $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 3000,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('3000.00', $response->json('applied'));
        $this->assertSame('1000.00', $response->json('applied_to_opening_balance'), '2000 to the real sale, 1000 leftover.');
        $this->assertSame('0.00', \App\Models\Sale::find($sale['id'])->balance_due, 'The real sale is fully settled first.');
        $this->assertSame('4000.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 4. Mixed opening payable + real purchase ---

    public function test_mixed_opening_payable_and_real_purchase(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'MIXED-SUP-' . uniqid(), 'name' => 'Mixed Payable Supplier', 'is_active' => true]);
        $this->lockOpeningPayable($supplier, 5000);
        $purchase = $this->creditPurchase($supplier, 2000);

        $this->assertSame('7000.00', $this->balances->supplierBalance($supplier->fresh()));

        $response = $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 3000,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('3000.00', $response->json('applied'));
        $this->assertSame('1000.00', $response->json('applied_to_opening_balance'));
        $this->assertSame('0.00', \App\Models\Purchase::find($purchase['id'])->balance_due);
        $this->assertSame('4000.00', $this->balances->supplierBalance($supplier->fresh()));
    }

    // --- 5. Payment larger than real sales but within aggregate customer balance ---

    public function test_payment_larger_than_real_sales_but_within_aggregate_customer_balance(): void
    {
        $customer = Person::create(['name' => 'Larger Than Sales Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->lockOpeningReceivable($customer, 5000);
        $this->creditSale($customer, 2000); // aggregate = 7000

        $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 6000, // exceeds the 2000 real sale total, within the 7000 aggregate
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('1000.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 6. Payment larger than real purchases but within aggregate supplier balance ---

    public function test_payment_larger_than_real_purchases_but_within_aggregate_supplier_balance(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'LARGER-SUP-' . uniqid(), 'name' => 'Larger Than Purchases Supplier', 'is_active' => true]);
        $this->lockOpeningPayable($supplier, 5000);
        $this->creditPurchase($supplier, 2000); // aggregate = 7000

        $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 6000,
            'account_id' => $this->cash->id,
        ])->assertOk();

        $this->assertSame('1000.00', $this->balances->supplierBalance($supplier->fresh()));
    }

    // --- 7. Payment exceeding aggregate customer balance is rejected ---

    public function test_payment_exceeding_aggregate_customer_balance_is_rejected(): void
    {
        $customer = Person::create(['name' => 'Exceeding Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->lockOpeningReceivable($customer, 5000);

        $this->postJson("/api/people/{$customer->id}/payments", [
            'amount' => 5000.01,
            'account_id' => $this->cash->id,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame('5000.00', $this->balances->customerReceivableBalance($customer->fresh()), 'A rejected payment must not partially apply.');
    }

    // --- 8. Payment exceeding aggregate supplier balance is rejected ---

    public function test_payment_exceeding_aggregate_supplier_balance_is_rejected(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'EXCEED-SUP-' . uniqid(), 'name' => 'Exceeding Supplier', 'is_active' => true]);
        $this->lockOpeningPayable($supplier, 5000);

        $this->postJson("/api/suppliers/{$supplier->id}/payments", [
            'amount' => 5000.01,
            'account_id' => $this->cash->id,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame('5000.00', $this->balances->supplierBalance($supplier->fresh()));
    }

    // --- 9. Correct account balance effect ---

    public function test_correct_account_balance_effect_for_customer_and_supplier_payments(): void
    {
        $customer = Person::create(['name' => 'Account Effect Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->lockOpeningReceivable($customer, 5000);
        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $this->postJson("/api/people/{$customer->id}/payments", ['amount' => 1000, 'account_id' => $this->cash->id])->assertOk();

        $this->assertSame(number_format($cashBefore + 1000, 2, '.', ''), $this->balances->accountBalance($this->cash->fresh()));

        $supplier = Supplier::create(['supplier_code' => 'ACCT-SUP-' . uniqid(), 'name' => 'Account Effect Supplier', 'is_active' => true]);
        $this->lockOpeningPayableOnExistingRecord($supplier, 2000);
        $cashBeforeSupplierPayment = (float) $this->balances->accountBalance($this->cash->fresh());

        $this->postJson("/api/suppliers/{$supplier->id}/payments", ['amount' => 500, 'account_id' => $this->cash->id])->assertOk();

        $this->assertSame(number_format($cashBeforeSupplierPayment - 500, 2, '.', ''), $this->balances->accountBalance($this->cash->fresh()));
    }

    /**
     * Only one OpeningBalance record may ever exist - this test needs a
     * second opening item after the first is already locked, so it reopens,
     * adds the item, and relocks rather than creating a second record.
     */
    private function lockOpeningPayableOnExistingRecord(Supplier $supplier, float $amount): void
    {
        // Captured BEFORE reopen() - it deletes the record's items once
        // they're reversed, so they must be read first.
        $existing = $this->openingBalances->current()->load('items');
        $existingItems = $existing->items->map(fn ($item) => [
            'category' => $item->category,
            'person_id' => $item->person_id,
            'supplier_id' => $item->supplier_id,
            'product_id' => $item->product_id,
            'product_unit_id' => $item->product_unit_id,
            'quantity' => $item->quantity,
            'unit_cost' => $item->unit_cost,
            'amount' => $item->amount,
        ])->all();

        $this->openingBalances->reopen($this->userId);

        $this->openingBalances->saveDraft([
            'as_of_date' => '2020-01-01',
            'opening_equity' => (float) $existing->opening_equity - $amount,
            'items' => [
                ...$existingItems,
                ['category' => 'payable', 'supplier_id' => $supplier->id, 'amount' => $amount],
            ],
        ], $this->userId);

        $this->openingBalances->lock($this->userId);
    }

    // --- 10. Correct remaining person/supplier balance (multi-part payment) ---

    public function test_correct_remaining_balance_after_multiple_partial_payments(): void
    {
        $customer = Person::create(['name' => 'Multi Payment Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->lockOpeningReceivable($customer, 5000);

        $this->postJson("/api/people/{$customer->id}/payments", ['amount' => 1000, 'account_id' => $this->cash->id])->assertOk();
        $this->postJson("/api/people/{$customer->id}/payments", ['amount' => 1500, 'account_id' => $this->cash->id])->assertOk();

        $this->assertSame('2500.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 11. No fake Sale/Purchase created ---

    public function test_no_fake_sale_or_purchase_is_created_for_a_standalone_payment(): void
    {
        $customer = Person::create(['name' => 'No Fake Sale Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->lockOpeningReceivable($customer, 5000);

        $this->postJson("/api/people/{$customer->id}/payments", ['amount' => 1000, 'account_id' => $this->cash->id])->assertOk();

        $this->assertSame(0, \App\Models\Sale::query()->where('customer_id', $customer->id)->count());

        $transaction = Transaction::query()->where('person_id', $customer->id)->where('type', 'customer_payment')->first();
        $this->assertNotNull($transaction);
        $this->assertNull($transaction->sale_id, 'The standalone payment must never be attached to a sale_id.');
    }

    public function test_no_fake_purchase_is_created_for_a_standalone_supplier_payment(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'NOFAKE-SUP-' . uniqid(), 'name' => 'No Fake Purchase Supplier', 'is_active' => true]);
        $this->lockOpeningPayable($supplier, 5000);

        $this->postJson("/api/suppliers/{$supplier->id}/payments", ['amount' => 1000, 'account_id' => $this->cash->id])->assertOk();

        $this->assertSame(0, \App\Models\Purchase::query()->where('supplier_id', $supplier->id)->count());

        $transaction = Transaction::query()->where('supplier_id', $supplier->id)->where('type', 'supplier_payment')->first();
        $this->assertNotNull($transaction);
        $this->assertNull($transaction->purchase_id, 'The standalone payment must never be attached to a purchase_id.');
    }

    // --- 12. Standalone payment gets normal transaction/audit record ---

    public function test_standalone_payment_is_audited_like_any_other_transaction(): void
    {
        $customer = Person::create(['name' => 'Audited Payment Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $this->lockOpeningReceivable($customer, 5000);

        $before = \App\Models\AuditLog::query()->where('action', 'transaction_created')->count();

        $this->postJson("/api/people/{$customer->id}/payments", ['amount' => 1000, 'account_id' => $this->cash->id])->assertOk();

        $this->assertSame($before + 1, \App\Models\AuditLog::query()->where('action', 'transaction_created')->count());

        $entry = \App\Models\AuditLog::query()->where('action', 'transaction_created')->latest('id')->first();
        $this->assertSame('customer_payment', $entry->new_values['type']);
        $this->assertSame('1000.00', $entry->new_values['amount']);
    }

    // --- 13. Existing oldest-first allocation remains unchanged ---

    public function test_oldest_first_allocation_across_multiple_real_sales_is_unchanged(): void
    {
        $customer = Person::create(['name' => 'Oldest First Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $this->postJson('/api/purchases', [
            'supplier_id' => Supplier::create([
                'supplier_code' => 'STOCK-SUP2-' . uniqid(), 'name' => 'Stocking Supplier 2', 'is_active' => true,
            ])->id,
            'amount_paid' => 0,
            'purchase_date' => '2020-01-01',
            'items' => [['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 15]],
        ])->assertCreated();

        $oldSale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'sale_date' => '2020-01-15',
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 30]],
        ])->assertCreated()->json('sale');

        $newSale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'amount_paid' => 0,
            'sale_date' => '2020-03-01',
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 30]],
        ])->assertCreated()->json('sale');

        // 300 owed on each (600 total). Pay 400 - the older sale must be
        // fully settled first, then 100 applied to the newer one.
        $this->postJson("/api/people/{$customer->id}/payments", ['amount' => 400, 'account_id' => $this->cash->id])->assertOk();

        $this->assertSame('0.00', \App\Models\Sale::find($oldSale['id'])->balance_due);
        $this->assertSame('200.00', \App\Models\Sale::find($newSale['id'])->balance_due);
    }
}

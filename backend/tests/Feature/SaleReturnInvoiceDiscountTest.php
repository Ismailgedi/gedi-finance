<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C3 (accounting audit): a sale return used to prorate against
 * sale_items.line_total alone, which already bakes in any PER-LINE
 * discount but never the INVOICE-LEVEL discount (sales.discount, applied
 * once across the whole sale - see SaleService::create(), where
 * sales.total = sales.subtotal - sales.discount but
 * SUM(sale_items.line_total) == sales.subtotal, never sales.total). A
 * return therefore credited/refunded the customer for their
 * pre-invoice-discount share, overstating the credit/refund and corrupting
 * sales.total/gross_profit by exactly the discount amount.
 *
 * The fix (SaleReturnService::create()): scale each return line by
 * (sales.subtotal - sales.discount) / sales.subtotal - a ratio computed
 * from sales.subtotal/sales.discount, which a return never modifies (they
 * stay frozen at their sale-creation values), so the ratio is stable
 * across any number of partial returns.
 */
class SaleReturnInvoiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private Product $product;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->balances = app(BalanceService::class);

        $unit = Unit::create(['name' => 'C3 Unit', 'abbreviation' => 'c3u' . uniqid()]);
        $this->product = Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'C3 Product',
            'sku' => 'C3-' . uniqid(),
            'default_cost_price' => 6,
            'default_selling_price' => 10,
            'is_active' => true,
        ]);
        app(InventoryService::class)->record($this->product, 1000, null, 'purchase', 'purchase', null, null, 'Stock', null, 'in', 6);

        $this->cash = Account::create(['name' => 'C3 Cash', 'type' => 'cash', 'opening_balance' => 0, 'currency' => 'USD', 'is_active' => true]);
    }

    private function customer(string $name): Person
    {
        return Person::create(['name' => $name, 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
    }

    /** @return array{sale: array, saleItemId: int} */
    private function sale(Person $customer, float $quantity, float $unitPrice, float $discount, float $amountPaid = 0): array
    {
        $payload = [
            'customer_id' => $customer->id,
            'amount_paid' => $amountPaid,
            'discount' => $discount,
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
        ];

        if ($amountPaid > 0) {
            $payload['account_id'] = $this->cash->id;
        }

        $sale = $this->postJson('/api/sales', $payload)->assertCreated()->json('sale');

        return ['sale' => $sale, 'saleItemId' => $sale['items'][0]['id']];
    }

    private function returnItems(int $saleId, int $saleItemId, float $quantity, string $settlement = 'credit', ?int $refundAccountId = null): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'sale_id' => $saleId,
            'reason' => 'Customer changed their mind',
            'settlement_method' => $settlement,
            'items' => [['sale_item_id' => $saleItemId, 'quantity' => $quantity, 'is_saleable' => true]],
        ];

        if ($refundAccountId) {
            $payload['refund_account_id'] = $refundAccountId;
        }

        return $this->postJson('/api/sale-returns', $payload);
    }

    // --- 1. No invoice discount + full return: unaffected by the fix
    // (ratio = 1.0), matches the original, already-correct behavior. ---
    public function test_no_invoice_discount_full_return(): void
    {
        $customer = $this->customer('C3 No Discount Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->sale($customer, 10, 10, 0);
        // total = 100, unpaid.

        $this->returnItems($sale['id'], $itemId, 10)->assertCreated();

        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('0.00', $updated['total']);
        $this->assertSame('0.00', $updated['gross_profit']);
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 2. Invoice discount + full return: must produce the discounted
    // total ($90), not the pre-discount value ($100). ---
    public function test_invoice_discount_full_return(): void
    {
        $customer = $this->customer('C3 Full Return Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->sale($customer, 10, 10, 10);
        // subtotal = 100, discount = 10, total = 90, unpaid.

        $this->assertSame('90.00', $sale['total']);
        $this->assertSame('90.00', $this->balances->customerReceivableBalance($customer->fresh()));

        $this->returnItems($sale['id'], $itemId, 10)->assertCreated();

        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();

        // Must be 0, never -10 (which is what returning the full
        // pre-discount $100 against a $90 total would produce).
        $this->assertSame('0.00', $updated['total']);
        $this->assertSame('0.00', $updated['gross_profit']);
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 3. Invoice discount + partial return: must receive the correct
    // proportional share of the actual discounted sale value. ---
    public function test_invoice_discount_partial_return(): void
    {
        $customer = $this->customer('C3 Partial Return Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->sale($customer, 10, 10, 10);
        // subtotal = 100, discount = 10, total = 90.

        // Returning 4 of 10 units must credit 4/10 of the discounted $90
        // total = $36, never 4/10 of the pre-discount $100 (= $40).
        $response = $this->returnItems($sale['id'], $itemId, 4)->assertCreated();
        $this->assertSame('36.00', $response->json('return.total_value'));

        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('54.00', $updated['total'], '90 - 36 = 54.');
        // COGS/gross profit are independent of the discount (cost is always
        // the product's own weighted-average cost) - only the discount
        // ratio changes the receivable/revenue side. Original COGS was
        // 10 * $6 = 60; returning 4 units removes 4 * $6 = 24 of that.
        $this->assertSame('36.00', $updated['cost_of_goods_sold'], '60 - 24 = 36.');
        $this->assertSame('18.00', $updated['gross_profit'], '54 - 36 = 18.');
    }

    // --- 4. Invoice discount + multiple partial returns: the ratio must
    // stay stable across returns, never compounding. ---
    public function test_invoice_discount_multiple_partial_returns(): void
    {
        $customer = $this->customer('C3 Multiple Returns Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->sale($customer, 10, 10, 10);
        // subtotal = 100, discount = 10, total = 90.

        // First return: 3 of 10 -> 3/10 * 90 = 27.
        $first = $this->returnItems($sale['id'], $itemId, 3)->assertCreated();
        $this->assertSame('27.00', $first->json('return.total_value'));

        // Second return: 2 more of the remaining 7 -> 2/10 * 90 = 18, not
        // recomputed against an already-shrunk total.
        $second = $this->returnItems($sale['id'], $itemId, 2)->assertCreated();
        $this->assertSame('18.00', $second->json('return.total_value'));

        // Third return: the remaining 5 -> 5/10 * 90 = 45.
        $third = $this->returnItems($sale['id'], $itemId, 5)->assertCreated();
        $this->assertSame('45.00', $third->json('return.total_value'));

        // 27 + 18 + 45 = 90 exactly - the full discounted total, fully
        // accounted for with no compounding drift.
        $updated = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('0.00', $updated['total']);
        $this->assertSame('0.00', $updated['gross_profit']);
    }

    // --- 5. Credit settlement with an invoice discount, exceeding the
    // outstanding balance -> the excess becomes a customer credit, sized
    // off the discounted value. ---
    public function test_invoice_discount_credit_settlement_produces_correct_customer_credit(): void
    {
        $customer = $this->customer('C3 Credit Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->sale($customer, 10, 10, 10, amountPaid: 90);
        // subtotal = 100, discount = 10, total = 90, fully paid.

        $this->returnItems($sale['id'], $itemId, 10, 'credit')->assertCreated();

        // Full return of a fully-paid, discounted sale -> a $90 customer
        // credit, never $100 (which would be the pre-discount value).
        $this->assertSame('-90.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 6. Refund settlement with an invoice discount: the refund must
    // be sized off the discounted value too. ---
    public function test_invoice_discount_refund_settlement_refunds_the_discounted_value(): void
    {
        $customer = $this->customer('C3 Refund Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->sale($customer, 10, 10, 10, amountPaid: 90);
        // subtotal = 100, discount = 10, total = 90, fully paid.

        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $this->returnItems($sale['id'], $itemId, 10, 'refund', $this->cash->id)->assertCreated();

        // Refund must be exactly $90 (the discounted value already paid),
        // never $100.
        $this->assertSame(
            number_format($cashBefore - 90, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
        );
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 7. Customer credit from a partial return with an invoice
    // discount, where the return exceeds what's currently outstanding. ---
    public function test_invoice_discount_partial_return_exceeding_outstanding_becomes_credit(): void
    {
        $customer = $this->customer('C3 Exceeding Customer');
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->sale($customer, 10, 10, 10, amountPaid: 90);
        // subtotal = 100, discount = 10, total = 90, fully paid (nothing
        // outstanding).

        // Returning 5 of 10 units -> 5/10 * 90 = 45, all of which becomes a
        // customer credit since nothing was outstanding to apply it to.
        $this->returnItems($sale['id'], $itemId, 5, 'credit')->assertCreated();

        $this->assertSame('-45.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }
}

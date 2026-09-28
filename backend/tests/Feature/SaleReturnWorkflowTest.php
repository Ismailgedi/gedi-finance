<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer returns against a specific, already-posted sale - never the
 * whole-sale void (SaleVoidWorkflowTest covers that). Settlement always
 * pays down whatever is still outstanding on the sale first; only the
 * excess becomes a cash refund or a customer credit (a negative person
 * balance - no separate ledger). See SaleReturnService's own doc comment.
 */
class SaleReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private InventoryService $inventory;
    private Product $rice;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->balances = app(BalanceService::class);
        $this->inventory = app(InventoryService::class);

        $bag = Unit::create(['name' => 'Return Bag', 'abbreviation' => 'rbag' . uniqid()]);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Return Test Rice',
            'sku' => 'RET-RICE-' . uniqid(),
            'default_cost_price' => 25,
            'default_selling_price' => 40,
            'is_active' => true,
        ]);
        $this->inventory->record($this->rice, 1000, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 25);

        $this->cash = Account::create([
            'name' => 'Return Test Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function customer(string $name = 'Return Test Customer'): Person
    {
        return Person::create(['name' => $name, 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
    }

    /** @return array{sale: array, saleItemId: int} */
    private function creditSale(Person $customer, float $quantity = 10, float $unitPrice = 40, float $amountPaid = 0, ?Account $account = null): array
    {
        $payload = [
            'customer_id' => $customer->id,
            'amount_paid' => $amountPaid,
            'items' => [['product_id' => $this->rice->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
        ];

        if ($amountPaid > 0) {
            $payload['account_id'] = ($account ?? $this->cash)->id;
        }

        $sale = $this->postJson('/api/sales', $payload)->assertCreated()->json('sale');

        return ['sale' => $sale, 'saleItemId' => $sale['items'][0]['id']];
    }

    private function returnItems(int $saleId, int $saleItemId, float $quantity, string $settlement = 'credit', ?int $refundAccountId = null, bool $isSaleable = true, string $reason = 'Customer changed their mind'): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'sale_id' => $saleId,
            'reason' => $reason,
            'settlement_method' => $settlement,
            'items' => [['sale_item_id' => $saleItemId, 'quantity' => $quantity, 'is_saleable' => $isSaleable]],
        ];

        if ($refundAccountId) {
            $payload['refund_account_id'] = $refundAccountId;
        }

        return $this->postJson('/api/sale-returns', $payload);
    }

    // --- 1. Full unpaid customer return ---

    public function test_full_unpaid_customer_return(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $response = $this->returnItems($sale['id'], $itemId, 10)->assertCreated();

        $this->assertSame('400.00', $response->json('return.total_value'));
        $this->assertSame('400.00', $response->json('return.applied_to_receivable'));
        $this->assertSame('0.00', $response->json('return.refund_amount'));

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('0.00', $updatedSale['total']);
        $this->assertSame('0.00', $updatedSale['cost_of_goods_sold']);
        $this->assertSame('0.00', $updatedSale['gross_profit']);
        $this->assertSame('0.00', $updatedSale['balance_due']);
        $this->assertSame('paid', $updatedSale['payment_status']);

        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 2. Partial unpaid customer return ---

    public function test_partial_unpaid_customer_return(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $this->returnItems($sale['id'], $itemId, 2)->assertCreated();

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('320.00', $updatedSale['total']); // 400 - 80
        $this->assertSame('200.00', $updatedSale['cost_of_goods_sold']); // 250 - 50
        $this->assertSame('120.00', $updatedSale['gross_profit']);
        $this->assertSame('320.00', $updatedSale['balance_due']);
        $this->assertSame('unpaid', $updatedSale['payment_status']);

        $this->assertSame('320.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 3. Partial paid customer return where return <= outstanding balance ---

    public function test_partial_paid_return_within_outstanding_balance(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 300); // total 400, paid 300, outstanding 100

        $this->returnItems($sale['id'], $itemId, 2)->assertCreated(); // return value 80 <= 100 outstanding

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('320.00', $updatedSale['total']);
        $this->assertSame('300.00', $updatedSale['amount_paid']); // unchanged - no refund needed
        $this->assertSame('20.00', $updatedSale['balance_due']);
        $this->assertSame('partial', $updatedSale['payment_status']);
    }

    // --- 4. Partial paid customer return where return > outstanding balance ---

    public function test_partial_paid_return_exceeding_outstanding_becomes_credit(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 300); // outstanding 100

        // Return 5 bags = $200, exceeding the $100 still outstanding.
        $response = $this->returnItems($sale['id'], $itemId, 5, 'credit')->assertCreated();

        $this->assertSame('200.00', $response->json('return.total_value'));
        $this->assertSame('100.00', $response->json('return.applied_to_receivable'));
        $this->assertSame('0.00', $response->json('return.refund_amount'));
        $this->assertSame('100.00', $response->json('return.credited_amount'));

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('200.00', $updatedSale['total']);
        $this->assertSame('300.00', $updatedSale['amount_paid']); // unchanged - no refund
        $this->assertSame('-100.00', $updatedSale['balance_due']); // credit visible on the invoice itself

        // The worked example from the spec, verified precisely: only the
        // $100 excess is ever a distinct settling amount - never the full
        // $200 return value.
        $this->assertSame(
            '-100.00',
            $this->balances->customerReceivableBalance($customer->fresh()),
            'Final customer balance must be a $100 credit, not $200.',
        );
    }

    // --- Worked example: same scenario, settled with a cash refund instead ---

    public function test_partial_paid_return_exceeding_outstanding_with_cash_refund_only_refunds_the_excess(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 300); // outstanding 100
        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $response = $this->returnItems($sale['id'], $itemId, 5, 'refund', $this->cash->id)->assertCreated();

        $this->assertSame('100.00', $response->json('return.refund_amount'));
        $this->assertSame('0.00', $response->json('return.credited_amount'));

        // sale_return = -$200 person effect, sale_return_refund = +$100
        // person effect / -$100 account effect - never a single -$200 (or
        // -$100 only, skipping the full-value step) transaction.
        $this->assertSame(
            '-200.00',
            number_format((float) DB::table('transactions')->where('sale_id', $sale['id'])->where('type', 'sale_return')->value('person_balance_effect'), 2, '.', ''),
        );
        $refundTxn = DB::table('transactions')->where('sale_id', $sale['id'])->where('type', 'sale_return_refund')->first();
        $this->assertSame('100.00', number_format((float) $refundTxn->person_balance_effect, 2, '.', ''));
        $this->assertSame('-100.00', number_format((float) $refundTxn->account_balance_effect, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $refundTxn->amount, 2, '.', ''));

        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()), 'Final customer balance must be $0.');
        $this->assertSame(
            number_format($cashBefore - 100, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
        );
    }

    // --- 5. Fully paid customer return with cash refund ---

    public function test_fully_paid_customer_return_with_cash_refund(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 400); // fully paid
        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $this->returnItems($sale['id'], $itemId, 2, 'refund', $this->cash->id)->assertCreated();

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('320.00', $updatedSale['total']);
        $this->assertSame('320.00', $updatedSale['amount_paid']); // 400 - 80 refunded
        $this->assertSame('0.00', $updatedSale['balance_due']);
        $this->assertSame('paid', $updatedSale['payment_status']);

        $this->assertSame(
            number_format($cashBefore - 80, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
        );
        $this->assertSame('0.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 6. Fully paid customer return with customer credit ---

    public function test_fully_paid_customer_return_with_customer_credit(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 400); // fully paid
        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $this->returnItems($sale['id'], $itemId, 2, 'credit')->assertCreated();

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('320.00', $updatedSale['total']);
        $this->assertSame('400.00', $updatedSale['amount_paid']); // unchanged - no cash left the business
        $this->assertSame('-80.00', $updatedSale['balance_due']); // credit

        // No cash movement at all.
        $this->assertSame(number_format($cashBefore, 2, '.', ''), $this->balances->accountBalance($this->cash->fresh()));
        $this->assertSame('-80.00', $this->balances->customerReceivableBalance($customer->fresh()));
    }

    // --- 7. Cash-sale return ---

    public function test_cash_sale_return(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 400); // amount_paid >= total -> cash_sale

        $this->assertSame('cash_sale', DB::table('transactions')->where('sale_id', $sale['id'])->value('type'));

        $this->returnItems($sale['id'], $itemId, 3, 'credit')->assertCreated();

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('280.00', $updatedSale['total']); // 400 - 120
    }

    // --- 8. Credit-sale return ---

    public function test_credit_sale_return(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $this->assertSame('credit_sale', DB::table('transactions')->where('sale_id', $sale['id'])->value('type'));

        $this->returnItems($sale['id'], $itemId, 3, 'credit')->assertCreated();

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('280.00', $updatedSale['total']);
        $this->assertSame('280.00', $updatedSale['balance_due']);
    }

    // --- 9. Multiple partial returns ---

    public function test_multiple_partial_returns_accumulate_correctly(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $this->returnItems($sale['id'], $itemId, 2)->assertCreated();
        $this->returnItems($sale['id'], $itemId, 3)->assertCreated();

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('200.00', $updatedSale['total']); // 400 - 80 - 120
        $this->assertCount(2, $updatedSale['returns']);

        $this->assertSame(
            '5.0000',
            number_format((float) DB::table('sale_return_items')->where('sale_item_id', $itemId)->sum('quantity'), 4, '.', ''),
        );
    }

    // --- 10. Over-return blocked ---

    public function test_over_return_is_blocked(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $this->returnItems($sale['id'], $itemId, 7)->assertCreated();
        // Only 3 remain returnable - requesting 4 must be refused.
        $this->returnItems($sale['id'], $itemId, 4)->assertStatus(422);

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('120.00', $updatedSale['total']); // only the first return applied
    }

    // --- 11. Return against voided sale blocked ---

    public function test_return_against_voided_sale_is_blocked(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $admin = User::factory()->create();
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $admin->assignRole('Super Admin');
        $this->actingAs($admin)->postJson("/api/sales/{$sale['id']}/void", ['reason' => 'Testing return block'])->assertOk();

        $this->returnItems($sale['id'], $itemId, 2)->assertStatus(422);
    }

    // --- 12. Refund cannot exceed account balance ---

    public function test_refund_cannot_exceed_account_balance(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 400); // fully paid, cash now holds 400

        // Spend most of it elsewhere.
        $this->postJson('/api/transactions', [
            'type' => 'expense',
            'account_id' => $this->cash->id,
            'amount' => 350,
            'description' => 'Spent the sale proceeds on something else',
        ])->assertCreated();

        // Returning all 10 bags would need a $400 refund, but the account
        // only holds $50.
        $response = $this->returnItems($sale['id'], $itemId, 10, 'refund', $this->cash->id);
        $response->assertStatus(422);

        $unchangedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        $this->assertSame('400.00', $unchangedSale['total'], 'A refused return must not partially apply.');
    }

    // --- 13. Saleable return adds inventory at original unit cost ---

    public function test_saleable_return_adds_inventory_at_original_unit_cost(): void
    {
        $customer = $this->customer();
        $stockBefore = $this->inventory->currentStock($this->rice);
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $stockAfterSale = $this->inventory->currentStock($this->rice);
        $this->assertSame(
            number_format((float) $stockBefore - 10, 4, '.', ''),
            $stockAfterSale,
        );

        $this->returnItems($sale['id'], $itemId, 2, 'credit', null, true)->assertCreated();

        $stockAfterReturn = $this->inventory->currentStock($this->rice);
        $this->assertSame(
            number_format((float) $stockAfterSale + 2, 4, '.', ''),
            $stockAfterReturn,
        );

        $movement = DB::table('inventory_movements')
            ->where('reference_type', 'sale_return')
            ->where('product_id', $this->rice->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame('25.0000', number_format((float) $movement->unit_cost, 4, '.', ''), 'Must use the frozen sale-item cost, not today\'s weighted average.');
    }

    // --- 14. Unsaleable return does not add saleable inventory ---

    public function test_unsaleable_return_does_not_add_inventory(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);
        $stockAfterSale = $this->inventory->currentStock($this->rice);

        $this->returnItems($sale['id'], $itemId, 2, 'credit', null, false)->assertCreated();

        $stockAfterReturn = $this->inventory->currentStock($this->rice);
        $this->assertSame($stockAfterSale, $stockAfterReturn, 'An unsaleable/damaged return must not restock inventory.');

        $this->assertSame(
            0,
            DB::table('inventory_movements')->where('reference_type', 'sale_return')->count(),
        );
    }

    // --- 15 & 16. Correct COGS and gross profit ---

    public function test_correct_cogs_and_gross_profit_for_mixed_saleable_and_unsaleable_return(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);
        // Original: total 400, cogs 250 (10 * 25), gross_profit 150.

        $this->returnItems($sale['id'], $itemId, 2, 'credit', null, false)->assertCreated(); // unsaleable

        $updatedSale = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();
        // Revenue drops by the full $80, but COGS is untouched (nothing
        // saleable came back) - gross profit absorbs the full loss.
        $this->assertSame('320.00', $updatedSale['total']);
        $this->assertSame('250.00', $updatedSale['cost_of_goods_sold'], 'COGS must remain recognized for an unsaleable return.');
        $this->assertSame('70.00', $updatedSale['gross_profit']); // 320 - 250
    }

    // --- 36-38. Reason required / audited / ordinary User can process ---

    public function test_return_reason_is_required(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $this->postJson('/api/sale-returns', [
            'sale_id' => $sale['id'],
            'settlement_method' => 'credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_return_is_audited(): void
    {
        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $before = AuditLog::query()->where('action', 'sale_return_created')->count();

        $this->returnItems($sale['id'], $itemId, 2, 'credit', null, true, 'Wrong size delivered')->assertCreated();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'sale_return_created')->count());

        $entry = AuditLog::query()->where('action', 'sale_return_created')->latest('id')->first();
        $this->assertSame('Wrong size delivered', $entry->new_values['reason']);
        $this->assertSame($sale['id'], $entry->new_values['sale_id']);
        $this->assertSame('2', $entry->new_values['quantity']);
        $this->assertSame('80', $entry->new_values['value']);
        $this->assertSame('credit', $entry->new_values['settlement_method']);

        // The underlying sale_return Transaction is independently audited
        // too, via the existing generic mechanism.
        $transactionCreatedForReturn = AuditLog::query()
            ->where('action', 'transaction_created')
            ->get()
            ->filter(fn (AuditLog $log) => ($log->new_values['type'] ?? null) === 'sale_return');
        $this->assertGreaterThan(0, $transactionCreatedForReturn->count());
    }

    public function test_ordinary_user_can_process_a_return(): void
    {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Role::findOrCreate('User', 'web');
        $user->assignRole('User');
        $this->actingAs($user);

        $customer = $this->customer();
        ['sale' => $sale, 'saleItemId' => $itemId] = $this->creditSale($customer, 10, 40, 0);

        $this->returnItems($sale['id'], $itemId, 2)->assertCreated();
    }
}

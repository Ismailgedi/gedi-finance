<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Supplier returns against a specific, already-posted purchase - the mirror
 * of SaleReturnWorkflowTest. See PurchaseReturnService's own doc comment.
 */
class PurchaseReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private BalanceService $balances;
    private InventoryService $inventory;
    private Product $rice;
    private Supplier $supplier;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
        $this->balances = app(BalanceService::class);
        $this->inventory = app(InventoryService::class);

        $bag = Unit::create(['name' => 'PReturn Bag', 'abbreviation' => 'pbag' . uniqid()]);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Purchase Return Rice',
            'sku' => 'PRET-RICE-' . uniqid(),
            'default_cost_price' => 18,
            'default_selling_price' => 27,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'supplier_code' => 'PRET-SUP-' . uniqid(),
            'name' => 'Purchase Return Supplier',
            'is_active' => true,
        ]);

        $this->cash = Account::create([
            'name' => 'PReturn Cash ' . uniqid(),
            'type' => 'cash',
            'opening_balance' => 1000,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /** @return array{purchase: array, purchaseItemId: int} */
    private function purchase(float $quantity = 20, float $unitCost = 18, float $amountPaid = 0, array $additionalCosts = []): array
    {
        $payload = [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => $amountPaid,
            'items' => [['product_id' => $this->rice->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        ];

        if (!empty($additionalCosts)) {
            $payload['additional_costs'] = $additionalCosts;
        }

        if ($amountPaid > 0) {
            $payload['account_id'] = $this->cash->id;
        }

        $purchase = $this->postJson('/api/purchases', $payload)->assertCreated()->json('purchase');

        return ['purchase' => $purchase, 'purchaseItemId' => $purchase['items'][0]['id']];
    }

    private function returnItems(int $purchaseId, int $purchaseItemId, float $quantity, string $settlement = 'credit', ?int $refundAccountId = null, string $reason = 'Damaged on arrival'): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'purchase_id' => $purchaseId,
            'reason' => $reason,
            'settlement_method' => $settlement,
            'items' => [['purchase_item_id' => $purchaseItemId, 'quantity' => $quantity]],
        ];

        if ($refundAccountId) {
            $payload['refund_account_id'] = $refundAccountId;
        }

        return $this->postJson('/api/purchase-returns', $payload);
    }

    // --- 17. Unpaid supplier return ---

    public function test_unpaid_supplier_return(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 0); // total 360, unpaid

        $this->returnItems($purchase['id'], $itemId, 3)->assertCreated(); // value 54

        $updated = $this->getJson("/api/purchases/{$purchase['id']}")->assertOk()->json();
        $this->assertSame('306.00', $updated['total']); // 360 - 54
        $this->assertSame('306.00', $updated['balance_due']);
        $this->assertSame('306.00', $this->balances->supplierBalance($this->supplier->fresh()));
    }

    // --- 18. Partial paid supplier return ---

    public function test_partial_paid_supplier_return_within_outstanding(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 260); // total 360, paid 260, outstanding 100

        $this->returnItems($purchase['id'], $itemId, 3)->assertCreated(); // value 54 <= 100

        $updated = $this->getJson("/api/purchases/{$purchase['id']}")->assertOk()->json();
        $this->assertSame('306.00', $updated['total']);
        $this->assertSame('260.00', $updated['amount_paid']); // unchanged
        $this->assertSame('46.00', $updated['balance_due']);
    }

    // --- 19. Return exceeding payable ---

    public function test_supplier_return_exceeding_payable_creates_credit(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 260); // outstanding 100

        // Return 10 bags = $180, exceeding the $100 still owed.
        $response = $this->returnItems($purchase['id'], $itemId, 10, 'credit')->assertCreated();

        $this->assertSame('180.00', $response->json('return.total_value'));
        $this->assertSame('100.00', $response->json('return.applied_to_payable'));
        $this->assertSame('80.00', $response->json('return.credited_amount'));
        $this->assertSame('0.00', $response->json('return.refund_amount'));

        $this->assertSame(
            '-80.00',
            $this->balances->supplierBalance($this->supplier->fresh()),
            'A negative supplier balance is a supplier credit - the business no longer owes them, they owe the business.',
        );
    }

    // --- 20. Supplier cash refund ---

    public function test_supplier_cash_refund_only_refunds_the_excess(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 260); // outstanding 100
        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $response = $this->returnItems($purchase['id'], $itemId, 10, 'refund', $this->cash->id)->assertCreated();

        $this->assertSame('100.00', $response->json('return.applied_to_payable'));
        $this->assertSame('80.00', $response->json('return.refund_amount'));
        $this->assertSame('0.00', $response->json('return.credited_amount'));

        $this->assertSame('0.00', $this->balances->supplierBalance($this->supplier->fresh()));
        $this->assertSame(
            number_format($cashBefore + 80, 2, '.', ''),
            $this->balances->accountBalance($this->cash->fresh()),
            'The refund must increase the account - cash coming IN from the supplier.',
        );
    }

    // --- 21. Supplier credit ---

    public function test_supplier_credit_leaves_negative_balance_with_no_cash_movement(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 360); // fully paid
        $cashBefore = (float) $this->balances->accountBalance($this->cash->fresh());

        $this->returnItems($purchase['id'], $itemId, 5, 'credit')->assertCreated(); // value 90, outstanding 0

        $this->assertSame('-90.00', $this->balances->supplierBalance($this->supplier->fresh()));
        $this->assertSame(number_format($cashBefore, 2, '.', ''), $this->balances->accountBalance($this->cash->fresh()));
    }

    // --- 22. Multiple partial returns ---

    public function test_multiple_partial_supplier_returns_accumulate(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 0);

        $this->returnItems($purchase['id'], $itemId, 3)->assertCreated();
        $this->returnItems($purchase['id'], $itemId, 4)->assertCreated();

        $updated = $this->getJson("/api/purchases/{$purchase['id']}")->assertOk()->json();
        $this->assertSame('234.00', $updated['total']); // 360 - 54 - 72
        $this->assertCount(2, $updated['returns']);
    }

    // --- 23. Over-return blocked ---

    public function test_over_return_is_blocked(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 0);

        $this->returnItems($purchase['id'], $itemId, 15)->assertCreated();
        // Only 5 remain returnable.
        $this->returnItems($purchase['id'], $itemId, 6)->assertStatus(422);
    }

    // --- 24. Return against voided purchase blocked ---

    public function test_return_against_voided_purchase_is_blocked(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 0);

        $admin = User::factory()->create();
        Role::findOrCreate('Super Admin', 'web');
        $admin->assignRole('Super Admin');
        $this->actingAs($admin)->postJson("/api/purchases/{$purchase['id']}/void", ['reason' => 'Testing return block'])->assertOk();

        $this->returnItems($purchase['id'], $itemId, 3)->assertStatus(422);
    }

    // --- 25. Inventory-negative guard ---

    public function test_supplier_return_is_blocked_when_stock_already_sold(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 0);

        // Sell most of that stock elsewhere.
        $this->postJson('/api/sales', [
            'amount_paid' => 17 * 27,
            'account_id' => $this->cash->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 17, 'unit_price' => 27]],
        ])->assertCreated();

        $this->assertSame('3.0000', $this->inventory->currentStock($this->rice));

        // Returning 5 would need to remove 5, but only 3 remain.
        $response = $this->returnItems($purchase['id'], $itemId, 5);
        $response->assertStatus(422);

        $unchanged = $this->getJson("/api/purchases/{$purchase['id']}")->assertOk()->json();
        $this->assertSame('360.00', $unchanged['total'], 'A refused return must not partially apply.');
    }

    // --- 26. Original landed cost used ---

    public function test_return_uses_original_landed_unit_cost_not_weighted_average(): void
    {
        // 20 bags @ $18 + $40 transport = $760 total landed cost across 20
        // bags -> landed_unit_cost = 18 + 40/20 = 20.
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 0, [
            ['description' => 'Transport', 'amount' => 40, 'type' => 'third_party', 'account_id' => $this->cash->id],
        ]);

        $this->assertSame('20.0000', number_format((float) $this->getJson("/api/purchases/{$purchase['id']}")->json('items.0.landed_unit_cost'), 4, '.', ''));

        // A second, separately-priced purchase changes the weighted average
        // for the product, so a return using "today's" average would give
        // a different (wrong) figure than the original landed cost.
        $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 20, 'unit_cost' => 30]],
        ])->assertCreated();
        $this->assertNotEquals('20.0000', $this->inventory->weightedAverageCost($this->rice));

        $this->returnItems($purchase['id'], $itemId, 5)->assertCreated();

        $movement = DB::table('inventory_movements')
            ->where('reference_type', 'purchase_return')
            ->where('product_id', $this->rice->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame('20.0000', number_format((float) $movement->unit_cost, 4, '.', ''));
    }

    // --- reason required / audited ---

    public function test_purchase_return_reason_is_required(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 0);

        $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase['id'],
            'settlement_method' => 'credit',
            'items' => [['purchase_item_id' => $itemId, 'quantity' => 3]],
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_purchase_return_is_audited(): void
    {
        ['purchase' => $purchase, 'purchaseItemId' => $itemId] = $this->purchase(20, 18, 0);

        $before = AuditLog::query()->where('action', 'purchase_return_created')->count();

        $this->returnItems($purchase['id'], $itemId, 3, 'credit', null, 'Wrong grade delivered')->assertCreated();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'purchase_return_created')->count());

        $entry = AuditLog::query()->where('action', 'purchase_return_created')->latest('id')->first();
        $this->assertSame('Wrong grade delivered', $entry->new_values['reason']);
        $this->assertSame($purchase['id'], $entry->new_values['purchase_id']);
    }
}

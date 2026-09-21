<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies receivables/payables aging buckets: only the OUTSTANDING
 * balance of a sale/purchase contributes (a fully paid one contributes
 * nothing), bucketed by sale_date/purchase_date age in days, using the
 * simplest methodology the current data model supports (no due-date
 * tracking exists to age against instead).
 */
class AgingReportTest extends TestCase
{
    use RefreshDatabase;

    private Product $rice;
    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());

        $bag = Unit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $this->rice = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Aging Test Rice',
            'sku' => 'AGING-RICE',
            'default_cost_price' => 10,
            'default_selling_price' => 20,
            'is_active' => true,
        ]);
        app(InventoryService::class)->record($this->rice, 1000, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 10);

        $this->cash = Account::create([
            'name' => 'Aging Test Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_receivables_age_correctly_by_sale_date_and_only_the_outstanding_balance_counts(): void
    {
        $customer = Person::create(['name' => 'Aging Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $makeSale = function (int $daysAgo, float $total, float $paid) use ($customer) {
            return $this->postJson('/api/sales', [
                'customer_id' => $customer->id,
                'sale_date' => now()->subDays($daysAgo)->toDateString(),
                'amount_paid' => $paid,
                'account_id' => $paid > 0 ? $this->cash->id : null,
                'items' => [['product_id' => $this->rice->id, 'quantity' => $total / 20, 'unit_price' => 20]],
            ])->assertCreated()->json('sale');
        };

        // 10 days ago, fully unpaid -> 0-30 bucket, $200 outstanding.
        $makeSale(10, 200, 0);
        // 45 days ago, fully unpaid -> 31-60 bucket, $100 outstanding.
        $makeSale(45, 100, 0);
        // 75 days ago, fully unpaid -> 61-90 bucket, $60 outstanding.
        $makeSale(75, 60, 0);
        // 120 days ago, fully unpaid -> 90+ bucket, $500 outstanding.
        $makeSale(120, 500, 0);
        // A sale from 10 days ago that's FULLY PAID must contribute $0,
        // not its total, to any bucket.
        $makeSale(10, 300, 300);
        // A sale from 45 days ago with a PARTIAL payment: only the
        // remaining $80 (of $180) should land in 31-60.
        $makeSale(45, 180, 100);

        $response = $this->getJson('/api/reports/customer-receivables')->assertOk()->json();
        $buckets = collect($response['aging']['buckets'])->keyBy('label');

        $this->assertSame('200.00', $buckets['0-30']['outstanding']);
        $this->assertSame('180.00', $buckets['31-60']['outstanding'], '100 (fully unpaid) + 80 (partial remainder) = 180.');
        $this->assertSame('60.00', $buckets['61-90']['outstanding']);
        $this->assertSame('500.00', $buckets['90+']['outstanding']);
        $this->assertSame('940.00', $response['aging']['total'], '200+180+60+500 = 940.');
    }

    public function test_payables_age_correctly_by_purchase_date_and_only_the_outstanding_balance_counts(): void
    {
        $supplier = Supplier::create(['supplier_code' => 'AGING-SUP', 'name' => 'Aging Supplier', 'is_active' => true]);

        $makePurchase = function (int $daysAgo, float $qty, float $paid) use ($supplier) {
            return $this->postJson('/api/purchases', [
                'supplier_id' => $supplier->id,
                'purchase_date' => now()->subDays($daysAgo)->toDateString(),
                'amount_paid' => $paid,
                'account_id' => $paid > 0 ? $this->cash->id : null,
                'items' => [['product_id' => $this->rice->id, 'quantity' => $qty, 'unit_cost' => 10]],
            ])->assertCreated()->json('purchase');
        };

        // 5 days ago, unpaid -> 0-30, $150 outstanding (15 bags * 10).
        $makePurchase(5, 15, 0);
        // 50 days ago, unpaid -> 31-60, $80 outstanding.
        $makePurchase(50, 8, 0);
        // 100 days ago, unpaid -> 90+, $200 outstanding.
        $makePurchase(100, 20, 0);
        // 5 days ago, fully paid -> contributes nothing.
        $makePurchase(5, 10, 100);

        $response = $this->getJson('/api/reports/supplier-payables')->assertOk()->json();
        $buckets = collect($response['aging']['buckets'])->keyBy('label');

        $this->assertSame('150.00', $buckets['0-30']['outstanding']);
        $this->assertSame('80.00', $buckets['31-60']['outstanding']);
        $this->assertSame('0.00', $buckets['61-90']['outstanding']);
        $this->assertSame('200.00', $buckets['90+']['outstanding']);
        $this->assertSame('430.00', $response['aging']['total']);
    }

    public function test_a_voided_sale_does_not_contribute_to_aging(): void
    {
        $customer = Person::create(['name' => 'Aging Void Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->subDays(10)->toDateString(),
            'amount_paid' => 0,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 10, 'unit_price' => 20]],
        ])->assertCreated()->json('sale');

        $this->postJson("/api/sales/{$sale['id']}/void", [])->assertOk();

        $response = $this->getJson('/api/reports/customer-receivables')->assertOk()->json();
        $this->assertSame('0.00', $response['aging']['total'], 'A voided sale must not appear in any aging bucket.');
    }
}

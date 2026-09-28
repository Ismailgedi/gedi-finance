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
 * Flexible wholesale selling prices: a sale's unit_price is, and always
 * was, an independent per-line value (sale_items.unit_price) that
 * SaleService::create() takes straight from the request - it never reads
 * or writes products.default_selling_price. This locks that behaviour in
 * as an explicit, named guarantee (it previously only held incidentally),
 * covering the exact scenario from the spec: Maize costs $32/bag, defaults
 * to $37/bag, but actually sells anywhere from $35 to $38 depending on the
 * market, and gross profit must always be actual price minus actual COGS.
 */
class FlexibleSellingPriceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Product $maize;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());

        $this->cash = Account::create([
            'name' => 'Flexible Price Cash',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $bag = Unit::create(['name' => 'Flex Price Bag', 'abbreviation' => 'fpbag']);
        $this->maize = Product::create([
            'base_unit_id' => $bag->id,
            'name' => 'Maize',
            'sku' => 'FLEX-MAIZE',
            'default_cost_price' => 32,
            'default_selling_price' => 37,
            'is_active' => true,
        ]);

        // Opening stock at the product's default cost, so weighted-average
        // cost is exactly $32/bag - matching the spec's example.
        app(InventoryService::class)->record(
            $this->maize, 1000, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 32
        );
    }

    private function sell(float $quantity, float $unitPrice, array $overrides = []): array
    {
        return $this->postJson('/api/sales', array_merge([
            'amount_paid' => $overrides['amount_paid'] ?? round($quantity * $unitPrice, 2),
            'account_id' => $this->cash->id,
            'items' => [array_merge([
                'product_id' => $this->maize->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ], $overrides['item'] ?? [])],
        ], $overrides['top'] ?? []))->assertCreated()->json('sale');
    }

    // 1. Sale uses product default price when unchanged.
    public function test_sale_uses_product_default_price_when_unchanged(): void
    {
        $sale = $this->sell(1, 37);

        $this->assertSame('37.00', $sale['total']);
        $this->assertSame((float) 37, (float) $sale['items'][0]['unit_price']);
        $this->assertSame('32.00', $sale['cost_of_goods_sold']);
        $this->assertSame('5.00', $sale['gross_profit']);
    }

    // 2. User can sell below the default price.
    public function test_user_can_sell_below_default_price(): void
    {
        $sale = $this->sell(1, 35);

        $this->assertSame((float) 35, (float) $sale['items'][0]['unit_price']);
        $this->assertSame('35.00', $sale['total']);
    }

    // 3. User can sell above the default price.
    public function test_user_can_sell_above_default_price(): void
    {
        $sale = $this->sell(1, 40);

        $this->assertSame((float) 40, (float) $sale['items'][0]['unit_price']);
        $this->assertSame('40.00', $sale['total']);
    }

    // 4. Different sales of the same product can use different prices.
    public function test_different_sales_of_the_same_product_can_use_different_prices(): void
    {
        $saleA = $this->sell(1, 35);
        $saleB = $this->sell(1, 38);

        $this->assertSame((float) 35, (float) $saleA['items'][0]['unit_price']);
        $this->assertSame((float) 38, (float) $saleB['items'][0]['unit_price']);
        $this->assertNotSame($saleA['id'], $saleB['id']);
    }

    // 5. Product default price does not change when a sale price is changed.
    public function test_product_default_price_does_not_change_when_sale_price_is_changed(): void
    {
        $this->sell(1, 40);
        $this->sell(1, 30);

        $this->assertSame('37.0000', $this->maize->fresh()->default_selling_price);
    }

    // 6. Cash sale calculates correctly.
    public function test_cash_sale_calculates_correctly(): void
    {
        $balances = app(BalanceService::class);
        $cashBefore = (float) $balances->accountBalance($this->cash->fresh());

        $sale = $this->sell(2, 38);

        $this->assertSame('76.00', $sale['total']);
        $this->assertSame('76.00', $sale['amount_paid']);
        $this->assertSame('0.00', $sale['balance_due']);
        $this->assertSame(
            number_format($cashBefore + 76, 2, '.', ''),
            $balances->accountBalance($this->cash->fresh()),
        );
    }

    // 7. Credit sale calculates correctly.
    public function test_credit_sale_calculates_correctly(): void
    {
        $customer = Person::create(['name' => 'Flex Price Customer', 'is_active' => true, 'is_customer' => true, 'roles' => ['customer']]);
        $balances = app(BalanceService::class);

        $sale = $this->sell(3, 36, ['amount_paid' => 0, 'top' => ['customer_id' => $customer->id]]);

        $this->assertSame('108.00', $sale['total']);
        $this->assertSame('0.00', $sale['amount_paid']);
        $this->assertSame('108.00', $sale['balance_due']);
        $this->assertSame('108.00', $balances->customerReceivableBalance($customer->fresh()));
    }

    // 8. COGS remains based on inventory cost, never the selling price.
    public function test_cogs_remains_based_on_inventory_cost(): void
    {
        $sale = $this->sell(5, 45);

        // 5 bags * $32 weighted-average cost, unrelated to the $45 charged.
        $this->assertSame('160.00', $sale['cost_of_goods_sold']);
    }

    // 9. Gross profit uses the actual sale price, not the product default -
    // including a below-cost price that turns a normally-profitable
    // product into a loss on this specific sale.
    public function test_gross_profit_uses_actual_sale_price(): void
    {
        $sale = $this->sell(2, 30);

        $this->assertSame('60.00', $sale['total']);
        $this->assertSame('64.00', $sale['cost_of_goods_sold']);
        $this->assertSame('-4.00', $sale['gross_profit']);
    }

    // 10. Discounts still calculate correctly with a custom sale price.
    public function test_discounts_still_calculate_correctly(): void
    {
        $sale = $this->postJson('/api/sales', [
            'amount_paid' => 145,
            'account_id' => $this->cash->id,
            'discount' => 10,
            'items' => [[
                'product_id' => $this->maize->id,
                'quantity' => 4,
                'unit_price' => 40,
                'discount' => 5,
            ]],
        ])->assertCreated()->json('sale');

        // line_total = 4*40 - 5 = 155; total = 155 - 10 (invoice discount) = 145.
        $this->assertSame('155.00', $sale['subtotal']);
        $this->assertSame('145.00', $sale['total']);
        $this->assertSame('128.00', $sale['cost_of_goods_sold']);
        $this->assertSame('17.00', $sale['gross_profit']);
    }

    // 11. Invoice (GET /api/sales/{sale}) shows the actual sale price.
    public function test_invoice_shows_actual_sale_price(): void
    {
        $sale = $this->sell(1, 39);

        $fetched = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json();

        $this->assertSame((float) 39, (float) $fetched['items'][0]['unit_price']);
        $this->assertNotSame((float) 39, (float) $this->maize->default_selling_price);
    }

    // 12. Reports show figures derived from the actual sale price.
    public function test_reports_show_actual_sale_price(): void
    {
        $this->sell(2, 45);

        $profit = $this->getJson('/api/reports/profit?range=today')->assertOk()->json();

        $this->assertSame('90.00', $profit['revenue']['sales_revenue']);
        $this->assertSame('64.00', $profit['cost_of_goods_sold']['cogs']);
        $this->assertSame('26.00', $profit['gross_profit']);
    }
}

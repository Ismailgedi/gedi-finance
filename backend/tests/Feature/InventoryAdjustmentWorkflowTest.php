<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Physical inventory count / stock adjustment: reconciles a physical count
 * against the system quantity without ever editing inventory directly - a
 * non-zero difference becomes an ordinary InventoryMovement (movement_type
 * 'adjustment') via the existing, unmodified InventoryService::record(),
 * so weighted-average costing and inventory valuation pick it up exactly
 * like a purchase or sale would. See InventoryAdjustmentService.
 */
class InventoryAdjustmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Product $bag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());

        $unit = Unit::create(['name' => 'Adjustment Bag', 'abbreviation' => 'adjbag']);
        $this->bag = Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'Adjustment Test Product',
            'sku' => 'ADJ-TEST',
            'default_cost_price' => 25,
            'default_selling_price' => 35,
            'is_active' => true,
        ]);

        // Opening stock: 100 units at $25 - weighted-average cost is
        // exactly $25 before any adjustment.
        app(InventoryService::class)->record(
            $this->bag, 100, null, 'purchase', 'purchase', null, null, 'Opening stock', null, 'in', 25
        );
    }

    private function adjust(float $physicalQuantity, array $overrides = []): array
    {
        return $this->postJson('/api/inventory-adjustments', array_merge([
            'product_id' => $this->bag->id,
            'physical_quantity' => $physicalQuantity,
            'reason' => 'Physical stock count',
        ], $overrides))->assertCreated()->json('adjustment');
    }

    // 1. Negative adjustment (physical count below system quantity).
    public function test_negative_adjustment(): void
    {
        $adjustment = $this->adjust(96);

        $this->assertSame('100.0000', $adjustment['system_quantity']);
        $this->assertSame('96.0000', $adjustment['physical_quantity']);
        $this->assertSame((float) -4, (float) $adjustment['quantity_difference']);
        $this->assertSame('-100.00', $adjustment['total_adjustment_value']);
        $this->assertSame('96.0000', app(InventoryService::class)->currentStock($this->bag->fresh()));
    }

    // 2. Positive adjustment (physical count above system quantity).
    public function test_positive_adjustment(): void
    {
        $adjustment = $this->adjust(103);

        $this->assertSame((float) 3, (float) $adjustment['quantity_difference']);
        $this->assertSame('75.00', $adjustment['total_adjustment_value']);
        $this->assertSame('103.0000', app(InventoryService::class)->currentStock($this->bag->fresh()));
    }

    // C5 (accounting audit): a negative adjustment must always use the
    // current weighted-average cost, even if a unit_cost override is
    // explicitly supplied - never the client-supplied value.
    public function test_negative_adjustment_with_malicious_or_incorrect_explicit_cost_is_ignored(): void
    {
        $valueBefore = (float) app(InventoryService::class)->inventoryValue($this->bag->fresh());

        // 100 @ WAC $25 -> physical count 90 (shrinkage of 10). Attempting
        // to override the cost at $100/unit must be silently ignored - the
        // removal must still be valued at the true WAC ($25), never $100.
        $adjustment = $this->adjust(90, ['unit_cost' => 100]);

        $this->assertSame((float) -10, (float) $adjustment['quantity_difference']);
        $this->assertSame('25.0000', $adjustment['unit_cost'], 'Must use WAC, never the supplied override.');
        $this->assertSame('-250.00', $adjustment['total_adjustment_value'], '10 units removed at the true $25 WAC, not the malicious $100.');

        $this->assertSame(
            number_format($valueBefore - 250, 2, '.', ''),
            app(InventoryService::class)->inventoryValue($this->bag->fresh()),
        );

        // The weighted-average cost of the 90 units left must still be
        // exactly $25 - not corrupted toward the bogus $100 override.
        $this->assertSame('25.0000', app(InventoryService::class)->weightedAverageCost($this->bag->fresh()));
    }

    // C5: a positive adjustment (found stock, no existing cost basis for
    // THAT stock) may still legitimately use an explicit unit_cost - the
    // guard is one-directional, never blocking a real increase override.
    public function test_positive_adjustment_with_explicit_cost_is_respected(): void
    {
        $adjustment = $this->adjust(110, ['unit_cost' => 40]);

        $this->assertSame((float) 10, (float) $adjustment['quantity_difference']);
        $this->assertSame('40.0000', $adjustment['unit_cost']);
        $this->assertSame('400.00', $adjustment['total_adjustment_value'], '10 units added at the explicit $40 override.');
    }

    // 3. Zero adjustment (count confirms no discrepancy).
    public function test_zero_adjustment(): void
    {
        $adjustment = $this->adjust(100, ['reason' => 'Routine count, no discrepancy']);

        $this->assertSame((float) 0, (float) $adjustment['quantity_difference']);
        $this->assertSame('0.00', $adjustment['total_adjustment_value']);
        $this->assertNull($adjustment['inventory_movement_id']);
        $this->assertSame('100.0000', app(InventoryService::class)->currentStock($this->bag->fresh()));
        $this->assertSame(1, DB::table('inventory_adjustments')->count());
    }

    // 4. Correct quantity before/after.
    public function test_correct_quantity_before_and_after(): void
    {
        $adjustment = $this->adjust(90);

        $this->assertSame('100.0000', $adjustment['system_quantity']);
        $this->assertSame('90.0000', $adjustment['physical_quantity']);
        $this->assertSame('90.0000', app(InventoryService::class)->currentStock($this->bag->fresh()));
    }

    // 5. Correct inventory value after the adjustment.
    public function test_correct_inventory_value(): void
    {
        $inventory = app(InventoryService::class);
        $valueBefore = (float) $inventory->inventoryValue($this->bag->fresh());

        $this->adjust(96);

        $valueAfter = (float) $inventory->inventoryValue($this->bag->fresh());
        $this->assertSame(number_format($valueBefore - 100, 2, '.', ''), number_format($valueAfter, 2, '.', ''));
    }

    // 6. Weighted-average costing interaction: a decrease valued at the
    // current weighted-average leaves the average itself unchanged.
    public function test_weighted_average_costing_interaction(): void
    {
        // Blend in a second purchase at a different cost: (100*25 + 50*20) / 150 = 23.3333.
        app(InventoryService::class)->record(
            $this->bag, 50, null, 'purchase', 'purchase', null, null, 'Second batch', null, 'in', 20
        );

        $inventory = app(InventoryService::class);
        $wacBefore = $inventory->weightedAverageCost($this->bag->fresh());

        // Remove 15 units - InventoryAdjustmentService defaults to the
        // current weighted-average cost when none is given.
        $this->adjust(135);

        $wacAfter = $inventory->weightedAverageCost($this->bag->fresh());

        $this->assertSame($wacBefore, $wacAfter);
    }

    // 7. Audit trail.
    public function test_audit_trail(): void
    {
        $this->adjust(96);

        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory_adjustment_created']);
    }

    // 8. Required reason.
    public function test_reason_is_required(): void
    {
        $response = $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->bag->id,
            'physical_quantity' => 96,
            'reason' => '',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('inventory_adjustments')->count());
    }

    // 9. Negative-stock validation - a negative physical count is rejected,
    // and a decrease all the way to zero is still valid (never negative).
    public function test_negative_stock_validation(): void
    {
        $response = $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->bag->id,
            'physical_quantity' => -5,
            'reason' => 'Invalid count',
        ]);
        $response->assertStatus(422);

        $adjustment = $this->adjust(0, ['reason' => 'Total shrinkage']);
        $this->assertSame('0.0000', app(InventoryService::class)->currentStock($this->bag->fresh()));
        $this->assertSame((float) -100, (float) $adjustment['quantity_difference']);
    }

    // A positive adjustment on a product with zero existing stock (no
    // weighted-average to default to) requires an explicit unit cost.
    public function test_unit_cost_required_when_no_weighted_average_exists(): void
    {
        $unit = Unit::create(['name' => 'Fresh Product Unit', 'abbreviation' => 'fresh']);
        $freshProduct = Product::create([
            'base_unit_id' => $unit->id,
            'name' => 'Brand New Product',
            'sku' => 'ADJ-FRESH',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/inventory-adjustments', [
            'product_id' => $freshProduct->id,
            'physical_quantity' => 10,
            'reason' => 'Found stock never purchased in the system',
        ]);
        $response->assertStatus(422);

        $withCost = $this->postJson('/api/inventory-adjustments', [
            'product_id' => $freshProduct->id,
            'physical_quantity' => 10,
            'reason' => 'Found stock never purchased in the system',
            'unit_cost' => 18,
        ])->assertCreated()->json('adjustment');

        $this->assertSame('180.00', $withCost['total_adjustment_value']);
    }

    // 10. Existing purchase/sale inventory flows are unaffected.
    public function test_existing_purchase_and_sale_inventory_flows_still_work(): void
    {
        $inventory = app(InventoryService::class);

        $this->adjust(96);

        $inventory->record($this->bag, 20, null, 'purchase', 'purchase', null, null, 'Restock', null, 'in', 30);
        $this->assertSame('116.0000', $inventory->currentStock($this->bag->fresh()));

        $inventory->record($this->bag, 10, null, 'sale', 'sale', null, null, 'Sold', null, 'out');
        $this->assertSame('106.0000', $inventory->currentStock($this->bag->fresh()));
    }

    // Adjustment history, filterable by product - the year-end stock
    // verification view (System/Physical/Difference/Value).
    public function test_adjustment_history_is_listed_and_filterable_by_product(): void
    {
        $this->adjust(96);
        $this->adjust(98);

        $response = $this->getJson("/api/inventory-adjustments?product_id={$this->bag->id}")->assertOk()->json();

        $this->assertCount(2, $response['data']);
        $this->assertSame($this->bag->id, $response['data'][0]['product_id']);
    }

    // --- H2 (accounting audit): an inventory adjustment must respect the
    // same closed-financial-year and locked-Opening-Balance date rules
    // every other posting path already does. ---

    public function test_adjustment_is_blocked_inside_a_closed_financial_year(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        // 2019 is fully elapsed relative to "now" in this suite, so it can
        // be closed with no time travel.
        $this->actingAs($admin)->postJson('/api/admin/financial-years/2019/close')->assertOk();

        $this->actingAs(User::factory()->create());

        $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->bag->id,
            'physical_quantity' => 90,
            'reason' => 'Backdated count attempt',
            'adjustment_date' => '2019-06-15',
        ])->assertStatus(422);

        // Nothing changed - stock still reflects the 100 opening units.
        $this->assertSame('100.0000', app(InventoryService::class)->currentStock($this->bag->fresh()));
        $this->assertSame(0, DB::table('inventory_adjustments')->count());
    }

    public function test_adjustment_is_blocked_on_or_before_a_locked_opening_balance_date(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $person = Person::create(['name' => 'IAWT Opening Balance Person', 'is_active' => true, 'roles' => []]);
        $this->postJson('/api/admin/opening-balance', [
            'as_of_date' => '2026-01-01',
            'opening_equity' => 100,
            'items' => [['category' => 'other_receivable', 'person_id' => $person->id, 'amount' => 100]],
        ])->assertOk();
        $this->postJson('/api/admin/opening-balance/lock')->assertOk();

        $this->actingAs(User::factory()->create());

        // Dated exactly ON the locked as_of_date - must be refused.
        $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->bag->id,
            'physical_quantity' => 90,
            'reason' => 'Predates the opening balance',
            'adjustment_date' => '2026-01-01',
        ])->assertStatus(422);

        // Dated BEFORE the locked as_of_date - must also be refused.
        $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->bag->id,
            'physical_quantity' => 90,
            'reason' => 'Predates the opening balance',
            'adjustment_date' => '2025-12-15',
        ])->assertStatus(422);

        $this->assertSame('100.0000', app(InventoryService::class)->currentStock($this->bag->fresh()));
        $this->assertSame(0, DB::table('inventory_adjustments')->count());

        // Dated AFTER the locked as_of_date - must succeed.
        $this->postJson('/api/inventory-adjustments', [
            'product_id' => $this->bag->id,
            'physical_quantity' => 90,
            'reason' => 'After the opening balance',
            'adjustment_date' => '2026-01-02',
        ])->assertCreated();
    }
}

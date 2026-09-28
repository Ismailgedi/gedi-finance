<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 3 (accounting audit): the Sales/Purchases/Loans & Debts/Owner
 * Capital/Record Transaction/Inventory Adjustment forms used to read only
 * page 1 of GET /api/people (25/page), /api/products (50/page) and
 * /api/suppliers (25/page), silently making every customer/product/
 * supplier beyond that page unselectable. The fix is a frontend
 * pagination-fetch helper (fetchAllPages()) that walks every page - this
 * file verifies the backend contract that helper depends on: creating more
 * records than one page holds actually produces a real second page (not
 * silently truncated or capped), and that walking page 1 + page 2 yields
 * the complete active set with no duplicates or gaps.
 */
class ListEndpointPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_people_endpoint_paginates_at_25_and_a_second_page_is_reachable(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Person::create(['name' => "Pagination Person {$i}", 'is_active' => true, 'roles' => []]);
        }

        $page1 = $this->getJson('/api/people')->assertOk()->json();
        $this->assertCount(25, $page1['data']);
        $this->assertSame(1, $page1['current_page']);
        $this->assertSame(2, $page1['last_page'], 'Creating 30 people (> 25/page) must produce a real second page.');
        $this->assertSame(30, $page1['total']);

        $page2 = $this->getJson('/api/people?page=2')->assertOk()->json();
        $this->assertCount(5, $page2['data']);

        $allNames = collect($page1['data'])->pluck('name')
            ->merge(collect($page2['data'])->pluck('name'));

        $this->assertCount(30, $allNames->unique(), 'Every person must appear exactly once across both pages.');
        for ($i = 1; $i <= 30; $i++) {
            $this->assertTrue($allNames->contains("Pagination Person {$i}"), "Person {$i} must be reachable via pagination.");
        }
    }

    public function test_products_endpoint_paginates_at_50_and_a_second_page_is_reachable(): void
    {
        $unit = Unit::create(['name' => 'Pagination Unit', 'abbreviation' => 'pgu' . uniqid()]);

        for ($i = 1; $i <= 55; $i++) {
            Product::create([
                'base_unit_id' => $unit->id, 'name' => "Pagination Product {$i}", 'sku' => "PAGIN-{$i}-" . uniqid(),
                'default_cost_price' => 10, 'default_selling_price' => 20, 'is_active' => true,
            ]);
        }

        $page1 = $this->getJson('/api/products')->assertOk()->json();
        $this->assertCount(50, $page1['data']);
        $this->assertSame(2, $page1['last_page'], 'Creating 55 products (> 50/page) must produce a real second page.');
        $this->assertSame(55, $page1['total']);

        $page2 = $this->getJson('/api/products?page=2')->assertOk()->json();
        $this->assertCount(5, $page2['data']);

        $allNames = collect($page1['data'])->pluck('name')->merge(collect($page2['data'])->pluck('name'));
        $this->assertCount(55, $allNames->unique());
    }

    public function test_suppliers_endpoint_paginates_at_25_and_a_second_page_is_reachable(): void
    {
        for ($i = 1; $i <= 28; $i++) {
            Supplier::create(['supplier_code' => "PAGIN-SUP-{$i}", 'name' => "Pagination Supplier {$i}", 'is_active' => true]);
        }

        $page1 = $this->getJson('/api/suppliers')->assertOk()->json();
        $this->assertCount(25, $page1['data']);
        $this->assertSame(2, $page1['last_page'], 'Creating 28 suppliers (> 25/page) must produce a real second page.');
        $this->assertSame(28, $page1['total']);

        $page2 = $this->getJson('/api/suppliers?page=2')->assertOk()->json();
        $this->assertCount(3, $page2['data']);

        $allNames = collect($page1['data'])->pluck('name')->merge(collect($page2['data'])->pluck('name'));
        $this->assertCount(28, $allNames->unique());
    }
}

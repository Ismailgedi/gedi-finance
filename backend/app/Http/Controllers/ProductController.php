<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Product::query()
                ->where('is_active', true)
                ->with(['category', 'supplier', 'baseUnit', 'units.unit'])
                ->orderBy('name')
                ->paginate(50)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            // is_active,1: a product's preferred supplier is purely a
            // catalog default (see Product::supplier()'s doc comment) but
            // still must point at a real, currently-active supplier - the
            // same rule the accounting audit's Finding 4 applies to every
            // other supplier/account reference.
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id,is_active,1'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku'],
            'default_cost_price' => ['nullable', 'numeric', 'gte:0'],
            'default_selling_price' => ['nullable', 'numeric', 'gte:0'],
            'default_wholesale_price' => ['nullable', 'numeric', 'gte:0'],
            'minimum_stock' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::create([
            ...$validated,
            'sku' => $validated['sku'] ?? $this->nextSku(),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product->load(['category', 'supplier', 'baseUnit', 'units.unit']),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(
            $product->load(['category', 'supplier', 'baseUnit', 'units.unit'])
        );
    }

    /**
     * base_unit_id is deliberately NOT editable here: every existing
     * InventoryMovement.base_quantity for this product was computed using
     * the CURRENT base unit's conversion factor at the time it was
     * recorded. Changing base_unit_id after the fact wouldn't touch those
     * historical rows, so stock/cost calculations would silently
     * misinterpret them. Add a new sellable unit (already supported) or
     * create a new product instead of repurposing this one's base unit.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            // is_active,1: see the store() validation above.
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id,is_active,1'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku,' . $product->id],
            'default_cost_price' => ['nullable', 'numeric', 'gte:0'],
            'default_selling_price' => ['nullable', 'numeric', 'gte:0'],
            'default_wholesale_price' => ['nullable', 'numeric', 'gte:0'],
            'minimum_stock' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh()->load(['category', 'supplier', 'baseUnit', 'units.unit']),
        ]);
    }

    public function storeUnit(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id', 'unique:product_units,unit_id,NULL,id,product_id,' . $product->id],
            'conversion_factor' => ['required', 'numeric', 'gt:0'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $productUnit = ProductUnit::create([
            'product_id' => $product->id,
            ...$validated,
            'is_default' => $validated['is_default'] ?? false,
        ]);

        return response()->json([
            'message' => 'Product unit added successfully.',
            'product_unit' => $productUnit->load('unit'),
        ], 201);
    }

    private function nextSku(): string
    {
        $prefix = 'SKU-';
        $last = Product::query()
            ->where('sku', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('sku');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}

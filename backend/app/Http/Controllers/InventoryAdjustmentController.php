<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryAdjustmentRequest;
use App\Models\InventoryAdjustment;
use App\Services\InventoryAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    /**
     * Physical stock count history - also the year-end stock verification
     * view (System Quantity / Physical Quantity / Difference / Value),
     * optionally scoped to one product for the Products page's "Adjust
     * Stock" action/history.
     */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryAdjustment::query()
            ->with(['product.baseUnit', 'unit', 'creator']);

        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->query('product_id'));
        }

        return response()->json(
            $query->latest('adjustment_date')->latest('id')->paginate(25)
        );
    }

    public function store(StoreInventoryAdjustmentRequest $request, InventoryAdjustmentService $adjustments): JsonResponse
    {
        $adjustment = $adjustments->create($request->validated(), $request->user()?->id);

        return response()->json([
            'message' => 'Inventory adjustment recorded successfully.',
            'adjustment' => $adjustment,
        ], 201);
    }
}

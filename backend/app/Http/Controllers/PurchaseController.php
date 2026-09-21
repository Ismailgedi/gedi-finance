<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\VoidPurchaseRequest;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Services\SupplierPaymentService;
use Illuminate\Http\JsonResponse;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly SupplierPaymentService $payments,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(
            Purchase::query()
                ->with(['supplier', 'items.product.baseUnit', 'items.productUnit.unit', 'voidedBy'])
                ->latest('purchase_date')
                ->paginate(25)
        );
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $purchase = $this->purchases->create($request->validated(), $request->user()?->id);

        return response()->json([
            'message' => 'Purchase posted successfully.',
            'purchase' => $purchase,
        ], 201);
    }

    public function show(Purchase $purchase): JsonResponse
    {
        return response()->json(
            $purchase->load(['supplier', 'items.product.baseUnit', 'items.productUnit.unit', 'voidedBy'])
        );
    }

    public function payment(StorePaymentRequest $request, Purchase $purchase): JsonResponse
    {
        $purchase = $this->payments->pay($purchase, $request->validated(), $request->user()?->id);

        return response()->json([
            'message' => 'Supplier payment recorded successfully.',
            'purchase' => $purchase,
        ]);
    }

    public function void(VoidPurchaseRequest $request, Purchase $purchase): JsonResponse
    {
        $purchase = $this->purchases->void($purchase, $request->validated()['reason'] ?? null, $request->user()?->id);

        return response()->json([
            'message' => 'Purchase voided successfully.',
            'purchase' => $purchase,
        ]);
    }
}

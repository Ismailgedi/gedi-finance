<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\VoidSaleRequest;
use App\Models\Sale;
use App\Services\CustomerPaymentService;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly CustomerPaymentService $payments,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(
            Sale::query()
                ->with(['customer', 'items.product.baseUnit', 'items.productUnit.unit', 'voidedBy'])
                ->latest('sale_date')
                ->paginate(25)
        );
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $sale = $this->sales->create($request->validated(), $request->user()?->id);

        return response()->json([
            'message' => 'Sale posted successfully.',
            'sale' => $sale,
        ], 201);
    }

    public function show(Sale $sale): JsonResponse
    {
        return response()->json(
            $sale->load(['customer', 'items.product.baseUnit', 'items.productUnit.unit', 'voidedBy'])
        );
    }

    public function payment(StorePaymentRequest $request, Sale $sale): JsonResponse
    {
        $sale = $this->payments->receive($sale, $request->validated(), $request->user()?->id);

        return response()->json([
            'message' => 'Customer payment recorded successfully.',
            'sale' => $sale,
        ]);
    }

    public function void(VoidSaleRequest $request, Sale $sale): JsonResponse
    {
        $sale = $this->sales->void($sale, $request->validated()['reason'] ?? null, $request->user()?->id);

        return response()->json([
            'message' => 'Sale voided successfully.',
            'sale' => $sale,
        ]);
    }
}

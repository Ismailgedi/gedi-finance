<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseReturnRequest;
use App\Services\PurchaseReturnService;
use Illuminate\Http\JsonResponse;

class PurchaseReturnController extends Controller
{
    public function __construct(private readonly PurchaseReturnService $returns)
    {
    }

    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        $return = $this->returns->create(
            $request->validated(),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Purchase return recorded successfully.',
            'return' => $return,
        ], 201);
    }
}

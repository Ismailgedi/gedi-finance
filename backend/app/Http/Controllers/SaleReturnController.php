<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSaleReturnRequest;
use App\Services\SaleReturnService;
use Illuminate\Http\JsonResponse;

class SaleReturnController extends Controller
{
    public function __construct(private readonly SaleReturnService $returns)
    {
    }

    public function store(StoreSaleReturnRequest $request): JsonResponse
    {
        $return = $this->returns->create(
            $request->validated(),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Sale return recorded successfully.',
            'return' => $return,
        ], 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveOpeningBalanceRequest;
use App\Services\OpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-Admin-only (see the routes' 'role:Super Admin' middleware) - the
 * whole Opening Balance workflow is a foundational, rarely-touched setup
 * operation, matching the permission tier financial-year close/reopen
 * already uses.
 */
class OpeningBalanceController extends Controller
{
    public function __construct(private readonly OpeningBalanceService $service)
    {
    }

    public function show(): JsonResponse
    {
        $record = $this->service->current();

        if (!$record) {
            return response()->json(null);
        }

        return response()->json(
            $record->load([
                'items.person', 'items.supplier', 'items.product.baseUnit', 'items.productUnit.unit',
                'creator', 'lockedBy', 'reopenedBy',
            ])
        );
    }

    public function save(SaveOpeningBalanceRequest $request): JsonResponse
    {
        $record = $this->service->saveDraft($request->validated(), $request->user()?->id);

        return response()->json([
            'message' => 'Opening Balance draft saved.',
            'opening_balance' => $record,
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        $record = $this->service->lock($request->user()?->id);

        return response()->json([
            'message' => 'Opening Balance locked.',
            'opening_balance' => $record,
        ]);
    }

    public function reopen(Request $request): JsonResponse
    {
        $record = $this->service->reopen($request->user()?->id);

        return response()->json([
            'message' => 'Opening Balance reopened for correction.',
            'opening_balance' => $record,
        ]);
    }
}

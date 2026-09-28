<?php

namespace App\Http\Controllers;

use App\Services\FinancialYearCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super Admin only (see routes/api.php - gated by role:Super Admin the same
 * way user management already is). Closing/reopening are the only two
 * actions here; the Business Position report itself (any authenticated
 * user) already reflects a closed year's frozen snapshot automatically via
 * BusinessCapitalService::position().
 */
class FinancialYearCloseController extends Controller
{
    public function close(Request $request, int $year, FinancialYearCloseService $service): JsonResponse
    {
        $record = $service->close($year, $request->user()?->id);

        return response()->json([
            'message' => "Financial year {$year} closed successfully.",
            'financial_year_close' => $record,
        ]);
    }

    public function reopen(Request $request, int $year, FinancialYearCloseService $service): JsonResponse
    {
        $record = $service->reopen($year, $request->user()?->id);

        return response()->json([
            'message' => "Financial year {$year} reopened successfully.",
            'financial_year_close' => $record,
        ]);
    }
}

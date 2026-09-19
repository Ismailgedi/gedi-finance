<?php

namespace App\Http\Controllers;

use App\Services\BusinessReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BusinessReportController extends Controller
{
    public function summary(Request $request, BusinessReportService $reports): JsonResponse
    {
        try {
            return response()->json($reports->summary($request->query('from'), $request->query('to')));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sales(Request $request, BusinessReportService $reports): JsonResponse
    {
        try {
            return response()->json($reports->sales($request->query('from'), $request->query('to')));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function purchases(Request $request, BusinessReportService $reports): JsonResponse
    {
        try {
            return response()->json($reports->purchases($request->query('from'), $request->query('to')));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function inventory(BusinessReportService $reports): JsonResponse
    {
        return response()->json($reports->inventory());
    }

    public function customerReceivables(BusinessReportService $reports): JsonResponse
    {
        return response()->json($reports->customerReceivables());
    }

    public function supplierPayables(BusinessReportService $reports): JsonResponse
    {
        return response()->json($reports->supplierPayables());
    }
}

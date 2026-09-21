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
            return response()->json($reports->summary(
                $request->query('range'),
                $request->query('from'),
                $request->query('to'),
            ));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sales(Request $request, BusinessReportService $reports): JsonResponse
    {
        try {
            return response()->json($reports->sales($request->only([
                'range', 'from', 'to', 'customer_id', 'payment_status', 'search', 'page', 'per_page',
            ])));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function purchases(Request $request, BusinessReportService $reports): JsonResponse
    {
        try {
            return response()->json($reports->purchases($request->only([
                'range', 'from', 'to', 'supplier_id', 'payment_status', 'search', 'page', 'per_page',
            ])));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function profit(Request $request, BusinessReportService $reports): JsonResponse
    {
        try {
            return response()->json($reports->profit(
                $request->query('range'),
                $request->query('from'),
                $request->query('to'),
            ));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function inventory(BusinessReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->inventory()]);
    }

    public function customerReceivables(BusinessReportService $reports): JsonResponse
    {
        return response()->json([
            'data' => $reports->customerReceivables(),
            'aging' => $reports->receivablesAging(),
        ]);
    }

    public function supplierPayables(BusinessReportService $reports): JsonResponse
    {
        return response()->json([
            'data' => $reports->supplierPayables(),
            'aging' => $reports->payablesAging(),
        ]);
    }
}

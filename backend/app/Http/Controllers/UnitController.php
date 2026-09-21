<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Unit::query()->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:units,name'],
            'abbreviation' => ['required', 'string', 'max:10', 'unique:units,abbreviation'],
        ]);

        $unit = Unit::create($validated);

        return response()->json([
            'message' => 'Unit created successfully.',
            'unit' => $unit,
        ], 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Person::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->paginate(25)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'address' => [
                'nullable',
                'string',
                'max:500',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'roles' => [
                'nullable',
                'array',
            ],

            'roles.*' => [
                'string',
                'max:50',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $person = Person::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'roles' => $validated['roles'] ?? ['customer'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Person created successfully.',
            'person' => $person,
        ], 201);
    }

    public function show(Person $person, BalanceService $balances): JsonResponse
    {
        return response()->json([
            'person' => $person,
            'balance' => $balances->personBalance($person),
            'transactions' => $person->transactions()
                ->with(['account', 'category', 'loan'])
                ->latest('transaction_date')
                ->paginate(25),
        ]);
    }
}
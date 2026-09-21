<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\BalanceService;
use App\Services\CustomerPaymentService;
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

            // Distinct from `roles` (a free-form display tag list): this is
            // the actual flag SaleService checks before letting a person be
            // used as a sale's customer_id, so it must be set explicitly
            // rather than inferred from roles server-side.
            'is_customer' => [
                'sometimes',
                'boolean',
            ],

            'credit_limit' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'payment_terms_days' => [
                'nullable',
                'integer',
                'gte:0',
            ],
        ]);

        $person = Person::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'roles' => $validated['roles'] ?? ['customer'],
            'is_active' => $validated['is_active'] ?? true,
            'is_customer' => $validated['is_customer'] ?? false,
            'credit_limit' => $validated['credit_limit'] ?? null,
            'payment_terms_days' => $validated['payment_terms_days'] ?? null,
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

    public function update(Request $request, Person $person): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'is_customer' => ['sometimes', 'boolean'],
            'credit_limit' => ['nullable', 'numeric', 'gte:0'],
            'payment_terms_days' => ['nullable', 'integer', 'gte:0'],
        ]);

        $person->update($validated);

        return response()->json([
            'message' => 'Person updated successfully.',
            'person' => $person->fresh(),
        ]);
    }

    /**
     * The Dashboard's "Receive Payment" quick action: a payment FROM this
     * customer, not tied to one invoice already open. Applies across their
     * outstanding sales oldest-first via CustomerPaymentService - the exact
     * same customer_payment/Transaction/Sale machinery a per-invoice
     * payment uses, just choosing which invoice(s) to apply it to.
     */
    public function receivePayment(Request $request, Person $person, CustomerPaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $payments->receiveForCustomer(
            $person,
            (float) $validated['amount'],
            (int) $validated['account_id'],
            $validated['reference'] ?? null,
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Customer payment recorded successfully.',
            'applied' => $result['applied'],
            'sales' => $result['sales'],
        ]);
    }
}
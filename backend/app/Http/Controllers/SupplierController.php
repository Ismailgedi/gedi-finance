<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\BalanceService;
use App\Services\SupplierPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Supplier::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->paginate(25)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_code' => ['nullable', 'string', 'max:50', 'unique:suppliers,supplier_code'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'credit_limit' => ['nullable', 'numeric', 'gte:0'],
            'payment_terms_days' => ['nullable', 'integer', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $supplier = Supplier::create([
            ...$validated,
            'supplier_code' => $validated['supplier_code'] ?? $this->nextSupplierCode(),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Supplier created successfully.',
            'supplier' => $supplier,
        ], 201);
    }

    public function show(Supplier $supplier, BalanceService $balances): JsonResponse
    {
        return response()->json([
            'supplier' => $supplier,
            'balance' => $balances->supplierBalance($supplier),
        ]);
    }

    /**
     * Only contact/reference fields are editable. supplier_code is
     * deliberately excluded: it's the identifier every historical
     * purchase/payment transaction references by supplier_id, and while
     * changing the code text itself wouldn't break that FK, treating it as
     * immutable keeps printed purchase records and the code stable once
     * issued, matching supplier_code's role as a stable business reference.
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'credit_limit' => ['nullable', 'numeric', 'gte:0'],
            'payment_terms_days' => ['nullable', 'integer', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $supplier->update($validated);

        return response()->json([
            'message' => 'Supplier updated successfully.',
            'supplier' => $supplier->fresh(),
        ]);
    }

    /**
     * The Dashboard's "Pay Supplier" quick action: a payment TO this
     * supplier, not tied to one purchase already open. Applies across
     * their outstanding purchases oldest-first via SupplierPaymentService -
     * the same supplier_payment/Transaction/Purchase machinery a
     * per-purchase payment uses, just choosing which purchase(s) it pays.
     */
    public function pay(Request $request, Supplier $supplier, SupplierPaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $payments->payForSupplier(
            $supplier,
            (float) $validated['amount'],
            (int) $validated['account_id'],
            $validated['reference'] ?? null,
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Supplier payment recorded successfully.',
            'applied' => $result['applied'],
            'purchases' => $result['purchases'],
        ]);
    }

    private function nextSupplierCode(): string
    {
        $prefix = 'SUP-';
        $last = Supplier::query()
            ->where('supplier_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('supplier_code');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}

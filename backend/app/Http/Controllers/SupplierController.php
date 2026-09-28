<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Supplier;
use App\Services\BalanceService;
use App\Services\SupplierPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        // Defensive belt-and-braces: any Business Contact already marked
        // is_supplier=true that somehow doesn't have a linked profile yet
        // (e.g. the flag was set through a path other than
        // PersonController, or predates this migration on an environment
        // that hasn't run it) gets one provisioned before listing, so
        // Purchases never silently misses a supplier that should be
        // selectable.
        Person::query()
            ->where('is_supplier', true)
            ->where('is_active', true)
            ->whereDoesntHave('supplier')
            ->each(fn (Person $person) => Supplier::provisionForPerson($person));

        return response()->json(
            Supplier::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->paginate(25)
        );
    }

    /**
     * Creates the Business Contact and its linked Supplier profile
     * together - the "Add Supplier" screen is a purpose-built view onto
     * the same canonical Person records Business Contacts uses, not a
     * separate identity. It always creates a new Person (this form is
     * for a supplier who doesn't exist as a contact yet); if one with
     * the same identity already exists, mark them as a supplier from
     * Business Contacts instead so nothing gets duplicated.
     */
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

        $person = Person::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'roles' => ['supplier'],
            'is_active' => true,
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $supplier = Supplier::provisionForPerson($person);
        $supplier->update([
            'supplier_code' => $validated['supplier_code'] ?? $supplier->supplier_code,
            'email' => $validated['email'] ?? null,
            'credit_limit' => $validated['credit_limit'] ?? null,
            'payment_terms_days' => $validated['payment_terms_days'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Supplier created successfully.',
            'supplier' => $supplier->fresh(),
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

        // Editing a supplier here and editing the same person from
        // Business Contacts are two views onto one identity - keep the
        // linked Person's name/phone/address from drifting out of sync
        // regardless of which screen was used.
        $supplier->person?->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
        ]);

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
            // is_active,1: see the accounting audit's Finding 4 - an
            // account actually paying real money must be active.
            'account_id' => ['required', 'integer', 'exists:accounts,id,is_active,1'],
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
            'applied_to_opening_balance' => $result['applied_to_opening_balance'],
        ]);
    }
}

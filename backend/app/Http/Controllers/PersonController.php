<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Supplier;
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

            // Same idea for the supplier side: the flag that determines
            // whether this person gets a linked Supplier profile (and is
            // therefore selectable in Purchases) - see
            // Supplier::provisionForPerson() below.
            'is_supplier' => [
                'sometimes',
                'boolean',
            ],

            // Marks this contact as a business owner, eligible to be
            // selected on an owner_contribution/owner_withdrawal
            // transaction (see TransactionService::validateBusinessRules).
            // Unlike is_supplier, this has no linked profile to provision -
            // owners have no role-specific fields beyond the flag itself.
            'is_owner' => [
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
            'is_supplier' => $validated['is_supplier'] ?? false,
            'is_owner' => $validated['is_owner'] ?? false,
            'credit_limit' => $validated['credit_limit'] ?? null,
            'payment_terms_days' => $validated['payment_terms_days'] ?? null,
        ]);

        // A person marked as a supplier at creation time must be
        // immediately selectable in Purchases - no separate manual
        // "Add Supplier" step. name/phone/address are already fresh
        // since the profile is being created right now.
        if ($person->is_supplier) {
            Supplier::provisionForPerson($person);
        }

        return response()->json([
            'message' => 'Person created successfully.',
            'person' => $person,
        ], 201);
    }

    /**
     * `balances` breaks this person's activity into the same disjoint
     * buckets the Loans & Debts page and Business Position already use
     * (BalanceService's own bucket methods, never a second calculation) -
     * a customer receivable, a loan given, a loan received and an other
     * receivable are four separate debts, not one number. `combined` is
     * kept for anyone who still wants the old all-activity total, but
     * explicitly labeled as such - never treat it as any one bucket's
     * balance, and never use it to validate a repayment (see
     * TransactionService::assertWithinOutstandingBalance()).
     */
    public function show(Person $person, BalanceService $balances): JsonResponse
    {
        return response()->json([
            'person' => $person,
            'balances' => [
                'customer_receivable' => $balances->customerReceivableBalance($person),
                'loans_given' => $balances->loanGivenBalance($person),
                'loans_received' => $balances->loanReceivedBalance($person),
                'other_receivable' => $balances->otherReceivableBalance($person),
                'combined' => $balances->personBalance($person),
            ],
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
            'is_supplier' => ['sometimes', 'boolean'],
            'is_owner' => ['sometimes', 'boolean'],
            'credit_limit' => ['nullable', 'numeric', 'gte:0'],
            'payment_terms_days' => ['nullable', 'integer', 'gte:0'],
        ]);

        $person->update($validated);

        // Keep the linked Supplier profile (if any) in step with this
        // Business Contact - provision one the moment is_supplier turns
        // on, and keep name/phone/address from drifting out of sync
        // however this record is edited going forward.
        if ($person->is_supplier) {
            $supplier = Supplier::provisionForPerson($person);
            $supplier->update([
                'name' => $person->name,
                'phone' => $person->phone,
                'address' => $person->address,
            ]);
        }

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
            // is_active,1: see the accounting audit's Finding 4 - an
            // account actually receiving real money must be active.
            'account_id' => ['required', 'integer', 'exists:accounts,id,is_active,1'],
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
            'applied_to_opening_balance' => $result['applied_to_opening_balance'],
        ]);
    }
}
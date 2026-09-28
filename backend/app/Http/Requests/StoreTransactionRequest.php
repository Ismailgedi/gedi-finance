<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->input('type');

        // Mirrors TransactionService::validateBusinessRules() for exactly
        // the publicly-creatable subset (see TransactionType::
        // publiclyCreatable() and the `type` rule below) - every
        // service-owned type (credit_sale/cash_sale/customer_payment/
        // purchase/supplier_payment/sale_return*/purchase_return*/
        // purchase_cost/opening_balance_*) never reaches this far, so it no
        // longer needs a place here. sale_id/purchase_id are therefore
        // always optional now - no publicly-creatable type needs either.
        $personRequired = in_array($type, [
            'loan_given',
            'loan_repayment',
            'loan_received',
            'loan_payment',
            'debt_created',
            'debt_payment',
            'owner_contribution',
            'owner_withdrawal',
        ], true);

        $accountRequired = in_array($type, [
            'income',
            'expense',
            'loan_repayment',
            'loan_received',
            'loan_payment',
            'account_transfer',
            'adjustment',
            'owner_contribution',
            'owner_withdrawal',
        ], true);

        return [
            // Restricted to the publicly-creatable subset (see
            // TransactionType::publiclyCreatable()) - every other type
            // (cash_sale/credit_sale/customer_payment/purchase/
            // supplier_payment/sale_return*/purchase_return*/purchase_cost/
            // opening_balance_*) is service-owned and only ever created
            // internally by its owning service, never through this generic
            // endpoint. TransactionService::create() enforces the same
            // restriction server-side as a second, authoritative layer -
            // this rule exists so a disallowed type fails validation
            // cleanly rather than reaching the service at all.
            'type' => [
                'required',
                Rule::in(array_map(fn (TransactionType $case) => $case->value, TransactionType::publiclyCreatable())),
            ],

            'person_id' => [
                $personRequired ? 'required' : 'nullable',
                'nullable',
                'integer',
                'exists:people,id,is_active,1',
            ],

            // is_active,1: see the accounting audit's Finding 4 - an
            // account actually moving real money (income/expense/loan
            // settlement/transfer/owner capital/...) must be active.
            'account_id' => [
                $accountRequired ? 'required' : 'nullable',
                'nullable',
                'integer',
                'exists:accounts,id,is_active,1',
            ],

            'destination_account_id' => [
                'nullable',
                'integer',
                'exists:accounts,id,is_active,1',
                'different:account_id',
            ],

            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],

            'supplier_id' => [
                'nullable',
                'integer',
                'exists:suppliers,id',
            ],

            'sale_id' => [
                'nullable',
                'integer',
                'exists:sales,id',
            ],

            'purchase_id' => [
                'nullable',
                'integer',
                'exists:purchases,id',
            ],

            'loan_id' => [
                'nullable',
                'integer',
                'exists:loans,id',
            ],

            'amount' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'currency' => [
                'sometimes',
                'string',
                'size:3',
            ],

            'description' => [
                'required',
                'string',
                'min:3',
                'max:1000',
            ],

            'reference' => [
                'nullable',
                'string',
                'max:100',
            ],

            'transaction_date' => [
                'nullable',
                'date',
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'posted',
                    'voided',
                ]),
            ],

            'items' => [
                'nullable',
                'array',
            ],

            'items.*.description' => [
                'required_with:items',
                'string',
                'max:255',
            ],

            'items.*.quantity' => [
                'required_with:items',
                'numeric',
                'gt:0',
            ],

            'items.*.unit_price' => [
                'required_with:items',
                'numeric',
                'gte:0',
            ],

            'items.*.total' => [
                'required_with:items',
                'numeric',
                'gte:0',
            ],
        ];
    }
}
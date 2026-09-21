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

        // Mirrors TransactionService::validateBusinessRules() - cash_sale
        // intentionally excludes person_id since it's paid in full at the
        // time of sale (including walk-in customers with no record).
        $personRequired = in_array($type, [
            'credit_sale',
            'customer_payment',
            'loan_given',
            'loan_repayment',
            'loan_received',
            'loan_payment',
            'debt_created',
            'debt_payment',
        ], true);

        $accountRequired = in_array($type, [
            'income',
            'expense',
            'cash_sale',
            'customer_payment',
            'loan_repayment',
            'loan_received',
            'loan_payment',
            'account_transfer',
            'adjustment',
        ], true);

        return [
            'type' => [
                'required',
                Rule::enum(TransactionType::class),
            ],

            'person_id' => [
                $personRequired ? 'required' : 'nullable',
                'nullable',
                'integer',
                'exists:people,id,is_active,1',
            ],

            'account_id' => [
                $accountRequired ? 'required' : 'nullable',
                'nullable',
                'integer',
                'exists:accounts,id',
            ],

            'destination_account_id' => [
                'nullable',
                'integer',
                'exists:accounts,id',
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
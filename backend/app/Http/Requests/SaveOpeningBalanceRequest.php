<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'as_of_date' => ['required', 'date'],
            'opening_equity' => ['required', 'numeric'],

            'accounts' => ['nullable', 'array'],
            'accounts.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'accounts.*.opening_balance' => ['required', 'numeric'],

            'items' => ['nullable', 'array'],
            'items.*.category' => [
                'required',
                Rule::in(['receivable', 'other_receivable', 'payable', 'loan_given', 'loan_received', 'capital', 'inventory']),
            ],
            'items.*.person_id' => ['nullable', 'integer', 'exists:people,id'],
            'items.*.supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'items.*.amount' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}

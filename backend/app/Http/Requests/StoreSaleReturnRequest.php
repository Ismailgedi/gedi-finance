<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'return_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'settlement_method' => ['required', Rule::in(['credit', 'refund'])],
            // is_active,1: see the accounting audit's Finding 4 - a refund
            // account actually paying real money must be active.
            'refund_account_id' => ['required_if:settlement_method,refund', 'nullable', 'integer', 'exists:accounts,id,is_active,1'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.is_saleable' => ['sometimes', 'boolean'],
        ];
    }
}

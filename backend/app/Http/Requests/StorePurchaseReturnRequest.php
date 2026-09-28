<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_id' => ['required', 'integer', 'exists:purchases,id'],
            'return_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'settlement_method' => ['required', Rule::in(['credit', 'refund'])],
            // is_active,1: see the accounting audit's Finding 4 - a refund
            // account actually receiving real money must be active.
            'refund_account_id' => ['required_if:settlement_method,refund', 'nullable', 'integer', 'exists:accounts,id,is_active,1'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', 'integer', 'exists:purchase_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}

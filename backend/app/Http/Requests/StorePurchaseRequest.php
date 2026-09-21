<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'purchase_number' => ['nullable', 'string', 'max:50', 'unique:purchases,purchase_number'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'discount' => ['nullable', 'numeric', 'gte:0'],
            'amount_paid' => ['nullable', 'numeric', 'gte:0'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0'],
        ];
    }
}

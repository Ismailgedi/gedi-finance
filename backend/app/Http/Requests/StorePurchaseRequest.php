<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // is_active,1: see the accounting audit's Finding 4 - an
            // account actually paying real money must be active.
            'account_id' => ['nullable', 'integer', 'exists:accounts,id,is_active,1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0'],

            // Landed costs (transport, customs, ...) - see
            // PurchaseAdditionalCost/PurchaseService::create(). account_id
            // is validated as required specifically for type=third_party in
            // PurchaseService itself (a cross-field rule, same pattern the
            // service already uses for its other business rules).
            'additional_costs' => ['nullable', 'array'],
            'additional_costs.*.description' => ['required_with:additional_costs', 'string', 'max:255'],
            'additional_costs.*.amount' => ['required_with:additional_costs', 'numeric', 'gt:0'],
            'additional_costs.*.type' => ['required_with:additional_costs', Rule::in(['supplier_bundled', 'third_party'])],
            'additional_costs.*.account_id' => ['nullable', 'integer', 'exists:accounts,id,is_active,1'],
            'additional_costs.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'physical_quantity' => ['required', 'numeric', 'gte:0'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'adjustment_date' => ['nullable', 'date'],
            'unit_cost' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:100'],
            'payment_date' => ['nullable', 'date'],
        ];
    }
}

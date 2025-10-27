<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MensualidadPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'receipt_pdf_base64' => ['required', 'string'],
            'message' => ['nullable', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:150'],
            'payment_date' => ['nullable', 'date'],
        ];
    }
}


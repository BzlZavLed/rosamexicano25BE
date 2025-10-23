<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseLegacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'totalventa'     => ['required', 'numeric', 'min:0.01'],
            'method'    => ['nullable', 'in:efectivo,debit,credit'],
            'concepto'  => ['required', 'string', 'max:255'],
            'fecha'     => ['nullable', 'string', 'max:10'],
            'vendedor'  => ['nullable', 'string', 'max:65'],
        ];
    }
}


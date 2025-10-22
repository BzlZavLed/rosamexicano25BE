<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetStockRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ident'      => ['nullable','integer'],
            'existencia' => ['required','integer','min:0'],
            'mode'       => ['nullable','in:add,set'],

        ];
    }

    public function prepareForValidation(): void
    {
        // Normalize empties to null
        $this->merge([
            'product_id' => $this->input('product_id') ?: null,
            'ident'      => $this->input('ident') ?: null,
        ]);
    }
}

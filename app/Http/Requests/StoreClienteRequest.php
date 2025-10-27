<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200'],
            'email'  => ['required', 'email', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:25'],
        ];
    }
}

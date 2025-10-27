<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:200'],
            'email'  => [
                'sometimes',
                'required',
                'email',
                'max:200',
                Rule::unique('clientes', 'email')->ignore($this->route('cliente')),
            ],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:25'],
        ];
    }
}

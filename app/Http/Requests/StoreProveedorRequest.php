<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate with policies/roles later
    }

    public function rules(): array
    {
        return [
            'ident'    => ['required','integer'],
            'nombre'   => ['required','string','max:60'],
            'fecha'    => ['required','string','max:10'],   // stays as varchar(10)
            'tel'      => ['nullable','string','max:10'],
            'email'    => ['nullable','email','max:100'],
            'calle'    => ['nullable','string','max:100'],
            'bancaria' => ['nullable','string','max:50'],
            'ciudad'   => ['nullable','string','max:100'],
            'importe'  => ['nullable','numeric'],
            'sucursal' => ['nullable','string','max:100'],
            'tipo'     => ['required', Rule::in(['normal', 'consigna', 'porcentaje'])],
            'porcentaje_comision' => ['nullable','integer','in:20,30'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $tipo = $this->input('tipo');
        if ($tipo !== 'porcentaje') {
            $this->merge(['porcentaje_comision' => null]);
        }
        if ($tipo !== 'normal') {
            $this->merge(['importe' => null]);
        }
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate with policies/roles later
    }

    public function rules(): array
    {
        return [
            'ident'    => ['sometimes','integer'],
            'nombre'   => ['sometimes','string','max:60'],
            'fecha'    => ['sometimes','string','max:10'],
            'tel'      => ['sometimes','nullable','string','max:10'],
            'email'    => ['sometimes','nullable','email','max:100'],
            'calle'    => ['sometimes','nullable','string','max:100'],
            'bancaria' => ['sometimes','nullable','string','max:50'],
            'ciudad'   => ['sometimes','nullable','string','max:100'],
            'importe'  => ['sometimes','nullable','numeric'],
            'sucursal' => ['sometimes','nullable','string','max:100'],
            'tipo'     => ['sometimes', Rule::in(['normal','consigna','porcentaje'])],
            'porcentaje_comision' => ['sometimes','nullable','integer','in:20,30'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('tipo')) {
            $tipo = $this->input('tipo');
            if ($tipo !== 'porcentaje') {
                $this->merge(['porcentaje_comision' => null]);
            }
            if ($tipo !== 'normal') {
                $this->merge(['importe' => null]);
            }
        }
    }
}

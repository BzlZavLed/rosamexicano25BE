<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}

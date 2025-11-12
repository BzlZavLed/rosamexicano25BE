<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ident'       => ['sometimes','integer'],
            'nombre'      => ['sometimes','string','max:100'],
            'descripcion' => ['sometimes','nullable','string','max:100'],
            'fecha'       => ['sometimes','string','max:10'],
            'proveedorid' => ['sometimes','integer','exists:proveedores,ident'],
            'usuario'     => ['sometimes','string','max:80'],
            'precio'      => ['sometimes','numeric','min:0'],
            'precio_proveedor' => ['sometimes','nullable','numeric','min:0'],
        ];
    }
}

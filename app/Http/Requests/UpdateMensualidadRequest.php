<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMensualidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_cobro' => ['sometimes', 'required', 'date'],
            'mes_cobro'   => ['sometimes', 'required', 'string', 'max:10'],
            'concepto'    => ['sometimes', 'nullable', 'string', 'max:200'],
            'nota'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'proveedor_id'=> ['sometimes', 'required', 'integer', 'exists:proveedores,id'],
            'importe'     => ['sometimes', 'required', 'numeric'],
            'status'      => ['sometimes', 'required', 'string', 'in:pending,paid'],
            'payment_date'=> ['sometimes', 'nullable', 'date'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MensualidadBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'concepto'    => ['required', 'string', 'max:200'],
            'mes_cobro'   => ['required', 'string', 'max:10'],
            'fecha_cobro' => ['required', 'date'],
            'nota'        => ['nullable', 'string', 'max:200'],
            'cobros'      => ['required', 'array', 'min:1'],
            'cobros.*.proveedor_id'      => ['required', 'integer', 'exists:proveedores,id'],
            'cobros.*.importe'           => ['required', 'numeric'],
            'cobros.*.cobro_pdf_base64'  => ['nullable', 'string'],
        ];
    }
}

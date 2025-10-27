<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMensualidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_cobro' => ['required', 'date'],
            'mes_cobro'   => ['required', 'string', 'max:10'],
            'concepto'    => ['required', 'string', 'max:200'],
            'nota'        => ['nullable', 'string', 'max:200'],
            'proveedor_id'=> ['required', 'integer', 'exists:proveedores,id'],
            'importe'     => ['required', 'numeric'],
            'status'      => ['nullable', 'string', 'in:pending,paid'],
            'payment_date'=> ['nullable', 'date'],
            'receipt_pdf_base64' => ['nullable', 'string'],
        ];
    }
}

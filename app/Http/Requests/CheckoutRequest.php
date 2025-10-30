<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool { return true; } // admin-only in controller
    public function rules(): array
    {
        return [
            'idventa'         => ['required','integer'],
            'metodo'          => ['required','string','max:50'], // EFECTIVO|TARJETA|MIXTO...
            'recibo'          => ['required','numeric','min:0'], // amount received
            'cambio'          => ['required','numeric','min:0'], // client change
            'vendedor'        => ['required','string','max:45'],
            'concepto'        => ['nullable','string','max:60'],
            'lineas'          => ['required','array','min:1'],
            'lineas.*.idProd' => ['required','integer'], // producto.id
            'lineas.*.nombre' => ['required','string','max:65'],
            'lineas.*.proveedor' => ['required','integer'], // proveedores.id
            'lineas.*.pUni'   => ['required','numeric','min:0'],
            'lineas.*.cant'   => ['required','integer','min:1'],
            'lineas.*.product_desc'=> ['nullable','numeric','min:0'],
            'lineas.*.totdesc'=> ['nullable','numeric','min:0'],
            'descuento_general' => ['nullable','numeric','min:0'],
        ];
    }
}

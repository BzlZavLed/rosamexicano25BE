<?php

// app/Http/Requests/StorePromocionRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromocionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // Target: exactly one of producto OR proveedor (both null is invalid; both set also invalid)
            'producto'  => ['nullable','integer','exists:producto,ident'],
            'proveedor' => ['nullable','integer','exists:proveedores,ident'],

            'tipo'      => ['required', Rule::in(['descuento','bundle','precio_fijo'])],

            // For tipo='descuento'
            'descuento' => ['nullable','numeric','min:0','max:100'],
            'monto'     => ['nullable','numeric','min:0.01'],

            // For tipo='bundle' (e.g., 3x2 => mincompra=2, gratis=1)
            // For tipo='precio_fijo', mincompra is the N in "N productos por monto".
            'mincompra' => ['nullable','integer','min:1'],
            'gratis'    => ['nullable','integer','min:1'],

            'inicia'    => ['nullable','date'],
            'vence'     => ['required','date'],
            'estado'    => ['boolean'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $prod = $this->input('producto');
            $prov = $this->input('proveedor');
            if (empty($prod) && empty($prov)) {
                $v->errors()->add('target', 'Debes seleccionar un producto o un proveedor.');
            }
            if (!empty($prod) && !empty($prov)) {
                $v->errors()->add('target', 'No puedes combinar producto y proveedor en la misma promoción.');
            }

            $tipo = $this->input('tipo');
            if ($tipo === 'descuento') {
                if ($this->input('descuento') === null) {
                    $v->errors()->add('descuento', 'El campo descuento es obligatorio para tipo=descuento.');
                }
            } elseif ($tipo === 'bundle') {
                if ($this->input('mincompra') === null || $this->input('gratis') === null) {
                    $v->errors()->add('mincompra', 'mincompra y gratis son obligatorios para tipo=bundle.');
                }
            } elseif ($tipo === 'precio_fijo') {
                if ($this->input('mincompra') === null || $this->input('monto') === null) {
                    $v->errors()->add('monto', 'mincompra y monto son obligatorios para tipo=precio_fijo.');
                }
            }
        });
    }
}

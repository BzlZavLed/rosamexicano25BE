<?php

// app/Http/Requests/UpdatePromocionRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromocionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'producto'  => ['nullable','integer','exists:producto,ident'],
            'proveedor' => ['nullable','integer','exists:proveedores,ident'],
            'tipo'      => ['nullable', Rule::in(['descuento','bundle'])],
            'descuento' => ['nullable','numeric','min:0','max:100'],
            'mincompra' => ['nullable','integer','min:1'],
            'gratis'    => ['nullable','integer','min:1'],
            'inicia'    => ['nullable','date'],
            'vence'     => ['nullable','date'],
            'estado'    => ['nullable','boolean'],
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

            $tipo = $this->input('tipo', $this->route('promocion')->tipo ?? null);
            if ($tipo === 'descuento') {
                if ($this->has('tipo') || $this->has('descuento')) {
                    if ($this->input('descuento') === null) {
                        $v->errors()->add('descuento', 'El descuento es obligatorio para tipo=descuento.');
                    }
                }
            } elseif ($tipo === 'bundle') {
                if ($this->has('tipo') || $this->has('mincompra') || $this->has('gratis')) {
                    if ($this->input('mincompra') === null || $this->input('gratis') === null) {
                        $v->errors()->add('mincompra', 'mincompra y gratis son obligatorios para tipo=bundle.');
                    }
                }
            }
        });
    }
}

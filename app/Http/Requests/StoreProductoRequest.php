<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
class StoreProductoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ident'       => ['required','integer'],          // barcode/sku
            'nombre'      => ['required','string','max:100'],
            'descripcion' => ['nullable','string','max:100'],
            'fecha'       => ['required','string','max:10'],  // stays as varchar(10)
            'proveedorid' => ['required','integer','exists:proveedores,ident'],
            'precio'      => ['required','numeric','min:0'],
            'precio_proveedor' => ['nullable','numeric','min:0'],
        ];
    }

    public function attributes(): array
    {
        // Friendly field names in errors
        return [
            'ident'       => 'identificador interno',
            'nombre'      => 'nombre',
            'descripcion' => 'descripción',
            'proveedorid' => 'proveedor',
            'precio'      => 'precio',
            'fecha'       => 'fecha de creación',
        ];
    }

    // Optional: custom messages
    public function messages(): array
    {
        return [
            'proveedorid.exists' => 'El proveedor seleccionado no existe.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        // Log the validation failure with context
        Log::warning('Producto validation failed', [
            'user_id'  => optional($this->user())->id,
            'payload'  => $this->all(),
            'errors'   => $validator->errors()->toArray(),
            'route'    => $this->path(),
            'ip'       => $this->ip(),
        ]);

        // Return a clean JSON 422 the frontend can consume
        throw new HttpResponseException(
            response()->json([
                'message' => 'Errores de validación.',
                'errors'  => $validator->errors(), // { field: [msg,msg], ... }
            ], 422)
        );
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto' => ['nullable', 'numeric', 'min:0.01'],
            'totalventa' => ['nullable', 'numeric', 'min:0.01'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'concepto' => ['nullable', 'string', 'max:255'],
            'fecha' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $amount = $this->input('monto');
            if ($amount === null) {
                $amount = $this->input('totalventa');
            }

            if ($amount === null || (float) $amount <= 0) {
                $validator->errors()->add('monto', 'El monto del egreso es requerido y debe ser mayor a cero.');
            }

            $descripcion = $this->input('descripcion');
            if ($descripcion === null) {
                $descripcion = $this->input('concepto');
            }

            if (!is_string($descripcion) || trim($descripcion) === '') {
                $validator->errors()->add('descripcion', 'La descripción del egreso es obligatoria.');
            }
        });
    }
}

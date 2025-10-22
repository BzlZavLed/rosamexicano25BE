<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CajaLegacyRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'fecha'         => ['nullable','string','max:10'], // yyyy-mm-dd (stored as varchar(10))
            'saldo'         => ['nullable','numeric'],
            'saldosistema'  => ['nullable','numeric'],
            'nota'          => ['nullable','string','max:200'], // (optional text you may want to keep client-side)
        ];
    }
}

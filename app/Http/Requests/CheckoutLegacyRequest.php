<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutLegacyRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'items'               => ['required','array','min:1'],
            'items.*.ident'       => ['required','integer'],
            'items.*.qty'         => ['required','integer','min:1'],
            'items.*.discount_percent' => ['nullable','numeric','min:0','max:100'],
            'items.*.discount_amount'  => ['nullable','numeric','min:0'],
            'items.*.product_desc'     => ['nullable','numeric','min:0'],
            'discount_percent'    => ['nullable','numeric','min:0','max:100'],
            'ie'                  => ['nullable','integer'],
            'payment.method'      => ['required','in:efectivo,tarjeta,transferencia'],
            // para efectivo requerimos 'received'; para tarjeta/transferencia se ignora
            'payment.received'    => ['nullable','numeric','min:0'],
        ];
    }
}

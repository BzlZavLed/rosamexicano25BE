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
            'discount_percent'    => ['nullable','numeric','min:0','max:100'],
            'payment.method'      => ['required','in:cash,debit,credit'],
            // for cash we’ll require 'received'; for cards we ignore it
            'payment.received'    => ['nullable','numeric','min:0'],
        ];
    }
}

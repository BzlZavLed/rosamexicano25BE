<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashierFindRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'barcode'       => ['nullable','integer'],
            'search'        => ['nullable','string','max:100'],
            'proveedor_id'  => ['nullable','integer'],
            'per_page'      => ['nullable','integer','min:1','max:50'],
        ];
    }
}

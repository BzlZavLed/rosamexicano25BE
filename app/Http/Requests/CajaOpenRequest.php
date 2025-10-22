<?php
// app/Http/Requests/CajaOpenRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CajaOpenRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'saldo'         => ['nullable','numeric','min:0','required_without:saldoinicial'],
            'saldoinicial'  => ['nullable','numeric','min:0','required_without:saldo'],
            'fecha' => ['nullable','regex:/^\d{2}\/\d{2}\/\d{2}$/'] // d/m/y if provided
        ];
    }
}

// app/Http/Requests/CajaCloseRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CajaCloseRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'saldofinal'    => ['nullable','numeric','min:0'],      // counted cash at closing (optional)
            'saldosistema'  => ['nullable','numeric','min:0'],      // if you want to override auto-calc
            'fecha'         => ['nullable','regex:/^\d{2}\/\d{2}\/\d{2}$/'],
        ];
    }
}

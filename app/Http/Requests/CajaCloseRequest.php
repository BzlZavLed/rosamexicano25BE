<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Http\FormRequest;

class CajaCloseRequest extends FormRequest
{
    public function authorize(): bool { return true; } // admin-only in controller
    public function rules(): array
    {
        return [
            'saldofinal' => ['required','numeric','min:0'], // counted cash at close
        ];
    }
}

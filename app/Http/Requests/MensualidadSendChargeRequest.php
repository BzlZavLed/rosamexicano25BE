<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MensualidadSendChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'  => ['required', 'email'],
            'asunto' => ['nullable', 'string', 'max:150'],
        ];
    }
}

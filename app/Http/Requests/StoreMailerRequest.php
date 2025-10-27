<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMailerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mail'    => ['required', 'string', 'max:255'],
            'asunto'  => ['required', 'string', 'max:150'],
            'mensaje' => ['required', 'string'],
            'status'  => ['required', 'integer'],
            'fecha'   => ['nullable', 'date'],
        ];
    }
}

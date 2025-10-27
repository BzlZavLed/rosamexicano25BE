<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMailerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mail'    => ['sometimes', 'required', 'string', 'max:255'],
            'asunto'  => ['sometimes', 'required', 'string', 'max:150'],
            'mensaje' => ['sometimes', 'required', 'string'],
            'status'  => ['sometimes', 'required', 'integer'],
            'fecha'   => ['sometimes', 'nullable', 'date'],
        ];
    }
}

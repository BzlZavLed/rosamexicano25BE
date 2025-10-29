<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MailerResendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'   => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:150'],
            'body'    => ['required', 'string'],
            'pdf'     => ['nullable', 'string'],
            'url'     => ['nullable', 'string'],
        ];
    }
}

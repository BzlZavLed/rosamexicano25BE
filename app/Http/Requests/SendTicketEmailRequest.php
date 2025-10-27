<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendTicketEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'venta_id'           => ['required', 'integer'],
            'canal'              => ['nullable', 'string', 'max:30'],
            'cliente'            => ['required', 'array'],
            'cliente.nombre'     => ['required', 'string', 'max:200'],
            'cliente.email'      => ['nullable', 'email', 'max:200'],
            'cliente.telefono'   => ['nullable', 'string', 'max:25'],
            'ticket_pdf_base64'  => ['required', 'string'],
            'subject'     => ['nullable', 'string', 'max:150'],
            'template_id' => ['nullable', 'string', 'max:120'],
            'message'     => ['nullable', 'string', 'max:500'],
        ];
    }
}

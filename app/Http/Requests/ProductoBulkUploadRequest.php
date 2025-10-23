<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductoBulkUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file'           => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'delimiter'      => ['nullable', 'string', 'in:,;|'],
            'update_existing'=> ['nullable', 'boolean'],
        ];
    }
}


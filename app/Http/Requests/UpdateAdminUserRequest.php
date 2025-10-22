<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('usuario'); // from route parameter
        return [
            'nombre'   => ['sometimes','string','max:65'],
            'email'    => ['sometimes','email','max:65',"unique:usuarios,email,{$id},id"],
            'password' => ['sometimes','string','min:6'],
            'puesto'   => ['sometimes','string','max:20'],
            'priv1'    => ['sometimes','integer'],
            'priv2'    => ['sometimes','integer'],
            'priv3'    => ['sometimes','integer'],
            'priv4'    => ['sometimes','integer'],
        ];
    }
}

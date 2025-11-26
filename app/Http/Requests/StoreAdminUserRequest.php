<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminUserRequest extends FormRequest
{
    public function authorize(): bool { return true; } // lock with policy later

    public function rules(): array
    {
        return [
            'nombre'   => ['required','string','max:65'],
            'email'    => ['required','email','max:65','unique:usuarios,email'],
            'password' => ['required','string','min:6'],
            'puesto'   => ['sometimes','string','max:20'], // legacy support
            'role'     => ['sometimes','in:admin,cashier'],
            'modules'  => ['sometimes','array'],
            'modules.*'=> ['string','max:50'],
            'staff_role_id' => ['sometimes','nullable','exists:staff_roles,id'],
            'priv1'    => ['sometimes','integer'],
            'priv2'    => ['sometimes','integer'],
            'priv3'    => ['sometimes','integer'],
            'priv4'    => ['sometimes','integer'],
        ];
    }
}

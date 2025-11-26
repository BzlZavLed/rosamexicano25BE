<?php

namespace App\Http\Requests;

use App\Models\Usuario;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $routeParam = $this->route('usuario');
        $id = $routeParam instanceof Usuario ? $routeParam->getKey() : $routeParam;

        return [
            'nombre'   => ['sometimes','string','max:65'],
            'email'    => ['sometimes','email','max:65',"unique:usuarios,email,{$id},id"],
            'password' => ['sometimes','string','min:6'],
            'puesto'   => ['sometimes','string','max:20'],
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

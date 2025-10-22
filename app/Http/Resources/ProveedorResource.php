<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'ident'    => $this->ident,
            'nombre'   => $this->nombre,
            'fecha'    => $this->fecha,
            'tel'      => $this->tel,
            'email'    => $this->email,
            'calle'    => $this->calle,
            'bancaria' => $this->bancaria,
            'ciudad'   => $this->ciudad,
            'importe'  => $this->importe,
            'sucursal' => $this->sucursal,
        ];
    }
}

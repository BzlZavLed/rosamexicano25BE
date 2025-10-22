<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Expect joined columns when listing (producto_nombre, producto_precio, proveedor_id, proveedor_nombre)
        return [
            'id'          => $this->id,
            'ident'       => $this->ident,
            'existencia'  => $this->existencia,
            'importe'     => $this->importe,
            'provee'      => $this->provee,
            // Joined product/provider info when present
            'producto'    => [
                'id'     => $this->when(isset($this->producto_id), fn() => (int)$this->producto_id),
                'ident'  => $this->ident,
                'nombre' => $this->when(isset($this->producto_nombre), fn() => $this->producto_nombre),
                'precio' => $this->when(isset($this->producto_precio), fn() => $this->producto_precio),
            ],
            'proveedor'   => [
                'id'     => $this->when(isset($this->proveedor_id), fn() => (int)$this->proveedor_id),
                'nombre' => $this->when(isset($this->proveedor_nombre), fn() => $this->proveedor_nombre),
            ],
        ];
    }
}

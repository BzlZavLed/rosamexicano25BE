<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'ident' => $this->ident,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'precio' => $this->precio,
            'precio_proveedor' => $this->precio_proveedor,
            'fecha' => $this->fecha,
            'proveedorid' => $this->proveedorid,

            'proveedor' => new ProveedorResource($this->whenLoaded('proveedor')),

            // Either nest full resource:
            'inventario' => $this->whenLoaded('inventario', function () {
                return [
                    'id' => optional($this->inventario)->id,
                    'existencia' => optional($this->inventario)->existencia,
                    'importe' => optional($this->inventario)->importe,
                    'precio_individual' => optional($this->inventario)->precio_individual,
                    'provee' => optional($this->inventario)->provee,
                ];
            }),

            // Or expose convenience flat fields:
            'stock' => optional($this->inventario)->existencia,
            'stock_importe' => optional($this->inventario)->importe,
            'stock_unit_price' => optional($this->inventario)->precio_individual,
        ];
    }
}

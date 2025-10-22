<?php

// app/Http/Resources/PromocionResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PromocionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'producto'   => $this->producto,   // ident
            'proveedor'  => $this->proveedor,  // ident
            'tipo'       => $this->tipo,
            'descuento'  => $this->descuento,
            'mincompra'  => $this->mincompra,
            'gratis'     => $this->gratis,
            'inicia'     => optional($this->inicia)->toDateString(),
            'vence'      => optional($this->vence)->toDateString(),
            'estado'     => (bool) $this->estado,
            'activa'     => $this->activa, // computed
            // Optional: joined names
            'producto_nombre'  => optional($this->productoRef)->nombre,
            'proveedor_nombre' => optional($this->proveedorRef)->nombre,
        ];
    }
}


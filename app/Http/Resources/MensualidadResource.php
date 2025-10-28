<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MensualidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'fecha'    => optional($this->fecha)->toDateString(),
            'fecha_cobro' => optional($this->fecha)->toDateString(),
            'nombre'   => $this->nombre,
            'concepto' => $this->concepto,
            'mes_cobro'=> $this->mes_cobro,
            'nota'     => $this->nota,
            'importe'  => (float) $this->importe,
            'proveedor_id' => $this->proveedor_id,
            'status'   => $this->status,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'receipt_path' => $this->receipt_path,
            'cobro_path'   => $this->cobro_path,
            'proveedor' => $this->whenLoaded('proveedor', function () {
                return [
                    'id'     => $this->proveedor->id,
                    'nombre' => $this->proveedor->nombre,
                    'email'  => $this->proveedor->email,
                ];
            }),
        ];
    }
}

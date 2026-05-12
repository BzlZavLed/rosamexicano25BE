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
            'tipo'     => $this->tipo,
            'porcentaje_comision' => $this->porcentaje_comision,
            'deleted_at' => optional($this->deleted_at)->toDateTimeString(),
            'delete_reason' => $this->delete_reason,
            'recommendation' => $this->whenLoaded('recommendedImporte', function ($rec) {
                if (!$rec) {
                    return null;
                }

                return [
                    'recommended_importe' => (float) $rec->recommended_importe,
                    'avg_monthly_sales' => (float) $rec->avg_monthly_sales,
                    'total_sales' => (float) $rec->total_sales,
                    'months' => (int) $rec->months,
                    'percentage_used' => (float) $rec->percentage_used,
                    'months_window' => (int) $rec->months_window,
                    'period_start' => optional($rec->period_start)->toDateString(),
                    'period_end' => optional($rec->period_end)->toDateString(),
                    'updated_at' => optional($rec->updated_at)->toDateTimeString(),
                ];
            }),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MailerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'mail'    => $this->mail,
            'asunto'  => $this->asunto,
            'mensaje' => $this->mensaje,
            'status'  => (int) $this->status,
            'fecha'   => $this->fecha,
            'email' => $this->email,
        ];
    }
}

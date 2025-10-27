<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MensualidadChargeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $nombre;
    public string $concepto;
    public float $importe;
    public string $fecha;
    public ?string $nota;

    protected string $subjectLine;

    public function __construct(string $nombre, string $concepto, float $importe, string $fecha, ?string $nota, string $subjectLine)
    {
        $this->nombre = $nombre;
        $this->concepto = $concepto;
        $this->importe = $importe;
        $this->fecha = $fecha;
        $this->nota = $nota;
        $this->subjectLine = $subjectLine;
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->view('emails.mensualidad_charge')
            ->with([
                'nombre'   => $this->nombre,
                'concepto' => $this->concepto,
                'importe'  => $this->importe,
                'fecha'    => $this->fecha,
                'nota'     => $this->nota,
            ]);
    }
}


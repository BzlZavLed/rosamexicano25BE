<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MensualidadPaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $nombre;
    public ?string $mensaje;
    public ?string $telefono;
    public string $concepto;
    public float $importe;
    public string $fecha;

    protected string $pdfBinary;
    protected string $subjectLine;

    public function __construct(
        string $nombre,
        ?string $mensaje,
        ?string $telefono,
        string $concepto,
        float $importe,
        string $fecha,
        string $pdfBinary,
        string $subjectLine
    ) {
        $this->nombre = $nombre;
        $this->mensaje = $mensaje;
        $this->telefono = $telefono;
        $this->concepto = $concepto;
        $this->importe = $importe;
        $this->fecha = $fecha;
        $this->pdfBinary = $pdfBinary;
        $this->subjectLine = $subjectLine;
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->view('emails.mensualidad_paid')
            ->with([
                'nombre'   => $this->nombre,
                'mensaje'  => $this->mensaje,
                'telefono' => $this->telefono,
                'concepto' => $this->concepto,
                'importe'  => $this->importe,
                'fecha'    => $this->fecha,
            ])
            ->attachData($this->pdfBinary, 'recibo-mensualidad.pdf', [
                'mime' => 'application/pdf',
            ]);
    }
}


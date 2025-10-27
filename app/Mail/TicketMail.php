<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $nombre;
    public ?string $mensaje;
    public ?string $telefono;
    public ?string $canal;
    public int $ventaId;

    protected string $pdfBinary;
    protected string $subjectLine;
    protected ?string $templateId;

    /**
     * @param string $nombre
     * @param string|null $mensaje
     * @param string|null $telefono
     * @param string|null $canal
     * @param int $ventaId
     * @param string $pdfBinary
     * @param string $subjectLine
     */
    public function __construct(
        string $nombre,
        ?string $mensaje,
        ?string $telefono,
        ?string $canal,
        int $ventaId,
        string $pdfBinary,
        string $subjectLine,
        ?string $templateId = null
    ) {
        $this->nombre = $nombre;
        $this->mensaje = $mensaje;
        $this->telefono = $telefono;
        $this->canal = $canal;
        $this->ventaId = $ventaId;
        $this->pdfBinary = $pdfBinary;
        $this->subjectLine = $subjectLine;
        $this->templateId = $templateId;
    }

    public function build(): self
    {
        $mailable = $this->subject($this->subjectLine)
            ->view('emails.ticket')
            ->with([
                'nombre' => $this->nombre,
                'mensaje' => $this->mensaje,
                'telefono' => $this->telefono,
                'canal' => $this->canal,
                'ventaId' => $this->ventaId,
            ])
            ->attachData($this->pdfBinary, 'ticket.pdf', [
                'mime' => 'application/pdf',
            ]);

        if ($this->templateId) {
            $mailable->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message) {
                $message->getHeaders()->addTextHeader('X-Mailgun-Template', (string) $this->templateId);
            });
        }

        return $mailable;
    }
}

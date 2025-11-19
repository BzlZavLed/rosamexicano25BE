<?php

namespace App\Mail;

use App\Models\Proveedor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InventoryProposalMail extends Mailable
{
    use Queueable, SerializesModels;

    public Proveedor $provider;
    public string $horizonLabel;
    public string $generatedAt;
    public array $items;

    public function __construct(Proveedor $provider, string $horizonLabel, string $generatedAt, array $items)
    {
        $this->provider = $provider;
        $this->horizonLabel = $horizonLabel;
        $this->generatedAt = $generatedAt;
        $this->items = $items;
    }

    public function build(): self
    {
        $subject = sprintf(
            'Propuesta de inventario (%s) - %s',
            $this->horizonLabel,
            $this->provider->nombre ?? 'Proveedor'
        );

        return $this->subject($subject)
            ->markdown('emails.inventory_proposal', [
                'provider' => $this->provider,
                'horizonLabel' => $this->horizonLabel,
                'generatedAt' => $this->generatedAt,
                'items' => $this->items,
            ]);
    }
}

<?php

namespace App\Mail;

use App\Models\Proveedor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RestockForecastMail extends Mailable
{
    use Queueable, SerializesModels;

    public Proveedor $provider;
    public string $horizonLabel;
    public string $forecastDate;
    public array $items;

    public function __construct(Proveedor $provider, string $horizonLabel, string $forecastDate, array $items)
    {
        $this->provider = $provider;
        $this->horizonLabel = $horizonLabel;
        $this->forecastDate = $forecastDate;
        $this->items = $items;
    }

    public function build(): self
    {
        $subject = sprintf(
            'Pronóstico de resurtido (%s) - %s',
            $this->horizonLabel,
            $this->provider->nombre ?? 'Proveedor'
        );

        return $this->subject($subject)
            ->view('emails.restock_forecast')
            ->with([
                'provider' => $this->provider,
                'horizonLabel' => $this->horizonLabel,
                'forecastDate' => $this->forecastDate,
                'items' => $this->items,
            ]);
    }
}

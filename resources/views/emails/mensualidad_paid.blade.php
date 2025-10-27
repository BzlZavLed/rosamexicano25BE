<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pago de Mensualidad</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 520px; margin: 0 auto; padding: 24px; background: #ffffff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        h1 { font-size: 22px; color: #0f172a; margin: 0 0 16px; text-align: center; }
        p { color: #475569; line-height: 1.6; font-size: 15px; }
        .highlight { font-weight: bold; color: #1d4ed8; }
        .footer { margin-top: 24px; font-size: 13px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5; padding: 32px 0;">
        <tr>
            <td align="center">
                <div class="container">
                    <h1>Pago registrado</h1>
                    <p>Hola {{ $nombre }}, confirmamos que se registró el pago de la mensualidad.</p>
                    <p class="highlight">Concepto: {{ $concepto }}</p>
                    <p class="highlight">Importe: ${{ number_format($importe, 2) }}</p>
                    <p>Fecha del pago: {{ $fecha }}</p>
                    @if (!empty($mensaje))
                        <p>{{ $mensaje }}</p>
                    @endif
                    @if (!empty($telefono))
                        <p>Teléfono de contacto: {{ $telefono }}</p>
                    @endif
                    <p>Adjunto encontrarás el recibo en PDF.</p>
                    <div class="footer">
                        Rosa Mexicano · Control de mensualidades
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gracias por tu compra</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 520px; margin: 0 auto; padding: 24px; background: #ffffff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        h1 { font-size: 24px; color: #0f172a; margin: 0 0 16px; text-align: center; }
        p { color: #475569; line-height: 1.6; font-size: 15px; }
        .thanks { font-weight: bold; color: #1d4ed8; }
        .footer { margin-top: 24px; font-size: 13px; color: #94a3b8; text-align: center; }
        .cta { display: inline-block; margin-top: 16px; padding: 10px 20px; background: #1d4ed8; color: #ffffff; text-decoration: none; border-radius: 999px; font-size: 14px; }
    </style>
</head>
<body>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5; padding: 32px 0;">
        <tr>
            <td align="center">
                <div class="container">
                    <h1>Hola {{ $nombre }}!</h1>
                    <p class="thanks">Gracias por tu compra en Rosa Mexicano.</p>
                    <p>Adjunto encontrarás tu recibo en formato PDF. Si tienes alguna duda o necesitas asistencia adicional, no dudes en contactarnos.</p>
                    @if (!empty($mensaje))
                        <p>{{ $mensaje }}</p>
                    @endif
                    <p><strong>Venta #{{ $ventaId }}</strong></p>
                    @if (!empty($canal))
                        <p>Canal: {{ ucfirst($canal) }}</p>
                    @endif
                    @if (!empty($telefono))
                        <p>Teléfono registrado: {{ $telefono }}</p>
                    @endif
                    <p>¡Esperamos verte pronto!</p>
                    <div class="footer">
                        Rosa Mexicano · Servicio al cliente<br>
                        WhatsApp/Cel: 998 123 4567
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cobro mensualidad</title>
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
                    <h1>Nuevo cobro</h1>
                    <p>Hola {{ $nombre }}, tienes un nuevo cobro generado.</p>
                    <p class="highlight">Concepto: {{ $concepto }}</p>
                    <p class="highlight">Importe: ${{ number_format($importe, 2) }}</p>
                    <p>Fecha límite: {{ $fecha }}</p>
                    @if (!empty($nota))
                        <p>{{ $nota }}</p>
                    @endif
                    <p>Gracias por tu colaboración.</p>
                    <div class="footer">
                        Rosa Mexicano · Control de mensualidades
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>

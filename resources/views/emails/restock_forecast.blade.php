<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pronóstico de resurtido</title>
</head>
<body style="font-family: Arial, sans-serif; color:#111827; line-height:1.4;">
    <h2 style="margin-bottom:0.5rem;">Hola {{ $provider->nombre ?? 'proveedor' }},</h2>
    <p style="margin-top:0;">Compartimos los productos sugeridos para resurtir en el horizonte de {{ $horizonLabel }} (pronosticado el {{ \Carbon\Carbon::parse($forecastDate)->format('d/m/Y') }}).</p>

    <table style="width:100%; border-collapse:collapse; margin-top:1rem;">
        <thead>
            <tr>
                <th style="text-align:left; border-bottom:1px solid #e5e7eb; padding:8px;">Producto</th>
                <th style="text-align:right; border-bottom:1px solid #e5e7eb; padding:8px;">Sugerido</th>
                <th style="text-align:right; border-bottom:1px solid #e5e7eb; padding:8px;">Inventario</th>
                <th style="text-align:right; border-bottom:1px solid #e5e7eb; padding:8px;">Stock recomendado</th>
                <th style="text-align:right; border-bottom:1px solid #e5e7eb; padding:8px;">Promedio diario</th>
                <th style="text-align:right; border-bottom:1px solid #e5e7eb; padding:8px;">Cobertura</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td style="border-bottom:1px solid #f3f4f6; padding:8px;">
                        <strong>{{ $item['producto_nombre'] ?? $item['producto_ident'] }}</strong><br>
                        <small>ID: {{ $item['producto_ident'] }}</small>
                    </td>
                    <td style="text-align:right; border-bottom:1px solid #f3f4f6; padding:8px;">{{ $item['suggested_order_qty'] }}</td>
                    <td style="text-align:right; border-bottom:1px solid #f3f4f6; padding:8px;">{{ $item['inventory_on_hand'] }}</td>
                    <td style="text-align:right; border-bottom:1px solid #f3f4f6; padding:8px;">{{ $item['recommended_inventory'] ?? '—' }}</td>
                    <td style="text-align:right; border-bottom:1px solid #f3f4f6; padding:8px;">{{ number_format($item['avg_daily_sales'], 2) }}</td>
                    <td style="text-align:right; border-bottom:1px solid #f3f4f6; padding:8px;">
                        {{ $item['days_of_cover'] !== null ? $item['days_of_cover'] . ' días' : 'Sin datos' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin-top:1rem;">Si tienes dudas o comentarios, contáctanos para ajustar la estrategia.</p>
    <p style="margin-top:1rem;">Gracias,<br>Equipo Rosa Mexicano</p>
</body>
</html>

<x-mail::message>
# Hola {{ $provider->nombre ?? 'proveedor' }},

Compartimos la propuesta de inventario para el horizonte de **{{ $horizonLabel }}** (generada el {{ \Carbon\Carbon::parse($generatedAt)->format('d/m/Y') }}). Estos valores consideran tus ventas históricas y el colchón de seguridad configurado.

| Producto | Stock recomendado | Inventario actual | Promedio diario | Unidades totales |
| :-- | --: | --: | --: | --: |
@foreach ($items as $item)
| {{ $item['producto_nombre'] ?? $item['producto_ident'] }} ({{ $item['producto_ident'] }}) | {{ $item['recommended_inventory'] }} | {{ $item['inventory_on_hand'] ?? '—' }} | {{ number_format($item['avg_daily_sales'], 2) }} | {{ number_format($item['total_units'], 2) }} |
@endforeach

Si necesitas ajustar entregas o resolver dudas, por favor contactanos.

Gracias,<br>
Equipo Rosa Mexicano
</x-mail::message>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Backups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f4f4f7;
            margin: 0;
            padding: 2rem;
        }

        .card {
            max-width: 960px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.1);
            padding: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 0.75rem;
        }

        th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
        }

        tbody tr {
            border-bottom: 1px solid #e2e8f0;
        }

        tbody tr:last-child {
            border-bottom: none;
        }

        .chip {
            font-family: 'JetBrains Mono', 'SFMono-Regular', monospace;
            background: #f8fafc;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }

        a.button {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            border: 1px solid #0f172a;
            text-decoration: none;
            color: #0f172a;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.15s ease;
        }

        a.button:hover {
            background: #0f172a;
            color: #fff;
        }

        .empty {
            text-align: center;
            color: #94a3b8;
            padding: 1.5rem 0;
        }
    </style>
</head>

<body>
    <div class="card">
        <form action="{{ route('superadmin.logout') }}" method="POST" style="text-align:right;">
            @csrf
            <button type="submit" class="button" style="border-color:#e11d48;color:#e11d48;">Salir</button>
        </form>
        <h1>Respaldo de base de datos</h1>
        <p style="color:#475569;">Descarga los últimos snapshots generados automáticamente. Solo se conservan los más recientes.</p>

        <table>
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Peso</th>
                    <th>Creado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($files as $file)
                    <tr>
                        <td><span class="chip">{{ $file['name'] }}</span></td>
                        <td>{{ number_format($file['size'] / 1024 / 1024, 2) }} MB</td>
                        <td>{{ \Carbon\Carbon::createFromTimestamp($file['created_at'])->format('Y-m-d H:i:s') }}</td>
                        <td>
                            <a class="button" href="{{ route('backups.download', $file['name']) }}">Descargar</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="empty">No hay respaldos generados todavía.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>

</html>

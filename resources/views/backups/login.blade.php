<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Superadmin · Acceso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #fdf2f8, #e0f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .card {
            width: 100%;
            max-width: 400px;
            background: white;
            padding: 2rem;
            border-radius: 1.25rem;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.15);
        }

        label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #0f172a;
        }

        input {
            width: 100%;
            border-radius: 0.75rem;
            border: 1px solid #cbd5f5;
            padding: 0.85rem 1rem;
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        button {
            width: 100%;
            border: none;
            border-radius: 0.75rem;
            padding: 0.9rem;
            background: #0f172a;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }

        .error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 0.75rem;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="card">
        <h1 style="margin-top:0;">Acceso superadmin</h1>
        <p style="color:#475569;">Ingresa con las credenciales configuradas en el archivo <code>.env</code>.</p>

        @if ($errors->any())
            <div class="error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('superadmin.login.submit') }}">
            @csrf
            <label for="username">Usuario</label>
            <input id="username" name="username" value="{{ old('username') }}" required autofocus>

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" required>

            <button type="submit">Entrar</button>
        </form>
    </div>
</body>

</html>

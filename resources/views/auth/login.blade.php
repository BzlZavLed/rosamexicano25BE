<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); width: 100%; max-width: 380px; }
        h1 { margin-bottom: 1.5rem; font-size: 1.5rem; text-align: center; }
        label { display: block; margin-bottom: 0.25rem; font-weight: bold; }
        input { width: 100%; padding: 0.65rem; margin-bottom: 1rem; border: 1px solid #ccc; border-radius: 4px; }
        button { width: 100%; padding: 0.75rem; background: #1c64f2; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
        button:hover { background: #1a56db; }
        .note { font-size: 0.85rem; color: #555; margin-top: 1rem; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Iniciar sesión</h1>
        <form method="POST" action="/api/auth/login">
            <label for="identifier">Correo o teléfono</label>
            <input id="identifier" name="identifier" type="text" required>

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" required>

            <button type="submit">Entrar</button>
        </form>
        <p class="note">Este formulario envía las credenciales al endpoint REST <code>/api/auth/login</code>.</p>
    </div>
</body>
</html>

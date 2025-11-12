<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Project Setup Notes

### Mailgun transport dependency

If you see `Class "Symfony\\Component\\Mailer\\Bridge\\Mailgun\\Transport\\MailgunTransportFactory" not found` while sending mail (for example when mailing `benjaminzavala74@gmail.com`), install the Mailgun mailer bridge and HTTP client:

```bash
composer require symfony/mailgun-mailer symfony/http-client
# or via NPM script:
npm run composer:mailgun
```

### Clean configuration cache

After installing the dependencies or changing mail configuration, clear and rebuild Laravel's cached config to pick up the new settings:

```bash
php artisan config:clear
php artisan cache:clear
php artisan config:cache
```

### Multi-tenant branding

Front-end themes are selected at build time through the `VITE_APP_THEME` key in `.env`. Available themes out of the box:

- `rosa-mexicano` (default)
- `verde-lima`
- `azul-pacifico`

Set the desired key in `.env` / `.env.example`, run `npm install` or `npm run build`, and the corresponding stylesheet will be bundled automatically. To add a new theme, drop a CSS file inside `resources/css/themes` (the filename becomes the key) and define the CSS variables you want to override—`resources/js/theme.ts` will pick it up automatically.

### Sharing the API across multiple domains

If you deploy the same codebase under different domains (for example, `https://rosamexicano.on-forge.com` and `https://depekesypekas.on-forge.com`) and need both SPAs to call a single backend, make sure to:

1. Add every SPA origin to `CORS_ALLOWED_ORIGINS` in `.env` so `config/cors.php` can allow the cross-origin POST requests.
2. Mirror the same hostnames inside `SANCTUM_STATEFUL_DOMAINS` to keep cookie-based auth working when requests include `withCredentials`.
3. Point each front-end’s `VITE_API_BASE_URL` to the domain hosting the API (e.g. `https://rosamexicano.on-forge.com/api`).
4. After changing these settings, clear cached config: `php artisan config:clear && php artisan config:cache`.

With those values set, the API routes defined in `routes/api.php` will accept POSTs (and any other verbs) from all configured domains over HTTPS.

### Audit trail

Every INSERT, UPDATE, or DELETE executed by the application is captured automatically in the `audit_logs` table (see `database/migrations/2025_11_04_000000_create_audit_logs_table.php`). The listener registered in `App\Providers\AppServiceProvider` writes the SQL statement, bindings, connection, action type, and the authenticated user (when available). Run `php artisan migrate` to create the table before expecting entries; the logger skips logging until the table exists to avoid breaking fresh deployments.

### Automated database snapshots

Use `php artisan db:snapshot` to generate a SQL dump under `storage/app/backups`. The command auto-detects the current database connection (MySQL or PostgreSQL), invokes `mysqldump` / `pg_dump`, and keeps only the most recent 30 files (configurable via `--keep=`). A daily scheduled run at 02:00 server time is configured in `bootstrap/app.php`; make sure the queue/cron runner executes `php artisan schedule:run` every minute so the snapshots are produced. Remember to install the relevant CLI tools (`mysqldump` or `pg_dump`) on the server and grant the app user permission to write into `storage/app/backups`.

Super admins can download the generated SQL files from `/superadmin/backups`, which is protected via HTTP Basic auth. Configure the credentials in `.env`:

```
SUPERADMIN_USER=someuser
SUPERADMIN_PASSWORD=strong-password
```

Visit `/superadmin/login` to authenticate; upon success you’ll be redirected to `/superadmin/backups` where you can download the available SQL dumps or log out. Credentials are stored only in the session (no browser pop-up prompts).

### Tipos de proveedores

Los proveedores ahora se clasifican en tres tipos:

- `normal`: pagan una cuota mensual (`importe`). Solo estos proveedores reciben correos automáticos de cobro/pago.
- `consigna`: no tienen cuota mensual; cada producto debe capturar su costo base (`precio_proveedor`) y se vende con un precio público independiente.
- `porcentaje`: la tienda retiene 20% o 30% de cada venta. El costo del proveedor se calcula aplicando ese porcentaje al precio de venta y también se descuenta cualquier 4.5% por ventas con tarjeta (prorrateado entre todos los proveedores de la venta).

Los productos almacenan ambos precios (público y costo proveedor) y las ventas registran, por renglón, el costo del proveedor (`ventadesg.proveedor_bruto`), los descuentos que se le cargan (`ventadesg.proveedor_descuento`) y el monto neto a pagar (`ventadesg.proveedor_neto`), junto con el porcentaje aplicado cuando sea necesario.

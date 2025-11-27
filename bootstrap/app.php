<?php
ini_set('upload_max_filesize', '25M');
ini_set('post_max_size', '30M');
ini_set('max_file_uploads', '50');
use App\Support\SystemSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Artisan;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withCommands([
        \App\Console\Commands\GenerateRestockForecast::class,
        \App\Console\Commands\AutoCloseCashbox::class,
        \App\Console\Commands\RebalanceCardFees::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->alias([
            'superadmin.auth' => \App\Http\Middleware\SuperAdminAuth::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('db:snapshot')->dailyAt('02:00')->onFailure(function () {
            logger()->error('Daily database snapshot failed');
        });

        $schedule->call(function () {
            $horizon = SystemSettings::get('restock_cron_horizon', 'day,week,month');
            Artisan::call('restock:forecast', [
                '--horizon' => $horizon,
            ]);
        })->dailyAt('03:00');

        $schedule->command('cash:auto-close')->dailyAt('23:59');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
                Integration::handles($exceptions);

    })->create();

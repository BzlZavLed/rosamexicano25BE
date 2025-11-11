<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('db:snapshot')->dailyAt('02:00')->onFailure(function () {
            logger()->error('Daily database snapshot failed');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
                Integration::handles($exceptions);

    })->create();

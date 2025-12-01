<?php

namespace App\Providers;

use App\Support\AuditLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laragear\WebAuthn\Auth\WebAuthnUserProvider;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
    */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('webauthn', function ($app, array $config) {
            return new WebAuthnUserProvider(
                $app['hash'],
                $config['model'],
                $app->make(AssertionValidator::class),
                false // fallback to password validation disabled
            );
        });

        DB::listen(function (QueryExecuted $query): void {
            AuditLogger::handle($query);
        });
    }
}

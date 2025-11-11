<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = env('SUPERADMIN_USER');
        $password = env('SUPERADMIN_PASSWORD');

        if (!$username || !$password) {
            abort(403, 'Super admin credentials are not configured.');
        }

        if (
            $request->getUser() !== $username ||
            $request->getPassword() !== $password
        ) {
            return response('Unauthorized', 401, [
                'WWW-Authenticate' => 'Basic realm="Backups"',
            ]);
        }

        return $next($request);
    }
}

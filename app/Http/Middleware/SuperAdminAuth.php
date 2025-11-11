<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('superadmin.authenticated') === true) {
            return $next($request);
        }

        return redirect()->route('superadmin.login');
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SuperAdminAuthController extends Controller
{
    public function showLogin()
    {
        return view('backups.login');
    }

    public function authenticate(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = env('SUPERADMIN_USER', 'qwerty');
        $pass = env('SUPERADMIN_PASSWORD', 'sqlite');

        if (!$user || !$pass) {
            return back()->withErrors(['username' => 'Super admin credentials are not configured.']);
        }

        if ($request->username === $user && hash_equals($pass, $request->password)) {
            $request->session()->put('superadmin.authenticated', true);
            return redirect()->route('backups.index');
        }

        return back()->withErrors(['username' => 'Credenciales inválidas.'])->withInput([
            'username' => $request->username,
        ]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('superadmin.authenticated');
        return redirect()->route('superadmin.login');
    }
}

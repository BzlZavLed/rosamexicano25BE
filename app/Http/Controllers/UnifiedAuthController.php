<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UnifiedAuthController extends Controller
{
    /**
     * POST /api/auth/login
     * { "identifier": "<email or phone>", "password": "..." }
     *
     * Rule:
     * - If identifier is an email => try admin (usuarios.email).
     * - Otherwise => try provider (proveedores.tel).
     * - Providers: prefer passhash; if empty, accept provider ID as temporary password and upgrade to passhash.
     */
    public function login(Request $request)
    {
        $request->validate([
            'identifier' => ['required','string','max:100'],
            'password'   => ['required','string'],
        ]);

        $id = trim($request->identifier);
        $pw = $request->password;

        if (filter_var($id, FILTER_VALIDATE_EMAIL)) {
            // Admin path
            $admin = Usuario::where('email', $id)->first();
            if ($admin && Hash::check($pw, $admin->password)) {
                $token = $admin->createToken('pos-admin', ['role:admin'])->plainTextToken;
                return response()->json([
                    'token' => $token,
                    'role'  => 'admin',
                    'user'  => ['id'=>$admin->id,'email'=>$admin->email,'nombre'=>$admin->nombre],
                ]);
            }
            return response()->json(['message'=>'Invalid credentials'], 401);
        }

        // Provider path (phone)
        $prov = Proveedor::where('tel', $id)->first();
        if (!$prov) {
            return response()->json(['message'=>'Invalid credentials'], 401);
        }

        $ok = false;
        if (!empty($prov->passhash)) {
            $ok = Hash::check($pw, $prov->passhash);
        } else {
            // back-compat: provider ID as temporary password
            $ok = ((string)$pw === (string)$prov->id);
            if ($ok) {
                // upgrade to hash for future logins
                $prov->passhash = Hash::make($pw);
                $prov->save();
            }
        }

        if (!$ok) {
            return response()->json(['message'=>'Invalid credentials'], 401);
        }

        $token = $prov->createToken('pos-provider', ['role:provider'])->plainTextToken;
        return response()->json([
            'token'    => $token,
            'role'     => 'provider',
            'provider' => ['id'=>$prov->id,'nombre'=>$prov->nombre,'tel'=>$prov->tel],
        ]);
    }

    public function me(Request $request)
    {
        $u = $request->user();
        $isProvider = $u instanceof Proveedor;
        return response()->json([
            'role' => $isProvider ? 'provider' : 'admin',
            $isProvider ? 'provider' : 'user' => [
                'id'     => $u->id,
                'nombre' => $u->nombre ?? null,
                'email'  => $u->email ?? null,
                'tel'    => $u->tel ?? null,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message'=>'Logged out']);
    }
}

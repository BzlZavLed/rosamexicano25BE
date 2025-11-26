<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Proveedor;
use App\Models\StaffRole;
use App\Support\StaffModules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UnifiedAuthController extends Controller
{
    /**
     * POST /api/auth/login
     * { "identifier": "<email or phone>", "password": "..." }
     *
     * Rule:
     * - If identifier is an email => try admin (usuarios.email).
     * - Otherwise => try provider (proveedores.tel).
     * - Providers: prefer passhash; fallback to proveedor.ident (and legacy proveedor.id) to bootstrap.
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
            $admin = Usuario::where('email', $id)->with('staffRole')->first();
            if ($admin && Hash::check($pw, $admin->password)) {
                $role = $this->staffRole($admin);
                $token = $admin->createToken('pos-admin', ['role:' . $role])->plainTextToken;
                return response()->json([
                    'token' => $token,
                    'role'  => $role,
                    'user'  => [
                        'id' => $admin->id,
                        'email' => $admin->email,
                        'nombre' => $admin->nombre,
                        'modules' => $this->staffModules($admin),
                        'staff_role' => $this->formatStaffRole($admin->staffRole),
                        'role' => $role,
                    ],
                ]);
            }
            Log::warning('Admin login failed', [
                'identifier' => $id,
                'ip' => $request->ip(),
                'reason' => $admin ? 'password_mismatch' : 'user_not_found',
            ]);
            return response()->json(['message'=>'Invalid credentials'], 401);
        }

        // Provider path (phone)
        $normalizedPhone = preg_replace('/\D+/', '', $id);
        if ($normalizedPhone === '') {
            Log::warning('Provider login failed: invalid phone', [
                'identifier' => $id,
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $prov = Proveedor::where('tel', $normalizedPhone)->first();
        if (!$prov && $normalizedPhone !== $id) {
            $prov = Proveedor::where('tel', $id)->first();
        }
        if (!$prov) {
            $prov = Proveedor::whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tel, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?",
                [$normalizedPhone]
            )->first();
        }
        if (!$prov) {
            Log::warning('Provider login failed: provider not found', [
                'identifier' => $id,
                'normalized' => $normalizedPhone,
                'ip' => $request->ip(),
            ]);
            return response()->json(['message'=>'Invalid credentials'], 401);
        }

        $expectedPassword = (string) $prov->ident;
        $ok = false;

        if (!empty($prov->passhash) && Hash::check($pw, $prov->passhash)) {
            $ok = true;
        } elseif ((string) $pw === $expectedPassword) {
            // First time login or hash drift; rotate to hashed ident
            $prov->passhash = Hash::make($expectedPassword);
            $prov->save();
            $ok = true;
        } elseif ((string) $pw === (string) $prov->id) {
            // Legacy fallback: old credential was the internal ID; rotate to ident-based password
            $prov->passhash = Hash::make($expectedPassword);
            $prov->save();
            $ok = true;
        }

        if (!$ok) {
            Log::warning('Provider login failed: password mismatch', [
                'identifier' => $id,
                'provider_id' => $prov->id,
                'ip' => $request->ip(),
            ]);
            return response()->json(['message'=>'Invalid credentials'], 401);
        }

        if ($prov->tel !== $normalizedPhone) {
            $prov->tel = $normalizedPhone;
            $prov->save();
        }

        $token = $prov->createToken('pos-provider', ['role:provider'])->plainTextToken;
        return response()->json([
            'token'    => $token,
            'role'     => 'provider',
            'provider' => [
                'id'     => $prov->id,
                'ident'  => $prov->ident,
                'nombre' => $prov->nombre,
                'tel'    => $prov->tel,
                'email'  => $prov->email,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $u = $request->user();
        $isProvider = $u instanceof Proveedor;
        if ($isProvider) {
            return response()->json([
                'role' => 'provider',
                'provider' => [
                    'id'     => $u->id,
                    'ident'  => $u->ident,
                    'nombre' => $u->nombre ?? null,
                    'email'  => $u->email ?? null,
                    'tel'    => $u->tel ?? null,
                ],
            ]);
        }

        /** @var Usuario $u */
        $u->loadMissing('staffRole');
        $role = $this->staffRole($u);
        return response()->json([
            'role' => $role,
            'user' => [
                'id'     => $u->id,
                'nombre' => $u->nombre ?? null,
                'email'  => $u->email ?? null,
                'modules'=> $this->staffModules($u),
                'staff_role' => $this->formatStaffRole($u->staffRole),
                'role'   => $role,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message'=>'Logged out']);
    }

    protected function staffRole(Usuario $user): string
    {
        return $user->role === 'cashier' ? 'cashier' : 'admin';
    }

    protected function staffModules(Usuario $user): array
    {
        $user->loadMissing('staffRole');
        if ($user->staffRole && is_array($user->staffRole->modules)) {
            return $user->staffRole->modules;
        }

        $modules = $user->modules ?? [];

        if ($user->role === 'admin') {
            return empty($modules) ? StaffModules::list() : $modules;
        }

        if ($user->role === 'cashier') {
            return empty($modules) ? ['caja'] : $modules;
        }

        return $modules;
    }

    protected function formatStaffRole(?StaffRole $role): ?array
    {
        if (!$role) {
            return null;
        }

        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'base_role' => $role->base_role,
            'modules' => $role->modules ?? [],
            'is_default' => (bool) $role->is_default,
        ];
    }
}

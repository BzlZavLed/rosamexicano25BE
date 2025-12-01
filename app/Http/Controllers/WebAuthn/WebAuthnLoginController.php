<?php

namespace App\Http\Controllers\WebAuthn;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Models\Usuario;
use App\Models\Proveedor;
use App\Models\StaffRole;
use App\Support\StaffModules;
use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidation;
use Laragear\WebAuthn\JsonTransport;
use Laragear\WebAuthn\Exceptions\AssertionException;

use function response;

class WebAuthnLoginController
{
    /**
     * Returns the challenge to assertion.
     */
    public function options(AssertionRequest $request): Responsable
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:150'],
        ]);

        $user = $this->findUser($data['identifier']);
        if (!$user) {
            Log::info('[passkey] options: user not found', ['identifier' => $data['identifier']]);
            abort(404, 'Usuario no encontrado para passkey');
        }

        Log::info('[passkey] options: issuing challenge', [
            'identifier' => $data['identifier'],
            'user_type' => get_class($user),
            'user_id' => $user->getKey(),
        ]);

        return $request->toVerify($user);
    }

    /**
     * Log the user in.
     */
    public function login(AssertedRequest $request): Response|JsonResponse
    {
        $credId = $request->input('id');
        $credential = WebAuthnCredential::find($credId);
        if (!$credential) {
            Log::warning('[passkey] credential not found', ['cred_id' => $credId]);
            return response()->noContent(422);
        }

        $user = $credential->authenticatable;
        if (!$user) {
            Log::warning('[passkey] credential has no user', ['cred_id' => $credId]);
            return response()->noContent(422);
        }

        try {
            app(AssertionValidator::class)
                ->send(new AssertionValidation(new JsonTransport($request->all()), $user))
                ->thenReturn();
        } catch (AssertionException $e) {
            Log::warning('[passkey] assertion failed', [
                'cred_id' => $credId,
                'error' => $e->getMessage(),
            ]);
            return response()->noContent(422);
        }

        Log::info('[passkey] login success', [
            'user_type' => get_class($user),
            'user_id' => $user->getAuthIdentifier(),
        ]);

        if ($user instanceof Usuario) {
            return response()->json($this->issueStaffLoginResponse($user));
        }

        if ($user instanceof Proveedor) {
            return response()->json($this->issueProviderLoginResponse($user));
        }

        Log::warning('Passkey login: unsupported user type', ['class' => get_class($user)]);
        return response()->json(['message' => 'No se pudo iniciar sesión con passkey'], 422);
    }

    protected function findUser(string $identifier): ?WebAuthnAuthenticatable
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return Usuario::where('email', $identifier)->with('staffRole')->first();
        }

        $normalizedPhone = preg_replace('/\D+/', '', $identifier);
        if ($normalizedPhone === '') {
            return null;
        }

        $prov = Proveedor::where('tel', $normalizedPhone)->first();
        if (!$prov && $normalizedPhone !== $identifier) {
            $prov = Proveedor::where('tel', $identifier)->first();
        }

        if (!$prov) {
            $prov = Proveedor::whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tel, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?",
                [$normalizedPhone]
            )->first();
        }

        return $prov;
    }

    protected function issueStaffLoginResponse(Usuario $admin, ?string $token = null): array
    {
        $admin->loadMissing('staffRole');
        $role = $this->staffRole($admin);
        $issuedToken = $token ?: $admin->createToken('pos-admin', ['role:' . $role])->plainTextToken;

        return [
            'token' => $issuedToken,
            'role'  => $role,
            'user'  => [
                'id' => $admin->id,
                'email' => $admin->email,
                'nombre' => $admin->nombre,
                'modules' => $this->staffModules($admin),
                'staff_role' => $this->formatStaffRole($admin->staffRole),
                'role' => $role,
            ],
        ];
    }

    protected function issueProviderLoginResponse(Proveedor $prov, ?string $token = null): array
    {
        $issuedToken = $token ?: $prov->createToken('pos-provider', ['role:provider'])->plainTextToken;

        return [
            'token'    => $issuedToken,
            'role'     => 'provider',
            'provider' => [
                'id'     => $prov->id,
                'ident'  => $prov->ident,
                'nombre' => $prov->nombre,
                'tel'    => $prov->tel,
                'email'  => $prov->email,
            ],
        ];
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
            'modules' => $role->modules,
            'is_default' => (bool) $role->is_default,
        ];
    }
}

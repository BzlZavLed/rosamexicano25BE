<?php

namespace App\Http\Controllers;

use App\Models\BiometricCredential;
use App\Models\Proveedor;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BiometricAuthController extends UnifiedAuthController
{
    /**
     * Create a biometric credential for the current user.
     * The returned token should be stored securely by the client (Credential Management / biometrics).
     */
    public function register(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'identifier' => ['nullable', 'string', 'max:150'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);

        $identifier = $data['identifier'] ?? $this->guessIdentifier($user);
        $credentialId = (string) Str::uuid();
        $token = Str::random(64);
        $tokenHash = $this->hashToken($token);

        BiometricCredential::create([
            'credential_id' => $credentialId,
            'identifier' => $identifier,
            'token_hash' => $tokenHash,
            'device_label' => $data['device_label'] ?? null,
            'user_agent' => substr((string) $request->userAgent(), 0, 250),
            'authenticatable_id' => $user?->getKey(),
            'authenticatable_type' => $user ? $user->getMorphClass() : null,
        ]);

        return response()->json([
            'credential_id' => $credentialId,
            'token' => $token,
            'identifier' => $identifier,
        ], 201);
    }

    /**
     * Exchange a biometric token for a full session.
     * Request payload: { identifier: string, secret: "<credentialId>:<token>" }
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:150'],
            'secret' => ['required', 'string', 'max:1024'],
        ]);

        [$credentialId, $rawToken] = explode(':', $data['secret'], 2) + [null, null];
        if (!$credentialId || !$rawToken) {
            return response()->json(['message' => 'Credencial biométrica inválida'], 422);
        }

        $credential = BiometricCredential::where('credential_id', $credentialId)
            ->where('identifier', $data['identifier'])
            ->first();

        if (!$credential || !$this->tokenMatches($credential->token_hash, $rawToken)) {
            return response()->json(['message' => 'Biometría no reconocida'], 401);
        }

        $credential->last_used_at = now();
        $credential->user_agent = substr((string) $request->userAgent(), 0, 250);
        $credential->save();

        $user = $credential->authenticatable;
        if ($user instanceof Usuario) {
            return response()->json($this->issueStaffLoginResponse($user));
        }

        if ($user instanceof Proveedor) {
            return response()->json($this->issueProviderLoginResponse($user));
        }

        return response()->json(['message' => 'No se pudo resolver el usuario biométrico'], 400);
    }

    protected function guessIdentifier($user): string
    {
        if ($user instanceof Usuario) {
            return $user->email ?? ('admin-' . $user->id);
        }

        if ($user instanceof Proveedor) {
            return $user->tel ?? (string) $user->ident;
        }

        return 'usuario-' . Str::random(6);
    }

    protected function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function tokenMatches(string $hashed, string $plain): bool
    {
        return hash_equals($hashed, $this->hashToken($plain));
    }
}

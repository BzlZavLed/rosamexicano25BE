<?php

namespace App\Http\Controllers\WebAuthn;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

use function response;

class WebAuthnRegisterController
{
    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(AttestationRequest $request): Responsable
    {
        $user = $request->user();
        logger()->info('[passkey] register options', [
            'user_type' => $user ? get_class($user) : null,
            'user_id' => $user?->getAuthIdentifier(),
        ]);

        return $request
            ->fastRegistration()
//            ->userless()
//            ->allowDuplicates()
            ->toCreate();
    }

    /**
     * Registers a device for further WebAuthn authentication.
     */
    public function register(AttestedRequest $request): Response
    {
        $request->save();

        $user = $request->user();
        logger()->info('[passkey] register saved', [
            'user_type' => $user ? get_class($user) : null,
            'user_id' => $user?->getAuthIdentifier(),
            'cred_id' => $request->input('id'),
        ]);

        return response()->noContent();
    }
}

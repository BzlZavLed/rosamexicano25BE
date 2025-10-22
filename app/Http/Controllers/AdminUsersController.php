<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;

class AdminUsersController extends Controller
{
    // GET /api/admin/users?search=&per_page=
    public function index(Request $request)
    {
        // Only admins should see this; simple gate:
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }

        $perPage = (int)$request->get('per_page', 20);
        $q = Usuario::query();

        if ($s = $request->get('search')) {
            $like = '%'.$s.'%';
            $q->where(function($qq) use ($like) {
                $qq->where('nombre', 'ILIKE', $like)
                   ->orWhere('email', 'ILIKE', $like)
                   ->orWhere('puesto', 'ILIKE', $like);
            });
        }

        return $q->orderBy('nombre')->paginate($perPage);
    }

    // POST /api/admin/users
    public function store(StoreAdminUserRequest $request)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }

        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);
        $user = Usuario::create($data);

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'nombre' => $user->nombre,
            'puesto' => $user->puesto,
        ], 201);
    }

    // GET /api/admin/users/{usuario}
    public function show(Request $request, Usuario $usuario)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }
        return $usuario;
    }

    // PATCH /api/admin/users/{usuario}
    public function update(UpdateAdminUserRequest $request, Usuario $usuario)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }

        $data = $request->validated();
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $usuario->update($data);
        return $usuario;
    }

    // DELETE /api/admin/users/{usuario}
    public function destroy(Request $request, Usuario $usuario)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }
        $usuario->delete();
        return response()->noContent();
    }
}

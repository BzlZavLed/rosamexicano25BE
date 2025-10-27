<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientesController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = Cliente::query();

        if ($s = $request->get('search')) {
            $like = '%' . Str::lower($s) . '%';
            $q->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                   ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            });
        }

        $q->orderBy('nombre');

        return ClienteResource::collection($q->paginate($perPage));
    }

    public function store(StoreClienteRequest $request)
    {
        $data = $request->validated();

        $cliente = Cliente::updateOrCreate(
            ['email' => $data['email']],
            [
                'nombre'   => $data['nombre'],
                'telefono' => $data['telefono'] ?? null,
            ]
        );

        return new ClienteResource($cliente);
    }

    public function show(Cliente $cliente)
    {
        return new ClienteResource($cliente);
    }

    public function update(UpdateClienteRequest $request, Cliente $cliente)
    {
        $cliente->update($request->validated());
        return new ClienteResource($cliente);
    }

    public function destroy(Cliente $cliente)
    {
        $cliente->delete();
        return response()->noContent();
    }
}

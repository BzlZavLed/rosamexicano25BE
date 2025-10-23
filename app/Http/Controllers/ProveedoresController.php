<?php

namespace App\Http\Controllers;

use App\Models\Proveedor;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProveedorRequest;
use App\Http\Requests\UpdateProveedorRequest;
use App\Http\Resources\ProveedorResource;
use Illuminate\Support\Str;

class ProveedoresController extends Controller
{
    // GET /api/proveedores?search=...&per_page=...
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = Proveedor::query();

        if ($s = $request->get('search')) {
            $like = '%' . Str::lower($s) . '%';
            $q->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(ciudad) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(sucursal) LIKE ?', [$like]);
            });
        }

        $q->orderBy('nombre');

        return ProveedorResource::collection($q->paginate($perPage));
    }

    // POST /api/proveedores
    public function store(StoreProveedorRequest $request)
    {
        $proveedor = Proveedor::create($request->validated());
        return new ProveedorResource($proveedor);
    }

    // GET /api/proveedores/{proveedor}
    public function show(Proveedor $proveedore) // route model binding (singular key is 'proveedore' by default)
    {
        return new ProveedorResource($proveedore);
    }

    // PUT/PATCH /api/proveedores/{proveedor}
    public function update(UpdateProveedorRequest $request, Proveedor $proveedore)
    {
        $proveedore->update($request->validated());
        return new ProveedorResource($proveedore);
    }

    // DELETE /api/proveedores/{proveedor}
    public function destroy(Proveedor $proveedore)
    {
        $proveedore->delete();
        return response()->noContent();
    }
}

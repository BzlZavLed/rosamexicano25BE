<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProductoRequest;
use App\Http\Requests\UpdateProductoRequest;
use App\Http\Resources\ProductoResource;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ProductosController extends Controller
{
    // GET /api/productos?search=&barcode=&proveedor_id=&per_page=
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = Producto::with(['proveedor', 'inventario']); // ← add 'inventario'

        if ($barcode = $request->get('barcode')) {
            $q->where('ident', (int) $barcode);
        }

        if ($prov = $request->get('proveedor_id')) {
            $q->where('proveedorid', (int) $prov);
        }

        if ($s = $request->get('search')) {
            $like = '%' . $s . '%';
            $q->where(function ($qq) use ($like) {
                $qq->where('nombre', 'ILIKE', $like)
                    ->orWhere('descripcion', 'ILIKE', $like);
            });
        }

        $q->orderBy('nombre');

        return ProductoResource::collection($q->paginate($perPage));
    }

    // GET /api/proveedores/{proveedor}/productos
    public function byProveedor(Proveedor $proveedor, Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = $proveedor->productos()->with('proveedor');

        if ($s = $request->get('search')) {
            $like = '%' . $s . '%';
            $q->where(function ($qq) use ($like) {
                $qq->where('nombre', 'ILIKE', $like)
                    ->orWhere('descripcion', 'ILIKE', $like);
            });
        }

        if ($barcode = $request->get('barcode')) {
            $q->where('ident', (int) $barcode);
        }

        return ProductoResource::collection($q->orderBy('nombre')->paginate($perPage));
    }

    // POST /api/productos
    public function store(StoreProductoRequest $request)
    {
        try {
            $data = $request->validated();

            $data['usuario'] = Auth::id(); // or: $request->user()->id

            if (!$data['usuario']) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }

            $producto = Producto::create($data);

            return new ProductoResource($producto->load('proveedor'));

        } catch (Throwable $e) {
            Log::error('Producto store failed', [
                'ex' => $e,
                'payload' => $request->all(),
                'user_id' => Auth::id(),
            ]);
            return response()->json(['message' => 'No se pudo crear el producto.'], 500);
        }
    }

    // GET /api/productos/{producto}
    public function show(Producto $producto)
    {
        return new ProductoResource($producto->load('proveedor'));
    }

    // PUT/PATCH /api/productos/{producto}
    public function update(UpdateProductoRequest $request, Producto $producto)
    {
        $producto->update($request->validated());
        return new ProductoResource($producto->load('proveedor'));
    }

    // DELETE /api/productos/{producto}
    public function destroy(Producto $producto)
    {
        $producto->delete();
        return response()->noContent();
    }
}

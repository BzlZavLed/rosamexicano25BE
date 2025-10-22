<?php

// app/Http/Controllers/PromocionesController.php
namespace App\Http\Controllers;

use App\Http\Requests\StorePromocionRequest;
use App\Http\Requests\UpdatePromocionRequest;
use App\Http\Resources\PromocionResource;
use App\Models\Promocion;
use Illuminate\Http\Request;

class PromocionesController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = Promocion::with(['productoRef', 'proveedorRef']);

        // filters
        if ($pid = $request->get('producto')) {            // producto ident
            $q->where('producto', (int) $pid);
        }
        if ($provIdent = $request->get('proveedor')) {     // proveedor ident
            $q->where('proveedor', (int) $provIdent);
        }
        if (($estado = $request->get('estado')) !== null) { // '1' or '0'
            $q->where('estado', (int) (bool) $estado);
        }
        if ($activeOnly = $request->boolean('activa', false)) {
            $today = now()->startOfDay()->toDateString();
            $q->where('estado', true)
                ->where(function ($qq) use ($today) {
                    $qq->whereNull('inicia')->orWhere('inicia', '<=', $today);
                })
                ->where(function ($qq) use ($today) {
                    $qq->whereNull('vence')->orWhere('vence', '>=', $today);
                });
        }

        // simple search by names
        if ($s = $request->get('search')) {
            $like = '%' . $s . '%';
            $q->where(function ($qq) use ($like) {
                $qq->whereHas('productoRef', fn($w) => $w->where('nombre', 'ILIKE', $like))
                    ->orWhereHas('proveedorRef', fn($w) => $w->where('nombre', 'ILIKE', $like));
            });
        }

        $q->orderByDesc('id');

        return PromocionResource::collection($q->paginate($perPage));
    }

    public function store(StorePromocionRequest $request)
    {
        $promo = Promocion::create($request->validated());
        return new PromocionResource($promo->load(['productoRef', 'proveedorRef']));
    }

    public function show(Promocion $promocion)
    {
        return new PromocionResource($promocion->load(['productoRef', 'proveedorRef']));
    }

    public function update(UpdatePromocionRequest $request, Promocion $promocion)
    {
        $promocion->update($request->validated());
        return new PromocionResource($promocion->load(['productoRef', 'proveedorRef']));
    }

    public function destroy(Promocion $promocion)
    {
        $promocion->delete();
        return response()->json(['message' => 'Promoción eliminada']);
    }
}

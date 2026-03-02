<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetStockRequest;
use App\Http\Resources\InventarioResource;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Entrada;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InventarioController extends Controller
{
    /**
     * List inventory (optionally filter by provider, barcode, search by product name).
     * GET /api/inventario?proveedor_id=&ident=&search=&per_page=
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);
        if ($perPage <= 0) {
            $perPage = 20;
        }

        $page = (int) $request->get('page', 1);
        if ($page <= 0) {
            $page = 1;
        }

        $query = $this->buildInventarioQuery($request);

        return InventarioResource::collection($query->paginate($perPage, ['*'], 'page', $page));
    }

    /**
     * Provider-scoped inventory listing.
     * GET /api/proveedores/{proveedor}/inventario
     */
    public function byProveedor(Proveedor $proveedor, Request $request)
    {
        $request->merge(['proveedor_id' => $proveedor->ident]);
        return $this->index($request);
    }

    protected function buildInventarioQuery(Request $request): Builder
    {
        $sort = Str::lower((string) $request->get('sort', 'nombre'));
        $direction = Str::lower((string) $request->get('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['nombre', 'proveedor', 'existencia', 'puni'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'nombre';
        }

        $query = DB::table('inventario')
            ->selectRaw("
                inventario.*,
                producto.id as producto_id,
                producto.nombre as producto_nombre,
                producto.descripcion as producto_descripcion,
                producto.precio as producto_precio,
                producto.proveedorid as proveedor_id,
                proveedores.nombre as proveedor_nombre
            ")
            ->join('producto', 'producto.ident', '=', 'inventario.ident')
            ->join('proveedores', 'proveedores.ident', '=', 'producto.proveedorid');

        if ($prov = $request->get('proveedor_id')) {
            $query->where('producto.proveedorid', (int) $prov);
        }

        if ($ident = $request->get('ident')) {
            $query->where('inventario.ident', (int) $ident);
        }

        if ($s = $request->get('search')) {
            $like = '%' . Str::lower($s) . '%';
            $query->whereRaw('LOWER(producto.nombre) LIKE ?', [$like]);
        }

        switch ($sort) {
            case 'proveedor':
                $query->orderBy('proveedores.nombre', $direction);
                break;
            case 'existencia':
                $query->orderBy('inventario.existencia', $direction);
                break;
            case 'puni':
                $query->orderBy('inventario.precio_individual', $direction);
                break;
            default:
                $query->orderBy('producto.nombre', $direction);
        }

        return $query;
    }

    /**
     * Set (upsert) stock for a product, deriving provider and importe.
     * POST /api/inventario/set-stock
     * body: { product_id?: number, ident?: number, existencia: number }
     */
    public function setStock(SetStockRequest $request)
    {
        try {
            return DB::transaction(function () use ($request) {
                $mode = $request->get('mode', 'add'); // 'add' | 'set'
                $ident = (int) $request->integer('ident');
                $amt = (int) $request->integer('existencia');

                $product = Producto::where('ident', $ident)->first();
                if (!$product) {
                    return response()->json(['message' => 'Producto no encontrado por ident'], 404);
                }

                $unitPrice = (float) $product->precio;

                $inv = Inventario::where('ident', $ident)->lockForUpdate()->first();
                $previousExistence = $inv ? (int) $inv->existencia : 0;

                if (!$inv) {
                    $inv = new Inventario();
                    $inv->ident = $ident;
                    $inv->existencia = 0;
                    $inv->importe = 0;
                    $inv->provee = (int) $product->proveedorid;
                    $inv->precio_individual = $unitPrice;
                } else {
                    // keep unit price snapshot updated if you want latest price reflected
                    $inv->precio_individual = $unitPrice;
                    $inv->provee = (int) $product->proveedorid;
                }

                if ($mode === 'set') {
                    $inv->existencia = $amt;
                    $inv->importe = round($amt * $unitPrice, 2);
                } else { // 'add'
                    $newExist = (int) $inv->existencia + $amt;
                    if ($newExist < 0) {
                        return response()->json(['message' => 'La existencia no puede ser negativa'], 422);
                    }
                    $inv->existencia = $newExist;
                    $inv->importe = round(((float) $inv->importe) + $amt * $unitPrice, 2);
                }

                $inv->save();
                $inv->load(['producto', 'producto.proveedor']);

                $delta = $mode === 'set'
                    ? (int) $inv->existencia - $previousExistence
                    : $amt;

                if ($delta !== 0) {
                    $this->recordEntrada($product, $delta, $mode, $request->user());
                }

                return new InventarioResource($inv);
            });
        } catch (\Throwable $e) {
            Log::error('setStock failed', ['ex' => $e, 'payload' => $request->all()]);
            return response()->json(['message' => 'No se pudo actualizar el inventario.'], 500);
        }
    }

    /**
     * Adjust stock relatively (optional helper).
     * POST /api/inventario/adjust-stock
     * body: { product_id?: number, ident?: number, delta: number }
     */
    public function adjustStock(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:producto,id'],
            'ident' => ['nullable', 'integer'],
            'delta' => ['required', 'integer'],
        ]);

        // Resolve product
        $product = null;

        if (!empty($data['product_id'])) {
            $product = Producto::findOrFail($data['product_id']);
        } elseif (!empty($data['ident'])) {
            $product = Producto::where('ident', (int) $data['ident'])->first();
            if (!$product) {
                return response()->json(['message' => 'Producto no encontrado por ident'], 404);
            }
        } else {
            return response()->json(['message' => 'Debe enviar product_id o ident'], 422);
        }

        $proveedorId = (int) $product->proveedorid;
        $ident = (int) $product->ident;

        return DB::transaction(function () use ($product, $proveedorId, $ident, $data) {
            $inv = Inventario::where('ident', $ident)->lockForUpdate()->first();

            if (!$inv) {
                $inv = new Inventario([
                    'ident' => $ident,
                    'existencia' => 0,
                    'importe' => 0,
                    'provee' => $proveedorId,
                ]);
            }

            $inv->existencia += (int) $data['delta'];
            if ($inv->existencia < 0) {
                return response()->json(['message' => 'La existencia no puede ser negativa'], 422);
            }

            $inv->importe = $inv->existencia * (float) $product->precio;
            $inv->provee = $proveedorId;
            $inv->save();

            $row = DB::table('inventario')
                ->selectRaw("
                    inventario.*,
                    producto.id as producto_id,
                    producto.nombre as producto_nombre,
                    producto.precio as producto_precio,
                    producto.proveedorid as proveedor_id,
                    proveedores.nombre as proveedor_nombre
                ")
                ->join('producto', 'producto.ident', '=', 'inventario.ident')
                ->join('proveedores', 'proveedores.ident', '=', 'producto.proveedorid')
                ->where('inventario.id', $inv->id)
                ->first();

            return (new InventarioResource((object) $row));
        });
    }

    protected function recordEntrada(Producto $product, int $delta, string $mode, ?object $user = null): void
    {
        Entrada::create([
            'prodnombre' => $product->nombre,
            'prodid' => (string) $product->ident,
            'provid' => (string) $product->proveedorid,
            'ingreal' => $delta,
            'fecha' => now()->format('Y-m-d'),
            'accion' => $this->resolveEntradaAccion($mode),
            'usuario' => $this->resolveEntradaUsuario($user),
        ]);
    }

    protected function resolveEntradaAccion(string $mode): int
    {
        return $mode === 'set' ? 2 : 1;
    }

    protected function resolveEntradaUsuario(?object $user = null): string
    {
        if (!$user) {
            $user = Auth::user();
        }

        if (!$user) {
            return 'system';
        }

        $name = $user->nombre ?? $user->name ?? $user->email ?? (string) $user->getAuthIdentifier() ?? 'system';

        return Str::limit((string) $name, 85, '');
    }
}

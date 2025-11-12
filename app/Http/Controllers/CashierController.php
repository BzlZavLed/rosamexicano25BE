<?php

namespace App\Http\Controllers;

use App\Http\Requests\CajaOpenRequest;
use App\Http\Requests\CajaCloseRequest;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\ProductoResource;
use App\Models\EstadoCaja;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaDesg;
use App\Models\Promocion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\ProviderPayout;

class CashierController extends Controller
{
    /** Quick guard: allow only admins (not providers) */
    private function ensureAdmin(Request $request)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            abort(403, 'Solo administrador');
        }
    }

    private function todayStr(): string
    {
        return Carbon::now()->format('Y-m-d');
    } // 2025-10-22

    private function normalizeFecha(?string $value): string
    {
        $value = $value ? trim($value) : '';
        if ($value === '') {
            return $this->todayStr();
        }

        $formats = ['Y-m-d', 'd/m/y', 'd/m/Y', 'Y/m/d', 'm/d/Y', 'm-d-Y'];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d');
            } catch (\Throwable $e) {
                continue;
            }
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    private function legacyFecha(string $fechaIso): ?string
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $fechaIso)->format('d/m/y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function cajaByFechaQuery(string $fechaIso)
    {
        $legacy = $this->legacyFecha($fechaIso);

        return EstadoCaja::query()->where(function ($query) use ($fechaIso, $legacy) {
            $query->where('fecha', $fechaIso);
            if ($legacy && $legacy !== $fechaIso) {
                $query->orWhere('fecha', $legacy);
            }
        });
    }

    private function applyVentaFechaFilter($query, string $fechaIso)
    {
        $legacy = $this->legacyFecha($fechaIso);
        $query->where(function ($q) use ($fechaIso, $legacy) {
            $q->where('fecha', $fechaIso);
            if ($legacy && $legacy !== $fechaIso) {
                $q->orWhere('fecha', $legacy);
            }
        });
    }

    private function cashSummary(string $fechaIso): array
    {
        $methods = ['efectivo', 'cash'];

        $ingresosQuery = DB::table('ventas')->whereIn('metodo', $methods)->where('ie', 1);
        $this->applyVentaFechaFilter($ingresosQuery, $fechaIso);
        $ingresos = (float) $ingresosQuery
            ->selectRaw('COALESCE(SUM(COALESCE(ingreso_real, totalventa)), 0) as total')
            ->value('total');

        $egresosQuery = DB::table('ventas')->whereIn('metodo', $methods)->where('ie', 0);
        $this->applyVentaFechaFilter($egresosQuery, $fechaIso);
        $egresos = (float) $egresosQuery
            ->selectRaw('COALESCE(SUM(COALESCE(ingreso_real, totalventa)), 0) as total')
            ->value('total');

        return [
            'ingresos' => round($ingresos, 2),
            'egresos' => round($egresos, 2),
            'neto' => round($ingresos - $egresos, 2),
        ];
    }

    public function status()
    {
        $fecha = $this->todayStr();
        $row = $this->cajaByFechaQuery($fecha)->orderByDesc('id')->first();
        $cashSummary = [
            'ingresos' => 0.0,
            'egresos' => 0.0,
            'neto' => 0.0,
        ];

        if ($row) {
            $cashSummary = $this->cashSummary($fecha);
        }

        return response()->json([
            'open' => $row && (int) $row->estado === 1,
            'caja' => $row,
            'cash_summary' => $cashSummary,
        ]);
    }

    public function open(CajaOpenRequest $request)
    {
        Log::info('OPEN CAJA payload', $request->all());

        // normalize to Y-m-d, accept legacy formats
        $fecha = $this->normalizeFecha($request->input('fecha'));

        // Only one open per day
        $already = $this->cajaByFechaQuery($fecha)->where('estado', 1)->exists();
        if ($already) {
            return response()->json(['message' => 'La caja ya está abierta'], 422);
        }
        $opening = $request->has('saldoinicial')
            ? (float) $request->input('saldoinicial')
            : (float) $request->input('saldo');  // fallback
        $row = EstadoCaja::create([
            'fecha' => $fecha,                            // store as ISO
            'estado' => 1,                                 // 1 = abierta
            'saldoinicial' =>$opening,   // <-- IMPORTANT: map saldo -> saldoinicial
            'saldofinal' => 0.0,                               // not known yet
            'saldosistema' => 0.0,                               // computed at close
            'usuario' => $request->user()->nombre
                ?? ($request->user()->email ?? 'admin'),
        ]);

        return response()->json($row, 201);
    }

    public function close(CajaCloseRequest $request)
    {
        $fecha = $this->normalizeFecha($request->input('fecha'));

        $row = $this->cajaByFechaQuery($fecha)->where('estado', 1)->orderByDesc('id')->first();
        if (!$row) {
            return response()->json(['message' => 'No hay caja abierta para la fecha indicada'], 409);
        }

        // Sum only CASH sales for that date from legacy `ventas` table
        $cashQuery = DB::table('ventas');
        $this->applyVentaFechaFilter($cashQuery, $fecha);
        $cashTotal = (float) $cashQuery
            ->where('metodo', 'cash')
            ->sum('totalventa');

        // System expected cash at close
        $sistema = (float) $row->saldoinicial + $cashTotal;

        // Allow optional overrides from request
        if ($request->filled('saldosistema')) {
            $sistema = (float) $request->input('saldosistema');
        }
        if ($request->filled('saldofinal')) {
            $row->saldofinal = (float) $request->input('saldofinal'); // counted cash
        }

        $row->saldosistema = $sistema;
        $row->estado = 0; // cerrada
        $row->save();

        // You can also return variance if saldofinal was provided:
        $variance = $row->saldofinal !== null ? round($row->saldofinal - $row->saldosistema, 2) : null;

        return response()->json([
            'caja' => $row,
            'cash_today' => $cashTotal,
            'variance' => $variance,
        ]);
    }

    /** GET /api/cashier/find-product?q=...  (barcode or name) */
    public function findProduct(Request $request)
    {
        $this->ensureAdmin($request);

        $q = trim((string) $request->query('q', ''));
        if ($q === '')
            return response()->json([]);

        $query = Producto::query()->with('proveedor');

        if (is_numeric($q)) {
            $query->where('ident', (int) $q); // barcode
        } else {
            $like = '%' . Str::lower($q) . '%';
            $query->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                   ->orWhereRaw('LOWER(descripcion) LIKE ?', [$like]);
            });
        }

        return ProductoResource::collection($query->limit(20)->get());
    }

    /** POST /api/cashier/checkout  (creates venta + ventadesg + updates inventory) */
    public function checkout(CheckoutRequest $request)
    {
        $this->ensureAdmin($request);

        $now = Carbon::now();
        $fecha = $now->format('Y-m-d');
        $hora = $now->format('H:i:s');

        $payload = $request->validated();

        $result = DB::transaction(function () use ($payload, $fecha, $hora) {
            $grossSubtotal = 0;
            $itemDiscountTotal = 0;
            $lines = [];
            $lineItems = [];

            foreach ($payload['lineas'] as $index => $line) {
                $ident = (int) $line['idProd'];
                $producto = \App\Models\Producto::with('proveedor')
                    ->where('ident', $ident)
                    ->firstOrFail();

                $lineBase = (float) $line['pUni'] * (int) $line['cant'];
                $rawDiscount = $line['product_desc'] ?? $line['totdesc'] ?? 0;
                $lineItemDiscount = max(0, (float) $rawDiscount);
                if ($lineItemDiscount > $lineBase) {
                    $lineItemDiscount = $lineBase;
                }
                $lineItemDiscount = round($lineItemDiscount, 2);
                $lineNetBeforeOrder = max(0, $lineBase - $lineItemDiscount);

                $grossSubtotal += $lineBase;
                $itemDiscountTotal += $lineItemDiscount;

                $lines[] = [
                    'producto' => $producto,
                    'data' => $line,
                    'line_base' => $lineBase,
                    'item_discount' => $lineItemDiscount,
                ];

                $lineItems[] = [
                    'producto' => $producto,
                    'proveedor' => $producto->relationLoaded('proveedor') ? $producto->getRelation('proveedor') : null,
                    'qty' => (int) $line['cant'],
                    'unit_price' => (float) $line['pUni'],
                    'discount_amount' => $lineItemDiscount,
                ];

                $inv = \App\Models\Inventario::where('ident', $producto->ident)->lockForUpdate()->first();
                if (!$inv) {
                    $inv = new \App\Models\Inventario([
                        'ident' => $producto->ident,
                        'existencia' => 0,
                        'importe' => 0,
                        'provee' => (int) $producto->proveedorid,
                    ]);
                }

                if ($inv->existencia < $line['cant']) {
                    throw new \RuntimeException("Stock insuficiente para producto {$producto->nombre}");
                }

                $inv->existencia -= (int) $line['cant'];
                $inv->importe = $inv->existencia * (float) $producto->precio;
                $inv->provee = (int) $producto->proveedorid;
                $inv->save();
            }

            $payout = ProviderPayout::calculate($lineItems, $payload['metodo']);
            $grossSubtotal = $payout['gross_subtotal'];
            $itemDiscountTotal = $payout['discount_total'];
            $afterDiscount = $payout['after_discount'];
            $providerChargeTotal = $payout['provider_charge_total'];
            $total = $payout['total'];
            $costoTotal = $payout['costo_total'];
            $gananciaTotal = $payout['ganancia_total'];

            foreach ($lines as $idx => &$line) {
                $line['payout'] = $payout['lines'][$idx] ?? [];
            }
            unset($line);

            $ventaId = (int) ($payload['idventa'] ?? 0);
            if ($ventaId <= 0) {
                $ventaId = (int) DB::table('ventas')->max('idventa');
                $ventaId = $ventaId > 0 ? $ventaId + 1 : 1;
            }

            $paymentMethod = strtolower($payload['metodo']);
            $cashDelta = round(max(0, ($payload['recibo'] ?? 0) - ($payload['cambio'] ?? 0)), 2);
            $ingresoReal = in_array($paymentMethod, ['efectivo', 'cash'], true) ? $cashDelta : $total;

            $venta = Venta::create([
                'idventa' => $ventaId,
                'subtotal' => round($grossSubtotal, 2),
                'tarjeta_cargo' => $providerChargeTotal,
                'costo_total' => $costoTotal,
                'ganancia_total' => $gananciaTotal,
                'ingreso_real' => $ingresoReal,
                'totalventa' => $total,
                'metodo' => $payload['metodo'],
                'recibo' => $payload['recibo'],
                'cambio' => $payload['cambio'],
                'vendedor' => $payload['vendedor'],
                'fecha' => $fecha,
                'ie' => 0,
                'concepto' => $payload['concepto'] ?? '',
            ]);

            foreach ($lines as $line) {
                $lineData = $line['data'];
                $prod = $line['producto'];

                $lineItemDiscount = round($line['item_discount'], 2);
                $linePayout = $line['payout'] ?? [];
                $lineProviderCharge = round($linePayout['provider_charge'] ?? 0.0, 2);
                $providerBruto = round($linePayout['provider_bruto'] ?? 0.0, 2);
                $providerDiscount = round($linePayout['provider_total_discount'] ?? 0.0, 2);
                $providerNet = round($linePayout['provider_net'] ?? max(0, $providerBruto - $providerDiscount), 2);
                $publicTotal = round($linePayout['public_total'] ?? $line['line_base'], 2);
                $adminMarkup = round($linePayout['admin_markup'] ?? max(0, $publicTotal - $providerBruto), 2);
                $providerPct = null;
                if ($prod->relationLoaded('proveedor')) {
                    $prov = $prod->getRelation('proveedor');
                    if ($prov && $prov->tipo === 'porcentaje') {
                        $providerPct = $prov->porcentaje_comision;
                    }
                }

                $promotionFlag = 'normal';
                if ($lineItemDiscount > 0) {
                    $promotionFlag = 'descuento - producto';
                }

                if ($bundleLabel = $this->detectBundlePromotion($prod, (int) ($lineData['cant'] ?? 0))) {
                    $promotionFlag = $bundleLabel;
                }

                VentaDesg::create([
                    'idventa' => $ventaId,
                    'fecha' => $fecha,
                    'idprod' => (int) ($lineData['idProd'] ?? $prod->ident),
                    'nombre' => $lineData['nombre'],
                    'proveedor' => $lineData['proveedor'],
                    'puni' => $lineData['pUni'],
                    'cant' => $lineData['cant'],
                    'total' => $line['line_base'],
                    'descuento_producto' => $lineItemDiscount,
                    'promotion' => $promotionFlag,
                    'hora' => $hora,
                    'cargo_tarjeta_proveedor' => $lineProviderCharge > 0 ? $lineProviderCharge : null,
                    'proveedor_porcentaje' => $providerPct,
                    'proveedor_bruto' => $providerBruto,
                    'proveedor_descuento' => $providerDiscount,
                    'proveedor_neto' => $providerNet,
                    'admin_ganancia' => $adminMarkup,
                ]);
            }

            $venta->load('lineas');

            return [
                'venta' => $venta,
                'subtotal' => $grossSubtotal,
                'item_discount_total' => $itemDiscountTotal,
                'subtotal_after_item_discounts' => $afterDiscount,
                'discount_percent' => 0,
                'discount_amount' => 0,
                'overall_discount_total' => round($itemDiscountTotal + $providerChargeTotal, 2),
                'surcharge_percent' => strtolower($payload['metodo']) === 'tarjeta' ? 4.5 : 0.0,
                'surcharge_amount' => $providerChargeTotal,
                'tarjeta_cargo' => $providerChargeTotal,
                'ingreso_real' => $ingresoReal,
                'costo_total' => $costoTotal,
                'ganancia_total' => $gananciaTotal,
                'total' => $total,
            ];
        });

        return response()->json(['data' => $result], 201);
    }

    private function detectBundlePromotion(?Producto $producto, int $quantity): ?string
    {
        if (!$producto || $quantity <= 0) {
            return null;
        }

        $productIdent = (int) ($producto->ident ?? 0);
        $providerIdent = (int) ($producto->proveedorid ?? 0);
        if ($productIdent === 0 && $providerIdent === 0) {
            return null;
        }

        static $cache = [];
        $cacheKey = $productIdent . ':' . $providerIdent . ':' . $quantity;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $query = Promocion::query()
            ->whereIn('tipo', ['bundle', 'gratis'])
            ->where('estado', true);

        if ($productIdent) {
            $query->where(function ($q) use ($productIdent, $providerIdent) {
                $q->where('producto', $productIdent);
                if ($providerIdent) {
                    $q->orWhere(function ($inner) use ($providerIdent) {
                        $inner->whereNull('producto')->where('proveedor', $providerIdent);
                    });
                }
            });
        } elseif ($providerIdent) {
            $query->whereNull('producto')->where('proveedor', $providerIdent);
        } else {
            return $cache[$cacheKey] = null;
        }

        $promo = $query->orderByDesc('mincompra')
            ->get()
            ->filter(function (Promocion $promo) use ($quantity) {
                if (!$promo->activa) {
                    return false;
                }
                if ($promo->mincompra && $quantity < (int) $promo->mincompra) {
                    return false;
                }
                return ($promo->gratis ?? 0) > 0;
            })
            ->first();

        if (!$promo) {
            return $cache[$cacheKey] = null;
        }

        return $cache[$cacheKey] = sprintf('%d gratis', (int) $promo->gratis);
    }
}

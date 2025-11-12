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
        $ingresos = (float) $ingresosQuery->sum('totalventa');

        $egresosQuery = DB::table('ventas')->whereIn('metodo', $methods)->where('ie', 0);
        $this->applyVentaFechaFilter($egresosQuery, $fechaIso);
        $egresos = (float) $egresosQuery->sum('totalventa');

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

        return DB::transaction(function () use ($payload, $fecha, $hora) {
            $grossSubtotal = 0;
            $itemDiscountTotal = 0;
            $lines = [];
            $providerLines = [];

            foreach ($payload['lineas'] as $index => $line) {
                $producto = \App\Models\Producto::findOrFail($line['idProd']);

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

                $providerId = (int) ($producto->proveedorid ?? 0);

                $lines[] = [
                    'producto' => $producto,
                    'data' => $line,
                    'line_base' => $lineBase,
                    'item_discount' => $lineItemDiscount,
                    'net_before_order' => $lineNetBeforeOrder,
                    'provider_id' => $providerId,
                    'provider_charge' => 0.0,
                ];

                $providerLines[$providerId][] = $index;

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

            $afterDiscount = max(0, $grossSubtotal - $itemDiscountTotal);

            $providerNetTotals = [];
            foreach ($lines as &$line) {
                $lineNetAfterOrder = max(0, $line['net_before_order']);
                $line['net_after_order'] = $lineNetAfterOrder;
                $providerId = $line['provider_id'];
                $providerNetTotals[$providerId] = ($providerNetTotals[$providerId] ?? 0) + $lineNetAfterOrder;
            }
            unset($line);

            $providerChargeTotal = 0.0;
            if (strtolower($payload['metodo']) === 'tarjeta') {
                $totalNetAfterOrder = array_sum($providerNetTotals);
                if ($totalNetAfterOrder > 0) {
                    $providerChargeTotal = round($totalNetAfterOrder * 0.045, 2);

                    $providerIds = array_keys($providerNetTotals);
                    $providerCharges = [];
                    $remainingChargeTotal = $providerChargeTotal;
                    $providerCount = count($providerIds);

                    foreach ($providerIds as $pIndex => $providerId) {
                        $base = $providerNetTotals[$providerId];
                        if ($base <= 0) {
                            $providerCharges[$providerId] = 0.0;
                            continue;
                        }

                        if ($pIndex === $providerCount - 1) {
                            $providerCharges[$providerId] = round($remainingChargeTotal, 2);
                        } else {
                            $share = $base / $totalNetAfterOrder;
                            $charge = round($providerChargeTotal * $share, 2);
                            $providerCharges[$providerId] = $charge;
                            $remainingChargeTotal -= $charge;
                        }
                    }

                    foreach ($providerCharges as $providerId => $charge) {
                        $indexes = $providerLines[$providerId] ?? [];
                        if (empty($indexes) || $charge <= 0) {
                            continue;
                        }

                        $providerBase = $providerNetTotals[$providerId];
                        $remainingCharge = $charge;
                        $lineCountForProvider = count($indexes);

                        foreach ($indexes as $pos => $lineIdx) {
                            $lineNetAfterOrder = $lines[$lineIdx]['net_after_order'];
                            if ($providerBase <= 0 || $remainingCharge <= 0) {
                                $lineCharge = 0.0;
                            } elseif ($pos === $lineCountForProvider - 1) {
                                $lineCharge = round($remainingCharge, 2);
                            } else {
                                $weight = $lineNetAfterOrder / $providerBase;
                                $lineCharge = round($charge * $weight, 2);
                                $remainingCharge -= $lineCharge;
                            }

                            $lines[$lineIdx]['provider_charge'] = round(($lines[$lineIdx]['provider_charge'] ?? 0) + $lineCharge, 2);
                        }
                    }

                    $providerChargeTotal = round(array_reduce($lines, function ($carry, $lineData) {
                        return $carry + ($lineData['provider_charge'] ?? 0);
                    }, 0.0), 2);
                }
            }

            $total = round(max(0, $afterDiscount - $providerChargeTotal), 2);

            $venta = Venta::create([
                'idventa' => $payload['idventa'],
                'subtotal' => round($grossSubtotal, 2),
                'tarjeta_cargo' => $providerChargeTotal,
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
                $lineProviderCharge = round($line['provider_charge'] ?? 0.0, 2);
                $totalProductDiscount = round($lineItemDiscount + $lineProviderCharge, 2);

                $promotionFlag = 'normal';
                if ($totalProductDiscount > 0) {
                    $promotionFlag = 'descuento - producto';
                }

                if ($bundleLabel = $this->detectBundlePromotion($prod, (int) ($lineData['cant'] ?? 0))) {
                    $promotionFlag = $bundleLabel;
                }

                VentaDesg::create([
                    'idventa' => $payload['idventa'],
                    'fecha' => $fecha,
                    'idProd' => $prod->id,
                    'nombre' => $lineData['nombre'],
                    'proveedor' => $lineData['proveedor'],
                    'pUni' => $lineData['pUni'],
                    'cant' => $lineData['cant'],
                    'total' => $line['line_base'],
                    'descuento_producto' => $totalProductDiscount,
                    'promotion' => $promotionFlag,
                    'hora' => $hora,
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
                'overall_discount_total' => $itemDiscountTotal + $providerChargeTotal,
                'surcharge_percent' => strtolower($payload['metodo']) === 'tarjeta' ? 4.5 : 0.0,
                'surcharge_amount' => $providerChargeTotal,
                'tarjeta_cargo' => $providerChargeTotal,
                'total' => $total,
            ];
        });
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

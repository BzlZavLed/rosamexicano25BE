<?php

namespace App\Http\Controllers;

use App\Http\Requests\CajaOpenRequest;
use App\Http\Requests\CajaCloseRequest;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\ExpenseRequest;
use App\Http\Resources\ProductoResource;
use App\Models\EstadoCaja;
use App\Models\DailyCashSummary;
use App\Models\Egreso;
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
use Illuminate\Validation\ValidationException;

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

    private function cajaByFechaQuery(string $fechaIso)
    {
        return EstadoCaja::query()->where('fecha', $fechaIso);
    }

    private function applyVentaFechaFilter($query, string $fechaIso)
    {
        $query->where('fecha', $fechaIso);
    }

    private function getOrCreateDailySummary(string $fecha): DailyCashSummary
    {
        if ($summary = $this->findDailySummary($fecha)) {
            return $summary;
        }

        return DailyCashSummary::create([
            'fecha' => $fecha,
            'saldo_inicial' => 0,
            'efectivo' => 0,
            'transferencia' => 0,
            'tarjeta' => 0,
            'egresos' => 0,
            'saldo_cierre' => 0,
        ]);
    }

    private function findDailySummary(string $fecha): ?DailyCashSummary
    {
        return DailyCashSummary::whereDate('fecha', $fecha)->first();
    }

    private function cashSummary(string $fechaIso): array
    {
        $summary = $this->findDailySummary($fechaIso);
        $ingresos = $summary?->efectivo ?? 0.0;
        $egresos = $summary?->egresos ?? 0.0;

        return [
            'ingresos' => round($ingresos, 2),
            'egresos' => round($egresos, 2),
            'neto' => round($ingresos - $egresos, 2),
        ];
    }

    private function lastSaldoCierre(?string $beforeDate = null): ?float
    {
        $query = DailyCashSummary::query()
            ->whereNotNull('saldo_cierre')
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($beforeDate) {
            $query->where('fecha', '<', $beforeDate);
        }

        $row = $query->first();
        return $row ? (float) ($row->saldo_cierre ?? 0) : null;
    }

    private function applyPaymentToSummary(string $fecha, string $method, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $column = match (strtolower($method)) {
            'tarjeta' => 'tarjeta',
            'transferencia' => 'transferencia',
            default => 'efectivo',
        };

        $summary = $this->getOrCreateDailySummary($fecha);
        $summary->$column = round(($summary->$column ?? 0) + $amount, 2);
        $summary->save();
    }

    private function applyExpenseToSummary(string $fecha, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $summary = $this->getOrCreateDailySummary($fecha);
        $summary->egresos = round(($summary->egresos ?? 0) + $amount, 2);
        $summary->save();
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
        $previousClose = $this->lastSaldoCierre($fecha);
        $opening = $request->has('saldoinicial')
            ? (float) $request->input('saldoinicial')
            : ($request->has('saldo')
                ? (float) $request->input('saldo')
                : ($previousClose ?? 0.0));
        $row = EstadoCaja::create([
            'fecha' => $fecha,                            // store as ISO
            'estado' => 1,                                 // 1 = abierta
            'saldoinicial' =>$opening,   // <-- IMPORTANT: map saldo -> saldoinicial
            'saldofinal' => 0.0,                               // not known yet
            'saldosistema' => 0.0,                               // computed at close
            'saldo_cierre' => 0.0,
            'usuario' => $request->user()->nombre
                ?? ($request->user()->email ?? 'admin'),
        ]);

        $summary = $this->getOrCreateDailySummary($fecha);
        $summary->saldo_inicial = round($opening, 2);
        $summary->save();

        return response()->json($row, 201);
    }

    public function close(CajaCloseRequest $request)
    {
        $fecha = $this->normalizeFecha($request->input('fecha'));

        $row = $this->cajaByFechaQuery($fecha)->where('estado', 1)->orderByDesc('id')->first();
        if (!$row) {
            return response()->json(['message' => 'No hay caja abierta para la fecha indicada'], 409);
        }

        $summary = $this->getOrCreateDailySummary($fecha);
        $cashSummary = $this->cashSummary($fecha);
        $cashTotal = $cashSummary['ingresos'];
        $cashExpenses = $cashSummary['egresos'];

        $saldoCierre = round(
            ($summary->saldo_inicial ?? 0)
            + ($summary->efectivo ?? 0)
            - ($summary->egresos ?? 0),
            2
        );
        $summary->saldo_cierre = $saldoCierre;
        $summary->save();

        // System expected cash at close (based on summary totals)
        $sistema = $saldoCierre;

        // Allow optional overrides from request
        if ($request->filled('saldosistema')) {
            $sistema = (float) $request->input('saldosistema');
        }
        if ($request->filled('saldofinal')) {
            $row->saldofinal = (float) $request->input('saldofinal'); // counted cash
        }

        $row->saldosistema = $sistema;
        $row->saldo_cierre = $summary->saldo_cierre;
        $row->estado = 0; // cerrada
        $row->save();

        // You can also return variance if saldofinal was provided:
        $variance = $row->saldofinal !== null ? round($row->saldofinal - $row->saldosistema, 2) : null;

        return response()->json([
            'caja' => $row,
            'cash_today' => $cashTotal,
            'cash_expenses' => $cashExpenses,
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

        $result = DB::transaction(function () use ($payload, $fecha, $hora, $now) {
            $grossSubtotal = 0.0;
            $itemDiscountTotal = 0.0;
            $lines = [];
            $lineItems = [];

            foreach ($payload['lineas'] as $index => $line) {
                $ident = (int) $line['idProd'];
                $producto = Producto::with('proveedor')
                    ->where('ident', $ident)
                    ->firstOrFail();

                $quantity = max(0, (int) $line['cant']);
                $unitPrice = round((float) $line['pUni'], 2);
                $totalDiscount = round((float) ($line['product_desc'] ?? $line['totdesc'] ?? 0), 2);
                $manualDiscount = round((float) ($line['manual_discount'] ?? 0), 2);
                if ($manualDiscount > $totalDiscount) {
                    $manualDiscount = $totalDiscount;
                }

                $promotionDiscountTotal = round(max(0, $totalDiscount - $manualDiscount), 2);
                $promotionRules = $this->resolvePromotionRules($producto);
                [$paidQty, $freeQty, $promotionPercentAmount] = $this->breakdownPromotionDiscount(
                    $quantity,
                    $unitPrice,
                    $promotionRules['percent'] ?? 0.0,
                    $promotionDiscountTotal
                );

                if ($promotionDiscountTotal > 0 && $manualDiscount > 0) {
                    throw ValidationException::withMessages([
                        "lineas.$index.manual_discount" => 'Los productos con promoción no aceptan descuento manual',
                    ]);
                }

                $publicBase = round($unitPrice * $paidQty, 2);
                $lineNetBeforeManual = max(0, $publicBase - $promotionPercentAmount);
                if ($manualDiscount > $lineNetBeforeManual) {
                    $manualDiscount = $lineNetBeforeManual;
                }

                $grossSubtotal += $publicBase;
                $itemDiscountTotal += ($promotionPercentAmount + $manualDiscount);

                $provider = $producto->relationLoaded('proveedor')
                    ? $producto->getRelation('proveedor')
                    : $producto->proveedor()->first();
                $providerUnitCost = (float) ($producto->precio_proveedor ?? $unitPrice);
                $lines[] = [
                    'producto' => $producto,
                    'proveedor' => $provider,
                    'data' => $line,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'paid_quantity' => $paidQty,
                    'free_qty' => $freeQty,
                    'promotion_percent' => $promotionRules['percent'] ?? 0.0,
                    'promotion_discount_total' => $promotionDiscountTotal,
                    'promotion_percent_amount' => $promotionPercentAmount,
                    'manual_discount' => $manualDiscount,
                    'public_total' => $publicBase,
                    'provider_cost' => round($providerUnitCost * $paidQty, 2),
                ];

                $lineItems[] = [
                    'producto' => $producto,
                    'proveedor' => $provider,
                    'qty' => $quantity,
                    'paid_quantity' => $paidQty,
                    'unit_price' => $unitPrice,
                    'promotion_discount' => $promotionPercentAmount,
                    'manual_discount' => $manualDiscount,
                    'provider_unit_cost' => $providerUnitCost,
                    'provider_type' => $provider?->tipo,
                    'provider_pct' => $provider?->porcentaje_comision,
                ];

                $inv = Inventario::where('ident', $producto->ident)->lockForUpdate()->first();
                if (!$inv) {
                    $inv = new Inventario([
                        'ident' => $producto->ident,
                        'existencia' => 0,
                        'importe' => 0,
                        'provee' => (int) $producto->proveedorid,
                    ]);
                }

                if ($inv->existencia < $quantity) {
                    throw new \RuntimeException("Stock insuficiente para producto {$producto->nombre}");
                }

                $inv->existencia -= $quantity;
                $inv->importe = $inv->existencia * (float) $producto->precio;
                $inv->provee = (int) $producto->proveedorid;
                $inv->save();
            }

            $payout = ProviderPayout::calculate($lineItems, $payload['metodo']);
            $afterDiscount = $payout['after_discount'];
            $providerChargeTotal = $payout['provider_charge_total'];
            $total = $payout['total'];
            $paymentMethod = strtolower($payload['metodo']);
            $cashDelta = round(max(0, ($payload['recibo'] ?? 0) - ($payload['cambio'] ?? 0)), 2);
            $ingresoReal = $paymentMethod === 'efectivo' ? $cashDelta : $total;

            foreach ($lines as $idx => &$line) {
                $line['payout'] = $payout['lines'][$idx] ?? [];
            }
            unset($line);

            $ventaId = (int) ($payload['idventa'] ?? 0);
            if ($ventaId <= 0) {
                $ventaId = (int) DB::table('ventas')->max('idventa');
                $ventaId = $ventaId > 0 ? $ventaId + 1 : 1;
            }

            $venta = Venta::create([
                'idventa' => $ventaId,
                'totalventa' => $total,
                'metodo' => $payload['metodo'],
                'total_recibido' => $payload['recibo'],
                'cambio' => $payload['cambio'],
                'vendedor' => $payload['vendedor'],
                'fecha' => $fecha,
                'hora' => $hora,
                'receipt_printed' => false,
                'receipt_emailed' => false,
            ]);

            $this->applyPaymentToSummary(
                $fecha,
                $paymentMethod,
                $paymentMethod === 'efectivo' ? $ingresoReal : $total
            );

            foreach ($lines as $line) {
                $lineData = $line['data'];
                $prod = $line['producto'];
                $linePayout = $line['payout'] ?? [];

                VentaDesg::create([
                    'idventa' => $ventaId,
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'producto_id' => (int) ($lineData['idProd'] ?? $prod->ident),
                    'nombre' => $lineData['nombre'],
                    'proveedor_id' => $prod->proveedorid,
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'free_quantity' => $line['free_qty'],
                    'public_total' => $line['public_total'],
                    'venta_total' => $total,
                    'promotion_discount_percentage' => ($line['promotion_percent'] ?? 0) > 0 ? $line['promotion_percent'] : null,
                    'promotion_discount_amount' => $line['promotion_discount_total'],
                    'manual_discount_amount' => $line['manual_discount'],
                    'free_product' => $line['free_qty'] > 0,
                    'credit_card_discount' => $linePayout['credit_card_discount'] ?? 0.0,
                    'provider_percentage_discount' => $linePayout['provider_percentage_discount'] ?? 0.0,
                    'consigna_discount' => $linePayout['consigna_discount'] ?? 0.0,
                    'provider_cost' => $line['provider_cost'],
                    'provider_payment' => $linePayout['provider_net'] ?? 0.0,
                    'admin_earnings' => $linePayout['admin_earnings'] ?? 0.0,
                ]);
            }

            $venta->load('lineas');

            return [
                'venta' => $venta,
                'subtotal' => round($grossSubtotal, 2),
                'item_discount_total' => round($itemDiscountTotal, 2),
                'subtotal_after_item_discounts' => $afterDiscount,
                'discount_percent' => 0,
                'discount_amount' => 0,
                'overall_discount_total' => round($itemDiscountTotal + $providerChargeTotal, 2),
                'surcharge_percent' => strtolower($payload['metodo']) === 'tarjeta' ? 4.5 : 0.0,
                'surcharge_amount' => $providerChargeTotal,
                'tarjeta_cargo' => $providerChargeTotal,
                'ingreso_real' => strtolower($payload['metodo']) === 'efectivo'
                    ? round(max(0, $payload['recibo'] - $payload['cambio']), 2)
                    : $total,
                'costo_total' => $payout['costo_total'],
                'ganancia_total' => $payout['ganancia_total'],
                'total' => $total,
            ];
        });

        return response()->json(['data' => $result], 201);
    }

    public function registerExpense(ExpenseRequest $request)
    {
        $this->ensureAdmin($request);

        $fecha = $this->normalizeFecha($request->input('fecha'));
        $caja = $this->cajaByFechaQuery($fecha)->where('estado', 1)->first();
        if (!$caja) {
            return response()->json(['message' => 'Debes abrir caja antes de registrar gastos'], 409);
        }

        $descripcion = (string) ($request->input('descripcion') ?? $request->input('concepto') ?? '');
        $descripcion = trim($descripcion);
        $monto = $request->input('monto');
        if ($monto === null) {
            $monto = $request->input('totalventa');
        }
        $monto = round((float) $monto, 2);

        if ($monto <= 0) {
            return response()->json(['message' => 'El monto del egreso debe ser mayor a cero'], 422);
        }

        if ($descripcion === '') {
            return response()->json(['message' => 'La descripción del egreso es obligatoria'], 422);
        }

        $creadoPor = $request->user()->nombre
            ?? $request->user()->email
            ?? ($request->input('vendedor') ?: 'admin');

        $egreso = Egreso::create([
            'fecha' => $fecha,
            'descripcion' => $descripcion,
            'monto' => $monto,
            'creado_por' => $creadoPor,
        ]);

        $this->applyExpenseToSummary($fecha, $monto);

        return response()->json(['data' => $egreso], 201);
    }

    private function resolvePromotionRules(Producto $producto): array
    {
        $productIdent = (int) ($producto->ident ?? 0);
        $providerIdent = (int) ($producto->proveedorid ?? 0);
        $cacheKey = $productIdent . ':' . $providerIdent;

        static $cache = [];
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $productPromos = $productIdent > 0
            ? Promocion::query()
                ->where('estado', true)
                ->where('producto', $productIdent)
                ->get()
                ->filter(fn (Promocion $promo) => $promo->activa)
            : collect();

        $providerPromos = $providerIdent > 0
            ? Promocion::query()
                ->where('estado', true)
                ->whereNull('producto')
                ->where('proveedor', $providerIdent)
                ->get()
                ->filter(fn (Promocion $promo) => $promo->activa)
            : collect();

        $candidates = $productPromos->count() ? $productPromos : $providerPromos;
        $percentPromo = $candidates->firstWhere('tipo', 'descuento');
        $bundlePromo = $candidates->first(function (Promocion $promo) {
            return in_array($promo->tipo, ['bundle', 'gratis']);
        });

        return $cache[$cacheKey] = [
            'percent' => $percentPromo ? (float) ($percentPromo->descuento ?? 0) : 0.0,
            'bundle_min' => $bundlePromo ? (int) ($bundlePromo->mincompra ?? 0) : 0,
            'bundle_bonus' => $bundlePromo ? (int) ($bundlePromo->gratis ?? 0) : 0,
        ];
    }

    private function breakdownPromotionDiscount(int $quantity, float $unitPrice, float $percent, float $promotionDiscount): array
    {
        $percent = max(0.0, min(100.0, $percent));
        $percentFraction = $percent / 100;

        if ($promotionDiscount <= 0 || $unitPrice <= 0) {
            return [$quantity, 0, 0.0];
        }

        $unitsFromDiscount = $promotionDiscount / max(0.01, $unitPrice);
        $denominator = max(0.00001, 1 - $percentFraction);
        $paidQty = ($quantity - $unitsFromDiscount) / $denominator;
        $paidQty = (int) round(max(0, min($quantity, $paidQty)));
        $freeQty = max(0, $quantity - $paidQty);
        $percentAmount = round($paidQty * $unitPrice * $percentFraction, 2);
        if ($percentAmount > $promotionDiscount) {
            $percentAmount = round($promotionDiscount, 2);
        }

        return [$paidQty, $freeQty, $percentAmount];
    }
}

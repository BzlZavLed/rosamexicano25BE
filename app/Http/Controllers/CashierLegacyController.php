<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashierFindRequest;
use App\Http\Requests\CheckoutLegacyRequest;
use App\Http\Requests\CajaLegacyRequest;
use App\Http\Requests\ExpenseLegacyRequest;
use App\Http\Requests\SendTicketEmailRequest;
use App\Models\EstadoCaja;
use App\Models\VentaOld;
use App\Models\Venta;
use App\Models\VentaDesg;
use App\Models\Promocion;
use App\Models\Producto;
use App\Models\Inventario;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Mail\TicketMail;
use App\Models\Mailer;
use Throwable;
use Carbon\Carbon;

class CashierLegacyController extends Controller
{
    /** Return today in ISO format */
    private function todayStr(): string
    {
        return Carbon::now()->format('Y-m-d');
    }

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

    /* ===================== CAJA ===================== */

    // GET /api/caja/status
    public function status(Request $request)
    {
        $fecha = $this->todayStr();
        $row = $this->cajaByFechaQuery($fecha)->orderByDesc('id')->first();

        return response()->json([
            'open' => $row && (int) $row->estado === 1,
            'caja' => $row,
        ]);
    }

    // POST /api/caja/open  { saldo: number, fecha?: "d/m/y" }
    public function open(CajaLegacyRequest $request)
    {
        $fecha = $this->normalizeFecha($request->input('fecha'));

        $existsOpen = $this->cajaByFechaQuery($fecha)->where('estado', 1)->exists();
        if ($existsOpen) {
            return response()->json(['message' => 'Ya existe caja abierta para esa fecha'], 409);
        }

        $row = EstadoCaja::create([
            'fecha' => $fecha,              // store ISO forward, still compatible searching legacy
            'estado' => 1,                   // 1 = abierta
            'saldoinicial' => (float) $request->input('saldo', 0),   // saldo inicial (efectivo)
            'saldofinal' => 0,   // saldo final (efectivo) --- IGNORE ---
            'saldosistema' => 0,                   // se calculará al cerrar
            'usuario' => $request->user()->nombre ?? $request->user()->email ?? 'admin',
        ]);

        return response()->json($row, 201);
    }

    // POST /api/caja/close  { saldosistema?: number, fecha?: "d/m/y" }
    public function close(CajaLegacyRequest $request)
    {
        $fecha = $this->normalizeFecha($request->input('fecha'));

        // caja abierta de ese día
        $row = $this->cajaByFechaQuery($fecha)->where('estado', 1)->orderByDesc('id')->first();
        if (!$row) {
            return response()->json(['message' => 'No hay caja abierta para la fecha indicada'], 409);
        }

        // total en efectivo del día (según ventas.totalventa y ventas.metodo='efectivo')
        $cashQuery = DB::table('ventas');
        $this->applyVentaFechaFilter($cashQuery, $fecha);
        $cashTotal = (float) $cashQuery
            ->whereIn('metodo', ['efectivo', 'cash']) // compat: aceptar registros legacy 'cash'
            ->sum('totalventa');

        $row->saldosistema = $row->saldo + $cashTotal; // saldo inicial + ventas en efectivo
        // si el cliente manda un valor manual en el cierre, lo respetamos
        if ($request->filled('saldosistema')) {
            $row->saldosistema = (float) $request->input('saldosistema');
        }
        $row->estado = 0; // cerrada
        $row->save();

        return response()->json([
            'caja' => $row,
            'cash_today' => $cashTotal,
        ]);
    }

    /* ================== PRODUCT FIND ================= */

    // GET /api/cashier/find-product?barcode=123456 or ?search=papel
    public function findProduct(CashierFindRequest $request)
    {
        $q = Producto::with(['proveedor', 'inventario']);

        if ($barcode = $request->input('barcode')) {
            $q->where('ident', (int) $barcode);
        }
        if ($s = $request->input('search')) {
            $like = '%' . Str::lower($s) . '%';
            $q->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(descripcion) LIKE ?', [$like]);
            });
        }
        if ($pid = $request->input('proveedor_id')) {
            $q->where('proveedorid', (int) $pid);
        }

        $per = (int) $request->input('per_page', 25);
        $data = $q->orderBy('nombre')->limit($per)->get();

        return response()->json(['data' => $data]);
    }

    /* ===================== CHECKOUT ================== */

    // POST /api/cashier/checkout
    public function checkout(CheckoutLegacyRequest $request)
    {
        // caja debe estar abierta para hoy (d/m/y)
        $fechaHoy = $this->todayStr();
        $caja = $this->cajaByFechaQuery($fechaHoy)->where('estado', 1)->first();
        if (!$caja) {
            return response()->json(['message' => 'Debes abrir caja antes de vender'], 409);
        }

        $items = $request->input('items', []);
        $method = $request->input('payment.method');    // 'efectivo' | 'tarjeta' | 'transferencia'
        $received = $request->input('payment.received');  // efectivo entregado (solo efectivo)

        try {
            $payload = DB::transaction(function () use ($items, $method, $received, $fechaHoy, $request) {

                $lines = [];
                $grossSubtotal = 0.0;
                $itemDiscountTotal = 0.0;
                $providerLines = [];

                // 1) validar stock + preparar renglones
                $providerCache = [];

                foreach ($items as $index => $it) {
                    $ident = (int) $it['ident'];
                    $qty = (int) $it['qty'];

                    $producto = Producto::where('ident', $ident)->first();
                    if (!$producto) {
                        throw new \RuntimeException("Producto {$ident} no existe");
                    }

                    $inv = Inventario::where('ident', $ident)->lockForUpdate()->first();
                    $exist = (int) ($inv?->existencia ?? 0);
                    if ($qty > $exist) {
                        throw new \RuntimeException("Inventario insuficiente para {$ident}");
                    }

                    $unit = (float) $producto->precio;
                    $gross = $unit * $qty;
                    $grossSubtotal += $gross;

                    // descuento por producto (monto o porcentaje)
                    $itemDiscount = 0.0;
                    if (array_key_exists('product_desc', $it) && $it['product_desc'] !== null) {
                        $itemDiscount = max(0, (float) $it['product_desc']);
                    } elseif (array_key_exists('discount_amount', $it) && $it['discount_amount'] !== null) {
                        $itemDiscount = max(0, (float) $it['discount_amount']);
                    } elseif (array_key_exists('discount_percent', $it) && $it['discount_percent'] !== null) {
                        $percent = max(0, min(100, (float) $it['discount_percent']));
                        $itemDiscount = round($gross * ($percent / 100), 2);
                    }
                    if ($itemDiscount > $gross) {
                        $itemDiscount = $gross;
                    }
                    $itemDiscount = round($itemDiscount, 2);

                    $itemDiscountTotal += $itemDiscount;
                    $netBeforeOrder = max(0, $gross - $itemDiscount);

                    $providerId = (int) ($producto->proveedorid ?? 0);
                    if ($providerId && !array_key_exists($providerId, $providerCache)) {
                        $providerCache[$providerId] = Proveedor::where('ident', $providerId)->first();
                    }
                    $provider = $providerCache[$providerId] ?? null;
                    $providerType = $provider->tipo ?? 'normal';
                    $providerPct = $providerType === 'porcentaje' ? (int) ($provider->porcentaje_comision ?? 0) : null;
                    $providerUnitCost = $producto->precio_proveedor ?? null;
                    if ($providerUnitCost === null) {
                        if ($providerType === 'porcentaje' && $providerPct) {
                            $providerUnitCost = round($unit * (1 - ($providerPct / 100)), 2);
                        } else {
                            $providerUnitCost = $unit;
                        }
                    }

                    $lines[] = [
                        'producto' => $producto,
                        'inv' => $inv,
                        'qty' => $qty,
                        'unit' => $unit,
                        'gross' => $gross,
                        'item_discount' => $itemDiscount,
                        'net_before_order' => $netBeforeOrder,
                        'provider_id' => $providerId,
                        'provider_type' => $providerType,
                        'provider_pct' => $providerPct,
                        'provider_unit_cost' => $providerUnitCost,
                        'provider_charge' => 0.0,
                    ];

                    if (!isset($providerLines[$providerId])) {
                        $providerLines[$providerId] = [];
                    }
                    $providerLines[$providerId][] = $index;
                }

                $afterDiscount = max(0, $grossSubtotal - $itemDiscountTotal);

                $providerNetTotals = [];
                foreach ($lines as &$line) {
                    $lineNetAfterOrder = max(0, $line['net_before_order']);
                    $line['net_after_order'] = $lineNetAfterOrder;

                    $pid = $line['provider_id'];
                    $providerNetTotals[$pid] = ($providerNetTotals[$pid] ?? 0) + $lineNetAfterOrder;
                }
                unset($line);

                // 2) recargo a proveedores por tarjeta (4.5%), distribuido proporcionalmente
                $providerChargeTotal = 0.0;
                if ($method === 'tarjeta') {
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

                        $providerChargeTotal = round(array_reduce($lines, function ($carry, $line) {
                            return $carry + ($line['provider_charge'] ?? 0);
                        }, 0.0), 2);
                    }
                }

                $total = round(max(0, $afterDiscount - $providerChargeTotal), 2);

                // 5) ticket consecutivo (idventa)
                $nextIdVenta = (int) DB::table('ventas')->max('idventa') + 1;

                // 6) efectivo / cambio
                $vendedor = $request->user()->nombre ?? $request->user()->email ?? 'admin';
                $recibo = null;
                $cambio = null;

                if ($method === 'efectivo') {
                    $recib = (float) $received;
                    if ($recib < $total) {
                        throw new \RuntimeException('Efectivo recibido insuficiente');
                    }
                    $recibo = $recib;
                    $cambio = round($recib - $total, 2);
                } else {
                    // tarjeta o transferencia: cobro exacto
                    $recibo = $total;
                    $cambio = 0.0;
                }
        $ie = $request->input('ie', 1); // legacy

        // 7) encabezado de venta (tabla legacy "ventas")
        $venta = Venta::create([
            'idventa' => $nextIdVenta,
            'subtotal' => round($grossSubtotal, 2),
            'tarjeta_cargo' => round($providerChargeTotal, 2),
            'totalventa' => $total,
            'metodo' => $method,       // 'efectivo' | 'tarjeta' | 'transferencia'
            'recibo' => $recibo,
            'cambio' => $cambio,
            'vendedor' => $vendedor,
            'fecha' => $fechaHoy,     // "d/m/y" (varchar(10))
            'ie' => $ie,
            'concepto' => 'VENTA MOSTRADOR',
        ]);

        // 8) renglones (tabla legacy "ventadesg") + actualizar inventario
        $hora = date('H:i:s'); // ok para time(6)

        foreach ($lines as $ln) {
            $lineItemDiscount = round($ln['item_discount'], 2);
            $lineProviderCharge = round($ln['provider_charge'] ?? 0.0, 2);

            // Etiqueta de promoción: NO considerar el recargo como "descuento"
            $promotionFlag = 'normal';
            if ($lineItemDiscount > 0) {
                $promotionFlag = 'descuento - producto';
            }

                    if ($bundleLabel = $this->detectPromotionLabel($ln['producto'], (int) ($ln['qty'] ?? 0))) {
                        $promotionFlag = $bundleLabel;
                    }

                    VentaDesg::create([
                        'idventa' => $nextIdVenta,
                        'fecha' => $fechaHoy,
                        'idprod' => (int) $ln['producto']->ident,
                        'nombre' => (string) $ln['producto']->nombre,
                        'proveedor' => (int) $ln['producto']->proveedorid,
                        'puni' => (float) $ln['unit'],
                        'cant' => (int) $ln['qty'],
                        'total' => (float) $ln['gross'],
                        'descuento_producto' => $lineItemDiscount,
                        'cargo_tarjeta_proveedor' => ($lineProviderCharge > 0 ? $lineProviderCharge : null),
                        'promotion' => $promotionFlag,
                        'hora' => $hora,
                        'proveedor_pago' => round(($ln['provider_unit_cost'] ?? 0) * (int) $ln['qty'], 2),
                        'proveedor_porcentaje' => $ln['provider_type'] === 'porcentaje'
                            ? ($ln['provider_pct'] ?? null)
                            : null,
                    ]);

                    // disminuir inventario
                    $inv = $ln['inv'];
                    if (!$inv) {
                        $inv = new Inventario();
                        $inv->ident = (int) $ln['producto']->ident;
                        $inv->existencia = 0;
                        $inv->importe = 0;
                        $inv->provee = (int) $ln['producto']->proveedorid;
                        $inv->precio_individual = (float) $ln['unit'];
                    }
                    $inv->existencia = max(0, (int) $inv->existencia - (int) $ln['qty']);
                    $inv->importe = max(0, (float) $inv->importe - ((int) $ln['qty'] * (float) $ln['unit']));
                    $inv->save();
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
                    'surcharge_percent' => $method === 'tarjeta' ? 4.5 : 0.0,
                    'surcharge_amount' => $providerChargeTotal,
                    'tarjeta_cargo' => round($providerChargeTotal, 2),
                    'total' => $total,
                ];
            });

            return response()->json(['data' => $payload], 201);

        } catch (Throwable $e) {
            report($e);
            $msg = $e->getMessage() ?: 'No se pudo finalizar la venta';
            $code = str_contains(strtolower($msg), 'inventario') ? 422 : 500;
            return response()->json(['message' => $msg], $code);
        }
    }


    // POST /api/cashier/expenses
    public function registerExpense(ExpenseLegacyRequest $request)
    {
        $fecha = $this->normalizeFecha($request->input('fecha'));

        $caja = $this->cajaByFechaQuery($fecha)->where('estado', 1)->first();
        if (!$caja) {
            return response()->json(['message' => 'Debes abrir caja antes de registrar gastos'], 409);
        }

        $total = round((float) $request->input('totalventa'), 2);
        if ($total <= 0) {
            return response()->json(['message' => 'El total del gasto debe ser mayor a cero'], 422);
        }

        try {
            $venta = DB::transaction(function () use ($request, $fecha, $total) {
                $nextIdVenta = (int) DB::table('ventas')->max('idventa') + 1;

                $method = $request->input('method') ?: 'efectivo';
                $concepto = $request->input('concepto');
                $vendedor = $request->input('vendedor') ?: ($request->user()->nombre ?? $request->user()->email ?? 'admin');

                $subtotal = round($total, 2);
                $tarjetaCargo = 0.0;
                $totalVenta = $subtotal;

                // Registro de gasto: sólo un encabezado en ventas con totales neutralizados.
                return Venta::create([
                    'idventa' => $nextIdVenta,
                    'subtotal' => $subtotal,
                    'tarjeta_cargo' => $tarjetaCargo,
                    'totalventa' => $totalVenta,
                    'metodo' => $method,
                    'recibo' => 0,
                    'cambio' => 0,
                    'vendedor' => $vendedor,
                    'fecha' => $fecha,
                    'ie' => 0,
                    'concepto' => $concepto,
                ]);
            });

            return response()->json(['data' => $venta], 201);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'No se pudo registrar el gasto'], 500);
        }
    }

    // POST /api/cashier/send-ticket
    public function emailTicket(SendTicketEmailRequest $request)
    {
        $fromEmail = config('mail.from.address');
        $fromName = config('mail.from.name', 'Rosa Mexicano');
        if (!$fromEmail) {
            return response()->json(['message' => 'Servicio de correo no configurado'], 500);
        }

        $ventaId = $request->integer('venta_id');
        $canal = $request->input('canal');

        $cliente = $request->input('cliente', []);
        $nombre = $cliente['nombre'] ?? 'Cliente';
        $email = isset($cliente['email']) ? trim((string) $cliente['email']) : null;
        $telefono = $cliente['telefono'] ?? null;

        if (!$email) {
            return response()->json(['message' => 'El correo del cliente es obligatorio para enviar el ticket.'], 422);
        }

        $subject = $request->input('subject') ?: "Ticket de compra - Venta #{$ventaId}";
        $templateId = $request->input('template_id');
        $message = $request->input('message', 'Gracias por tu compra.');
        $logMessage = $request->input('log_message', 'Venta realizada');

        $pdfBase64 = $request->input('ticket_pdf_base64');
        $decodedPdf = base64_decode($pdfBase64, true);
        if ($decodedPdf === false) {
            return response()->json(['message' => 'PDF inválido.'], 422);
        }

        $storedLink = null;
        try {
            $fileName = sprintf('tickets/venta_%s_%s.pdf', $ventaId, now()->format('Ymd_His'));
            Storage::disk('public')->put($fileName, $decodedPdf);
            $storedLink = Storage::disk('public')->url($fileName);
        } catch (Throwable $e) {
            Log::warning('No se pudo guardar el ticket en almacenamiento', ['error' => $e->getMessage()]);
        }

        try {
            $mailable = (new TicketMail(
                $nombre,
                $message,
                $telefono,
                $canal,
                $ventaId,
                $decodedPdf,
                $subject,
                $templateId
            ))->from($fromEmail, $fromName);

            Mail::to($email)->send($mailable);
        } catch (Throwable $e) {
            Log::error('No se pudo enviar el correo', [
                'error' => $e->getMessage(),
            ]);
            Log::error('Mail to:' . $email . " Nombre: " . $nombre, [
                'error' => $e->getMessage(),
            ]);

            Mailer::create([
                'mail' => $storedLink ?? 'ticket-no-guardado',
                'asunto' => $subject,
                'mensaje' => $logMessage . ' (error envío)',
                'status' => 0,
                'fecha' => now()->toDateString(),
            ]);

            return response()->json(['message' => 'No se pudo enviar el correo.'], 502);
        }

        Mailer::create([
            'mail' => $storedLink ?? 'ticket-no-guardado',
            'asunto' => $subject,
            'mensaje' => $logMessage,
            'status' => 1,
            'fecha' => now()->toDateString(),
            'email' => $email ?? 'no-email',
        ]);

        return response()->json(['message' => 'Ticket enviado correctamente.']);
    }
    private function detectPromotionLabel(?Producto $producto, int $quantity): ?string
    {
        if (!$producto || $quantity <= 0) {
            return null;
        }

        $productIdent = (int) ($producto->ident ?? 0);
        $providerIdent = (int) ($producto->proveedorid ?? 0);
        if ($productIdent === 0 && $providerIdent === 0) {
            return null;
        }

        // Cache key includes date because validity depends on "inicia"/"vence"
        static $cache = [];
        $today = Carbon::today()->toDateString(); // 'YYYY-MM-DD'
        $cacheKey = $productIdent . ':' . $providerIdent . ':' . $quantity . ':' . $today;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // Base query: active + within date window (open-ended allowed)
        $query = Promocion::query()
            ->where('estado', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('inicia')->orWhereDate('inicia', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('vence')->orWhereDate('vence', '>=', $today);
            })
            ->whereIn('tipo', ['bundle', 'gratis', 'descuento']);

        // Scope by product or provider (same logic as your bundle detector)
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

        // Pull candidates and filter by mincompra + field presence
        $candidates = $query->get()->filter(function (Promocion $promo) use ($quantity) {
            if ($promo->mincompra && $quantity < (int) $promo->mincompra) {
                return false;
            }
            if (in_array($promo->tipo, ['bundle', 'gratis'], true)) {
                return (int) ($promo->gratis ?? 0) > 0;
            }
            if ($promo->tipo === 'descuento') {
                return (float) ($promo->descuento ?? 0) > 0;
            }
            return false;
        });

        if ($candidates->isEmpty()) {
            return $cache[$cacheKey] = null;
        }

        // Choose best: prefer bundle/gratis (max gratis), else highest % discount
        $bestBundleGratis = $candidates
            ->whereIn('tipo', ['bundle', 'gratis'])
            ->sortByDesc(fn($p) => (int) $p->gratis)
            ->first();

        if ($bestBundleGratis) {
            return $cache[$cacheKey] = sprintf('%d gratis', (int) $bestBundleGratis->gratis);
        }

        $bestPct = $candidates
            ->where('tipo', 'descuento')
            ->sortByDesc(fn($p) => (float) $p->descuento)
            ->first();

        if ($bestPct) {
            $pct = (float) $bestPct->descuento;
            $pctLabel = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.'); // e.g., 12.5 -> "12.5"
            return $cache[$cacheKey] = 'descuento - promotion - ' . $pctLabel . '%';
        }

        return $cache[$cacheKey] = null;
    }
}


// Undefined column: 7 ERROR: column "activa" does not exist LINE 1: ...lect * from "promociones" where "estado" = $1 and "activa" =... ^ 
// (Connection: pgsql, SQL: select * from "promociones" where "estado" = 1 and "activa" = 1 and ("inicia" is null or "inicia"::date <= 2025-11-03) 
// and ("vence" is null or "vence"::date >= 2025-11-03) and "tipo" in (bundle, gratis, descuento) and ("producto" = 614173 or
// ("producto" is null and "proveedor" = 481633)))

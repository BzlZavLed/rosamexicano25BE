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
use App\Models\Producto;
use App\Models\Inventario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Mail\TicketMail;
use App\Models\Mailer;
use Throwable;

class CashierLegacyController extends Controller
{
    /** Return today as legacy d/m/y (two-digit year) */
    private function todayStr(): string
    {
        // ex: 22/10/25
        return date('d/m/y');
    }

    /* ===================== CAJA ===================== */

    // GET /api/caja/status
    public function status(Request $request)
    {
        $fecha = $this->todayStr();
        $row = EstadoCaja::where('fecha', $fecha)->orderByDesc('id')->first();

        return response()->json([
            'open' => $row && (int) $row->estado === 1,
            'caja' => $row,
        ]);
    }

    // POST /api/caja/open  { saldo: number, fecha?: "d/m/y" }
    public function open(CajaLegacyRequest $request)
    {
        $fecha = $request->input('fecha') ?: $this->todayStr();

        $existsOpen = EstadoCaja::where('fecha', $fecha)->where('estado', 1)->exists();
        if ($existsOpen) {
            return response()->json(['message' => 'Ya existe caja abierta para esa fecha'], 409);
        }

        $row = EstadoCaja::create([
            'fecha'        => $fecha,              // varchar(10) legacy
            'estado'       => 1,                   // 1 = abierta
            'saldoinicial'        => (float) $request->input('saldo', 0),   // saldo inicial (efectivo)
            'saldofinal'        => 0,   // saldo final (efectivo) --- IGNORE ---
            'saldosistema' => 0,                   // se calculará al cerrar
            'usuario'      => $request->user()->nombre ?? $request->user()->email ?? 'admin',
        ]);

        return response()->json($row, 201);
    }

    // POST /api/caja/close  { saldosistema?: number, fecha?: "d/m/y" }
    public function close(CajaLegacyRequest $request)
    {
        $fecha = $request->input('fecha') ?: $this->todayStr();

        // caja abierta de ese día
        $row = EstadoCaja::where('fecha', $fecha)->where('estado', 1)->orderByDesc('id')->first();
        if (!$row) {
            return response()->json(['message' => 'No hay caja abierta para la fecha indicada'], 409);
        }

        // total en efectivo del día (según ventas.totalventa y ventas.metodo='efectivo')
        $cashTotal = (float) DB::table('ventas')
            ->where('fecha', $fecha)
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
            'caja'       => $row,
            'cash_today' => $cashTotal,
        ]);
    }

    /* ================== PRODUCT FIND ================= */

    // GET /api/cashier/find-product?barcode=123456 or ?search=papel
    public function findProduct(CashierFindRequest $request)
    {
        $q = Producto::with(['proveedor', 'inventario']);

        if ($barcode = $request->input('barcode')) {
            $q->where('ident', (int)$barcode);
        }
        if ($s = $request->input('search')) {
            $like = '%' . Str::lower($s) . '%';
            $q->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                   ->orWhereRaw('LOWER(descripcion) LIKE ?', [$like]);
            });
        }
        if ($pid = $request->input('proveedor_id')) {
            $q->where('proveedorid', (int)$pid);
        }

        $per = (int)$request->input('per_page', 25);
        $data = $q->orderBy('nombre')->limit($per)->get();

        return response()->json(['data' => $data]);
    }

    /* ===================== CHECKOUT ================== */

    // POST /api/cashier/checkout
    public function checkout(CheckoutLegacyRequest $request)
    {
        // caja debe estar abierta para hoy (d/m/y)
        $fechaHoy = $this->todayStr();
        $caja = EstadoCaja::where('fecha', $fechaHoy)->where('estado', 1)->first();
        if (!$caja) {
            return response()->json(['message' => 'Debes abrir caja antes de vender'], 409);
        }

        $items = $request->input('items', []);
        $discountPercent = (float)($request->input('discount_percent') ?? 0);
        $method  = $request->input('payment.method');   // 'efectivo' | 'tarjeta' | 'transferencia'
        $received= $request->input('payment.received'); // efectivo entregado (solo efectivo)

        try {
            $payload = DB::transaction(function () use ($items, $discountPercent, $method, $received, $fechaHoy, $request) {

                $lines = [];
                $grossSubtotal = 0.0;
                $itemDiscountTotal = 0.0;

                // 1) validar stock + preparar renglones
                foreach ($items as $it) {
                    $ident = (int)$it['ident'];
                    $qty   = (int)$it['qty'];

                    $producto = Producto::where('ident', $ident)->first();
                    if (!$producto) {
                        throw new \RuntimeException("Producto {$ident} no existe");
                    }

                    $inv = Inventario::where('ident', $ident)->lockForUpdate()->first();
                    $exist = (int)($inv?->existencia ?? 0);
                    if ($qty > $exist) {
                        throw new \RuntimeException("Inventario insuficiente para {$ident}");
                    }

                    $unit = (float)$producto->precio;
                    $gross = $unit * $qty;
                    $grossSubtotal += $gross;

                    $itemDiscount = 0.0;
                    if (array_key_exists('product_desc', $it) && $it['product_desc'] !== null) {
                        $itemDiscount = max(0, (float)$it['product_desc']);
                    } elseif (array_key_exists('discount_amount', $it) && $it['discount_amount'] !== null) {
                        $itemDiscount = max(0, (float)$it['discount_amount']);
                    } elseif (array_key_exists('discount_percent', $it) && $it['discount_percent'] !== null) {
                        $percent = max(0, min(100, (float)$it['discount_percent']));
                        $itemDiscount = round($gross * ($percent / 100), 2);
                    }

                    if ($itemDiscount > $gross) {
                        $itemDiscount = $gross;
                    }
                    $itemDiscount = round($itemDiscount, 2);

                    $itemDiscountTotal += $itemDiscount;
                    $net = max(0, $gross - $itemDiscount);

                    $lines[] = [
                        'producto' => $producto,
                        'inv' => $inv,
                        'qty' => $qty,
                        'unit' => $unit,
                        'gross' => $gross,
                        'item_discount' => $itemDiscount,
                        'net' => $net,
                    ];
                }

                // 2) descuento y recargo
                $netSubtotal = max(0, $grossSubtotal - $itemDiscountTotal);
                $orderDiscountAmount = $discountPercent > 0 ? round($netSubtotal * ($discountPercent / 100), 2) : 0.0;
                if ($orderDiscountAmount > $netSubtotal) {
                    $orderDiscountAmount = $netSubtotal;
                }

                $afterDiscount  = max(0, $netSubtotal - $orderDiscountAmount);

                $surchargePercent = $method === 'tarjeta' ? 4.5 : 0.0;
                $surchargeAmount  = $surchargePercent > 0 ? round($afterDiscount * ($surchargePercent / 100), 2) : 0.0;

                $total = round(max(0, $afterDiscount + $surchargeAmount), 2);

                // 3) ticket consecutivo (idventa)
                $nextIdVenta = (int) DB::table('ventas')->max('idventa') + 1;

                // 4) efectivo / cambio
                $vendedor = $request->user()->nombre ?? $request->user()->email ?? 'admin';
                $recibo   = null;
                $cambio   = null;

                if ($method === 'efectivo') {
                    $recib = (float)$received;
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

                // 5) encabezado de venta (tabla legacy "ventas")
                $venta = Venta::create([
                    'idventa'    => $nextIdVenta,
                    'subtotal'   => round($netSubtotal, 2),
                    'tarjeta_cargo' => round($surchargeAmount, 2),
                    'descuento_general' => round($orderDiscountAmount, 2),
                    'totalventa' => $total,
                    'metodo'     => $method,       // 'efectivo' | 'tarjeta' | 'transferencia'
                    'recibo'     => $recibo,
                    'cambio'     => $cambio,
                    'vendedor'   => $vendedor,
                    'fecha'      => $fechaHoy,     // "d/m/y" (varchar(10))
                    'ie'         => $ie,
                    'concepto'   => 'VENTA MOSTRADOR',
                ]);

                // 6) renglones (tabla legacy "ventadesg") + actualizar inventario
                $hora = date('H:i:s'); // ok para time(6)
                $lineCount = count($lines);
                $remainingOrderDiscount = $orderDiscountAmount;
                foreach ($lines as $idx => $ln) {
                    $lineOrderDiscount = 0.0;
                    if ($orderDiscountAmount > 0 && $netSubtotal > 0) {
                        if ($idx === $lineCount - 1) {
                            $lineOrderDiscount = $remainingOrderDiscount;
                        } else {
                            $lineOrderDiscount = round($orderDiscountAmount * ($ln['net'] / $netSubtotal), 2);
                            $remainingOrderDiscount = max(0, $remainingOrderDiscount - $lineOrderDiscount);
                        }
                    }

                    $totalLineDiscount = round($ln['item_discount'] + $lineOrderDiscount, 2);

                    VentaDesg::create([
                        'idventa'   => $nextIdVenta,
                        'fecha'     => $fechaHoy,
                        'idprod'    => (int)$ln['producto']->ident, // barcode
                        'nombre'    => (string)$ln['producto']->nombre,
                        'proveedor' => (int)$ln['producto']->proveedorid,
                        'puni'      => (float)$ln['unit'],
                        'cant'      => (int)$ln['qty'],
                        'total'     => (float)$ln['gross'],
                        'product_desc'   => (float)$totalLineDiscount,
                        'hora'      => $hora,
                    ]);

                    // disminuir inventario
                    $inv = $ln['inv'];
                    if (!$inv) {
                        $inv = new Inventario();
                        $inv->ident = (int)$ln['producto']->ident;
                        $inv->existencia = 0;
                        $inv->importe = 0;
                        $inv->provee = (int)$ln['producto']->proveedorid;
                        $inv->precio_individual = (float)$ln['unit'];
                    }
                    $inv->existencia = max(0, (int)$inv->existencia - (int)$ln['qty']);
                    $inv->importe    = max(0, (float)$inv->importe - ((int)$ln['qty'] * (float)$ln['unit']));
                    $inv->save();
                }

                $venta->load('lineas');

                return [
                    'venta'             => $venta,
                    'subtotal'          => $grossSubtotal,
                    'item_discount_total' => $itemDiscountTotal,
                    'subtotal_after_item_discounts' => $netSubtotal,
                    'discount_percent'  => $discountPercent,
                    'discount_amount'   => $orderDiscountAmount,
                    'descuento_general' => $orderDiscountAmount,
                    'overall_discount_total' => $itemDiscountTotal + $orderDiscountAmount,
                    'surcharge_percent' => $surchargePercent,
                    'surcharge_amount'  => $surchargeAmount,
                    'tarjeta_cargo'     => round($surchargeAmount, 2),
                    'total'             => $total,
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
        $fecha = $request->input('fecha') ?: $this->todayStr();

        $caja = EstadoCaja::where('fecha', $fecha)->where('estado', 1)->first();
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
                $descuentoGeneral = 0.0;
                
                $totalVenta = $subtotal;

                // Registro de gasto: sólo un encabezado en ventas con totales neutralizados.
                return Venta::create([
                    'idventa' => $nextIdVenta,
                    'subtotal' => $subtotal,
                    'descuento_general' => $descuentoGeneral,
                    'tarjeta_cargo' => 0,
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
        $fromName  = config('mail.from.name', 'Rosa Mexicano');
        if (!$fromEmail) {
            return response()->json(['message' => 'Servicio de correo no configurado'], 500);
        }

        $ventaId  = $request->integer('venta_id');
        $canal    = $request->input('canal');

        $cliente  = $request->input('cliente', []);
        $nombre   = $cliente['nombre'] ?? 'Cliente';
        $email    = isset($cliente['email']) ? trim((string) $cliente['email']) : null;
        $telefono = $cliente['telefono'] ?? null;

        if (!$email) {
            return response()->json(['message' => 'El correo del cliente es obligatorio para enviar el ticket.'], 422);
        }

        $subject  = $request->input('subject') ?: "Ticket de compra - Venta #{$ventaId}";
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
            Log::error('Mail to:'.$email. " Nombre: " .$nombre, [
                'error' => $e->getMessage(),
            ]);

            Mailer::create([
                'mail'    => $storedLink ?? 'ticket-no-guardado',
                'asunto'  => $subject,
                'mensaje' => $logMessage . ' (error envío)',
                'status'  => 0,
                'fecha'   => now()->toDateString(),
            ]);

            return response()->json(['message' => 'No se pudo enviar el correo.'], 502);
        }

        Mailer::create([
            'mail'    => $storedLink ?? 'ticket-no-guardado',
            'asunto'  => $subject,
            'mensaje' => $logMessage,
            'status'  => 1,
            'fecha'   => now()->toDateString(),
            'email' => $email ?? 'no-email',
        ]);

        return response()->json(['message' => 'Ticket enviado correctamente.']);
    }
}

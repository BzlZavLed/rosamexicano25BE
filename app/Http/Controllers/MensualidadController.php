<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMensualidadRequest;
use App\Http\Requests\UpdateMensualidadRequest;
use App\Http\Requests\MensualidadPayRequest;
use App\Http\Requests\MensualidadBulkRequest;
use App\Http\Requests\MensualidadSendChargeRequest;
use App\Http\Resources\MensualidadResource;
use App\Mail\MensualidadPaidMail;
use App\Mail\MensualidadChargeMail;
use App\Models\Mensualidad;
use App\Models\Mailer;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Throwable;

class MensualidadController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $query = Mensualidad::with('proveedor');

        if ($request->filled('fecha')) {
            $query->whereDate('fecha', $request->input('fecha'));
        }

        if ($request->filled('mes_cobro')) {
            $mes = trim((string) $request->input('mes_cobro'));
            $query->where('mes_cobro', 'like', $mes . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($search = $request->get('search')) {
            $like = '%' . Str::lower($search) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(concepto) LIKE ?', [$like]);
            });
        }

        $query->orderByDesc('fecha')->orderByDesc('id');

        $total = (clone $query)->count();

        if ($total > 20) {
            $paginator = $query->paginate($perPage)->appends($request->query());
            return MensualidadResource::collection($paginator);
        }

        $items = $query->get();
        return MensualidadResource::collection($items);
    }

    public function store(StoreMensualidadRequest $request)
    {
        Log::info('MensualidadController@store reached', [
            'ip' => $request->ip(),
            'proveedor_id' => $request->input('proveedor_id'),
            'mes_cobro' => $request->input('mes_cobro'),
            'fecha_cobro' => $request->input('fecha_cobro'),
        ]);

        $data = $request->validated();

        [$receiptBinary, $cobroLink] = $this->storeReceipt(
            $data['cobro_pdf_base64'] ?? null,
            'cobros',
            sprintf('mensualidad_%s', $data['proveedor_id'])
        );

        $mensualidadData = [
            'fecha' => $data['fecha_cobro'],
            'mes_cobro' => $data['mes_cobro'],
            'concepto' => $data['concepto'],
            'nota' => $data['nota'] ?? null,
            'importe' => $data['importe'],
            'proveedor_id' => $data['proveedor_id'],
            'status' => $data['status'] ?? 'pending',
            'cobro_path' => $cobroLink,
            'mail_status' => 0,
        ];

        if (($mensualidadData['status'] === 'paid') && empty($data['payment_date'])) {
            $mensualidadData['payment_date'] = now()->toDateString();
        } elseif (!empty($data['payment_date'])) {
            $mensualidadData['payment_date'] = $data['payment_date'];
        }

        $proveedor = Proveedor::find($data['proveedor_id']);
        if (!$proveedor) {
            return response()->json(['message' => 'Proveedor no encontrado'], 404);
        }
        if ($proveedor->tipo !== 'normal') {
            return response()->json(['message' => 'Solo los proveedores de tipo "normal" generan cobros mensuales.'], 422);
        }
        $mensualidadData['nombre'] = $proveedor?->nombre ?? 'PROVEEDOR';

        $mensualidad = Mensualidad::create($mensualidadData);
        $mensualidad->load('proveedor');
        Log::info('Mensualidad created', [
            'mensualidad_id' => $mensualidad->id,
            'proveedor_id' => $mensualidad->proveedor_id,
        ]);

        $mailStatus = null;
        if ($proveedor && $proveedor->tipo === 'normal' && filled($proveedor->email)) {
            $mailStatus = $this->sendChargeEmail($proveedor, $mensualidad, $receiptBinary);
        }

        if ($mailStatus !== null) {
            Mailer::create([
                'mail' => $mensualidad->cobro_path ?? 'cobro-sin-comprobante',
                'asunto' => 'Cobro creado a proveedor ' . $proveedor?->nombre,
                'mensaje' => $mensualidad->concepto,
                'status' => $mailStatus,
                'fecha' => now()->toDateString(),
                'email' => $proveedor->email ?? 'no-email',
            ]);

            if ($mailStatus === 1) {
                $mensualidad->mail_status = 1;
                $mensualidad->save();
            }
        } else {
            $mensualidad->mail_status = 0;
            $mensualidad->save();
        }

        $mensualidad->refresh()->load('proveedor');

        return response()->json([
            'data' => new MensualidadResource($mensualidad),
            'mail' => [
                'sent' => $mailStatus === 1,
                'status' => $mailStatus ?? 'not-sent',
            ],
        ], 201);
    }

    public function show(Mensualidad $mensualidad)
    {
        $mensualidad->load('proveedor');
        return new MensualidadResource($mensualidad);
    }

    public function update(UpdateMensualidadRequest $request, Mensualidad $mensualidad)
    {
        $data = $request->validated();
        $update = [];

        if (array_key_exists('fecha_cobro', $data)) {
            $update['fecha'] = $data['fecha_cobro'];
        }
        if (array_key_exists('mes_cobro', $data)) {
            $update['mes_cobro'] = $data['mes_cobro'];
        }
        if (array_key_exists('concepto', $data)) {
            $update['concepto'] = $data['concepto'];
        }
        if (array_key_exists('nota', $data)) {
            $update['nota'] = $data['nota'];
        }
        if (array_key_exists('importe', $data)) {
            $update['importe'] = $data['importe'];
        }
        if (array_key_exists('cantidad_pago', $data)) {
            $update['cantidad_pago'] = $data['cantidad_pago'];
        }
        if (array_key_exists('restante', $data)) {
            $update['restante'] = $data['restante'];
        }
        if (array_key_exists('pago_completo', $data)) {
            $update['pago_completo'] = (bool) $data['pago_completo'];
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = $data['status'];
        }
        if (array_key_exists('payment_date', $data)) {
            $update['payment_date'] = $data['payment_date'];
        }
        if (array_key_exists('proveedor_id', $data)) {
            $update['proveedor_id'] = $data['proveedor_id'];
            $proveedor = Proveedor::find($data['proveedor_id']);
            if ($proveedor) {
                $update['nombre'] = $proveedor->nombre;
            }
        }

        $importeBase = isset($update['importe']) ? (float) $update['importe'] : (float) $mensualidad->importe;
        $cantidadBase = isset($update['cantidad_pago']) ? (float) $update['cantidad_pago'] : (float) $mensualidad->cantidad_pago;

        if (($update['status'] ?? $mensualidad->status) === 'paid') {
            if (empty($update['payment_date'])) {
                $update['payment_date'] = now()->toDateString();
            }
            $update['cantidad_pago'] = $importeBase;
            $update['restante'] = 0;
            $update['pago_completo'] = true;
        } else {
            $restante = isset($update['restante']) ? (float) $update['restante'] : max($importeBase - $cantidadBase, 0);
            $update['restante'] = max($restante, 0);
            $update['pago_completo'] = $update['pago_completo'] ?? ($update['restante'] <= 0);
        }

        $mensualidad->update($update);
        $mensualidad->refresh()->load('proveedor');

        return new MensualidadResource($mensualidad);
    }

    public function destroy(Mensualidad $mensualidad)
    {
        $mensualidad->delete();
        return response()->noContent();
    }

    public function bulkCreate(MensualidadBulkRequest $request)
    {
        $data = $request->validated();

        $concepto = $data['concepto'];
        $mesCobro = $data['mes_cobro'];
        $fechaCobro = $data['fecha_cobro'];
        $nota = $data['nota'] ?? null;
        $cobros = $data['cobros'];

        $created = [];
        $skipped = [];
        $mailSent = 0;
        $mailFailed = 0;

                DB::transaction(function () use ($cobros, $mesCobro, $fechaCobro, $nota, $concepto, &$created, &$skipped, &$mailSent, &$mailFailed) {
            foreach ($cobros as $cobro) {
                $prov = Proveedor::find($cobro['proveedor_id']);
                if (!$prov || $prov->tipo !== 'normal') {
                    $skipped[] = $cobro['proveedor_id'];
                    continue;
                }

                $exists = Mensualidad::where('proveedor_id', $prov->id)
                    ->where('mes_cobro', $mesCobro)
                    ->whereDate('fecha', $fechaCobro)
                    ->exists();

                if ($exists) {
                    $skipped[] = $prov->id;
                    continue;
                }

                [$receiptBinary, $cobroLink] = $this->storeReceipt(
                    $cobro['cobro_pdf_base64'] ?? null,
                    'cobros',
                    sprintf('mensualidad_%s', $prov->id)
                );

                $importe = (float) $cobro['importe'];
                $cantidadPago = isset($cobro['cantidad_pago']) ? (float) $cobro['cantidad_pago'] : 0;
                $restante = isset($cobro['restante']) ? (float) $cobro['restante'] : max($importe - $cantidadPago, 0);
                $pagoCompleto = $cobro['pago_completo'] ?? ($restante <= 0);

                $mensualidad = Mensualidad::create([
                    'fecha' => $fechaCobro,
                    'nombre' => $prov->nombre,
                    'concepto' => $concepto,
                    'mes_cobro' => $mesCobro,
                    'nota' => $nota,
                    'importe' => $importe,
                    'proveedor_id' => $prov->id,
                    'status' => $pagoCompleto ? 'paid' : 'pending',
                    'cantidad_pago' => $pagoCompleto ? $importe : $cantidadPago,
                    'restante' => $pagoCompleto ? 0 : max($restante, 0),
                    'pago_completo' => (bool) $pagoCompleto,
                    'payment_date' => $pagoCompleto ? ($cobro['payment_date'] ?? now()->toDateString()) : null,
                    'cobro_path' => $cobroLink,
                    'mail_status' => 0,
                ]);

                $mensualidad->load('proveedor');
                $created[] = $mensualidad;

                $mailStatus = null;
                if (filled($prov->email)) {
                    $mailStatus = $this->sendChargeEmail($prov, $mensualidad, $receiptBinary);
                }
                if ($mailStatus === 1) {
                    $mailSent++;
                } elseif ($mailStatus === 0) {
                    $mailFailed++;
                }
                if ($mailStatus !== null) {
                    Mailer::create([
                        'mail' => $cobroLink ?? 'cobro-sin-comprobante',
                        'asunto' => 'Cobro creado a proveedor ' . $prov->nombre,
                        'mensaje' => $concepto,
                        'status' => $mailStatus,
                        'fecha' => now()->toDateString(),
                        'email' => $prov->email ?? 'no-email',
                    ]);

                    if ($mailStatus === 1) {
                        $mensualidad->mail_status = 1;
                        $mensualidad->save();
                    } else {
                        $mensualidad->mail_status = 0;
                        $mensualidad->save();
                    }
                } else {
                    $mensualidad->mail_status = 0;
                    $mensualidad->save();
                }
                $mensualidad->refresh()->load('proveedor');
            }
        });

        $statusCode = count($created) > 0 ? 201 : 200;

        return response()->json([
            'message' => 'Cobros generados.',
            'created' => count($created),
            'skipped' => count($skipped),
            'mail' => [
                'sent' => $mailSent,
                'failed' => $mailFailed,
            ],
            'data' => MensualidadResource::collection(collect($created)),
        ], $statusCode);
    }

    public function pay(MensualidadPayRequest $request, Mensualidad $mensualidad)
    {
        $providerId = $request->input('provider_id', $mensualidad->proveedor_id);
        $proveedor = Proveedor::find($providerId);
        if (!$proveedor) {
            return response()->json(['message' => 'Proveedor no encontrado'], 404);
        }
        if ($proveedor->tipo !== 'normal') {
            return response()->json(['message' => 'Solo los proveedores de tipo "normal" tienen pagos mensuales.'], 422);
        }

        $providerEmail = $request->input('email') ?? $proveedor->email;
        $providerName = $proveedor->nombre;
        $providerPhone = $proveedor->tel;

        if ($request->filled('email') && $proveedor->email !== $providerEmail) {
            $proveedor->email = $providerEmail;
            $proveedor->save();
        }

        $paymentAmount = max(0, (float) $request->input('cantidad_pago', $mensualidad->importe));
        $restante = max(0, $mensualidad->importe - $paymentAmount);
        if ($restante > 0 && !$request->filled('restante')) {
            return response()->json(['message' => 'Debe especificar el restante cuando el pago es parcial'], 422);
        }
        if ($request->filled('restante')) {
            $restante = max(0, (float) $request->input('restante'));
        }

        $paymentDate = $request->input('payment_date') ?? now()->toDateString();

        [$pdfBinary, $storedLink] = $this->storeReceipt(
            $request->input('receipt_pdf_base64'),
            'payments',
            sprintf('mensualidad_%s', $mensualidad->id)
        );

        if (!$pdfBinary) {
            return response()->json(['message' => 'PDF inválido.'], 422);
        }

        $mensualidad->receipt_path = $storedLink;

        $subject = $request->input('subject') ?? 'Cobro pagado';
        $messageText = $request->input('message');

        $fromEmail = config('mail.from.address');
        $fromName = config('mail.from.name', 'Rosa Mexicano');
        if (!$fromEmail) {
            return response()->json(['message' => 'Servicio de correo no configurado'], 500);
        }

        $mailStatus = 0;
        if ($providerEmail) {
            try {
                $mailable = (new MensualidadPaidMail(
                    $providerName,
                    $messageText,
                    $providerPhone,
                    $mensualidad->concepto,
                    (float) $mensualidad->importe,
                    $paymentDate,
                    $pdfBinary,
                    $subject
                ))->from($fromEmail, $fromName);

                Mail::to($providerEmail)->send($mailable);
                $mailStatus = 1;
            } catch (Throwable $e) {
                Log::error('No se pudo enviar correo de mensualidad', ['error' => $e->getMessage()]);
                $mailStatus = 0;
            }
        }

        $mensualidad->status = $restante <= 0 ? 'paid' : 'pending';
        $mensualidad->payment_date = $paymentDate;
        $mensualidad->cantidad_pago = min($mensualidad->importe, $paymentAmount);
        $mensualidad->restante = $restante;
        $mensualidad->pago_completo = $restante <= 0;
        $mensualidad->proveedor_id = $proveedor->id;
        $mensualidad->nombre = $proveedor->nombre;
        $mensualidad->save();
        $mensualidad->load('proveedor');

        if ($providerEmail) {
            Mailer::create([
                'mail' => $storedLink ?? 'recibo-no-guardado',
                'asunto' => ($restante <= 0 ? 'Pago de proveedor' : 'Pago parcial de proveedor') . ' ' . $proveedor->nombre,
                'mensaje' => ($restante <= 0 ? 'Pago de proveedor registrado' : 'Pago parcial registrado'),
                'status' => $mailStatus,
                'fecha' => now()->toDateString(),
                'email' => $providerEmail,
            ]);
        }

        return response()->json([
            'message' => $restante <= 0 ? 'Pago registrado.' : 'Pago parcial registrado.',
            'mail' => [
                'sent' => $mailStatus === 1,
                'status' => $mailStatus,
            ],
            'data' => new MensualidadResource($mensualidad),
        ], $mailStatus === 1 ? 200 : 202);
    }

    public function sendCharge(MensualidadSendChargeRequest $request, Mensualidad $mensualidad)
    {
        $mensualidad->load('proveedor');

        $document = $this->getStoredFileFromPublicUrl($mensualidad->cobro_path);
        if (!$document) {
            return response()->json(['message' => 'No existe comprobante de cobro para esta mensualidad'], 422);
        }

        $fromEmail = config('mail.from.address');
        $fromName = config('mail.from.name', 'Rosa Mexicano');
        if (!$fromEmail) {
            return response()->json(['message' => 'Servicio de correo no configurado'], 500);
        }

        $recipientEmail = $request->input('email');
        $subject = $request->input('asunto') ?? 'Cobro generado';

        $mailStatus = 0;

        try {
            $mailable = (new MensualidadChargeMail(
                $mensualidad->nombre ?? $mensualidad->proveedor->nombre ?? 'PROVEEDOR',
                $mensualidad->concepto,
                (float) $mensualidad->importe,
                optional($mensualidad->fecha)->toDateString(),
                $mensualidad->nota,
                $subject
            ))->from($fromEmail, $fromName);

            $mailable->attachData($document['binary'], $document['name'], ['mime' => 'application/pdf']);

            Mail::to($recipientEmail)->send($mailable);
            $mailStatus = 1;
        } catch (Throwable $e) {
            Log::error('No se pudo reenviar correo de cobro', [
                'error' => $e->getMessage(),
                'mensualidad_id' => $mensualidad->id,
            ]);
        }

        $mensualidad->mail_status = $mailStatus;
        $mensualidad->save();
        $mensualidad->refresh()->load('proveedor');

        Mailer::create([
            'mail' => $mensualidad->cobro_path ?? 'cobro-sin-comprobante',
            'email' => $recipientEmail,
            'asunto' => $subject,
            'mensaje' => $mensualidad->concepto,
            'status' => $mailStatus,
            'fecha' => now()->toDateString(),
        ]);

        return response()->json([
            'message' => $mailStatus === 1 ? 'Cobro enviado correctamente.' : 'No se pudo enviar el cobro.',
            'mail' => [
                'sent' => $mailStatus === 1,
                'status' => $mailStatus,
            ],
            'data' => new MensualidadResource($mensualidad),
        ], $mailStatus === 1 ? 200 : 202);
    }

    private function sendChargeEmail(?Proveedor $proveedor, Mensualidad $mensualidad, ?string $bulkPdf = null): int
    {
        if (!$proveedor || empty($proveedor->email)) {
            return 0;
        }

        $fromEmail = config('mail.from.address');
        $fromName = config('mail.from.name', 'Rosa Mexicano');
        if (!$fromEmail) {
            return 0;
        }

        try {
            $mailable = (new MensualidadChargeMail(
                $proveedor->nombre,
                $mensualidad->concepto,
                (float) $mensualidad->importe,
                optional($mensualidad->fecha)->toDateString(),
                $mensualidad->nota,
                'Cobro generado'
            ))->from($fromEmail, $fromName);

            if ($bulkPdf) {
                $mailable->attachData($bulkPdf, 'cobro.pdf', ['mime' => 'application/pdf']);
            }

            Mail::to($proveedor->email)->send($mailable);
            return 1;
        } catch (Throwable $e) {
            Log::error('No se pudo enviar correo de cobro', [
                'error' => $e->getMessage(),
                'proveedor_id' => $proveedor->id,
            ]);
            return 0;
        }
    }

    private function getStoredFileFromPublicUrl(?string $url): ?array
    {
        if (empty($url)) {
            return null;
        }

        $relativePath = $this->resolveStoragePath($url);
        if (!$relativePath) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (!$disk->exists($relativePath)) {
                return null;
            }

            return [
                'path' => $relativePath,
                'binary' => $disk->get($relativePath),
                'name' => basename($relativePath) ?: 'cobro.pdf',
            ];
        } catch (Throwable $e) {
            Log::warning('No se pudo obtener comprobante para reenviar', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            return null;
        }
    }

    private function resolveStoragePath(string $url): ?string
    {
        $parsedPath = parse_url($url, PHP_URL_PATH) ?? $url;
        if (!$parsedPath) {
            return null;
        }

        $relative = ltrim($parsedPath, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }

        return $relative;
    }

    private function storeReceipt(?string $base64, string $directory, string $filenamePrefix): array
    {
        if (empty($base64)) {
            return [null, null];
        }

        $clean = preg_replace('/\s+/', '', (string) $base64);
        if (str_contains($clean, 'base64,')) {
            $clean = explode('base64,', $clean, 2)[1] ?? '';
        }

        $binary = base64_decode($clean, true);
        if ($binary === false) {
            return [null, null];
        }

        try {
            $disk = Storage::disk('public');
            $disk->makeDirectory($directory);
            $fileName = sprintf('%s/%s_%s_%s.pdf', trim($directory, '/'), $filenamePrefix, now()->format('Ymd_His'), Str::random(6));
            $disk->put($fileName, $binary);
            return [$binary, $disk->url($fileName)];
        } catch (Throwable $e) {
            Log::warning('No se pudo guardar recibo', [
                'error' => $e->getMessage(),
                'directory' => $directory,
                'filename' => $filenamePrefix,
            ]);
            return [null, null];
        }
    }
}

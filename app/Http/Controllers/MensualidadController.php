<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMensualidadRequest;
use App\Http\Requests\UpdateMensualidadRequest;
use App\Http\Requests\MensualidadPayRequest;
use App\Http\Requests\MensualidadBulkRequest;
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

        if ($search = $request->get('search')) {
            $like = '%' . Str::lower($search) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(nombre) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(concepto) LIKE ?', [$like]);
            });
        }

        $query->orderByDesc('fecha')->orderByDesc('id');

        return MensualidadResource::collection($query->paginate($perPage));
    }

    public function store(StoreMensualidadRequest $request)
    {
        $data = $request->validated();

        [$receiptBinary, $receiptLink] = $this->storeReceipt(
            $data['cobro_pdf_base64'] ?? null,
            'cobros',
            sprintf('mensualidad_%s', $data['proveedor_id'])
        );

        $mensualidadData = [
            'fecha'        => $data['fecha_cobro'],
            'mes_cobro'    => $data['mes_cobro'],
            'concepto'     => $data['concepto'],
            'nota'         => $data['nota'] ?? null,
            'importe'      => $data['importe'],
            'proveedor_id' => $data['proveedor_id'],
            'status'       => $data['status'] ?? 'pending',
            'receipt_path' => $receiptLink,
        ];

        if (($mensualidadData['status'] === 'paid') && empty($data['payment_date'])) {
            $mensualidadData['payment_date'] = now()->toDateString();
        } elseif (!empty($data['payment_date'])) {
            $mensualidadData['payment_date'] = $data['payment_date'];
        }

        $proveedor = Proveedor::find($data['proveedor_id']);
        $mensualidadData['nombre'] = $proveedor?->nombre ?? 'PROVEEDOR';

        $mensualidad = Mensualidad::create($mensualidadData);
        $mensualidad->load('proveedor');

        $mailStatus = $this->sendChargeEmail($proveedor, $mensualidad, $receiptBinary);

        Mailer::create([
            'mail'    => $mensualidad->receipt_path ?? 'cobro-sin-comprobante',
            'asunto'  => 'Cobro creado a proveedor',
            'mensaje' => $mensualidad->concepto,
            'status'  => $mailStatus,
            'fecha'   => now()->toDateString(),
        ]);
        return new MensualidadResource($mensualidad);
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

        if (($update['status'] ?? null) === 'paid' && empty($update['payment_date'])) {
            $update['payment_date'] = now()->toDateString();
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

        DB::transaction(function () use ($cobros, $mesCobro, $fechaCobro, $nota, $concepto, &$created, &$skipped) {
            foreach ($cobros as $cobro) {
                $prov = Proveedor::find($cobro['proveedor_id']);
                if (!$prov) {
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

                [$receiptBinary, $receiptLink] = $this->storeReceipt(
                    $cobro['cobro_pdf_base64'] ?? null,
                    'cobros',
                    sprintf('mensualidad_%s', $prov->id)
                );

                $mensualidad = Mensualidad::create([
                    'fecha'        => $fechaCobro,
                    'nombre'       => $prov->nombre,
                    'concepto'     => $concepto,
                    'mes_cobro'    => $mesCobro,
                    'nota'         => $nota,
                    'importe'      => $cobro['importe'],
                    'proveedor_id' => $prov->id,
                    'status'       => 'pending',
                    'receipt_path' => $receiptLink,
                ]);

                $mensualidad->load('proveedor');
                $created[] = $mensualidad;

                $mailStatus = $this->sendChargeEmail($prov, $mensualidad, $receiptBinary);

                Mailer::create([
                    'mail'    => $receiptLink ?? 'cobro-sin-comprobante',
                    'asunto'  => 'Cobro creado a proveedor',
                    'mensaje' => $concepto,
                    'status'  => $mailStatus,
                    'fecha'   => now()->toDateString(),
                ]);
            }
        });

        $statusCode = count($created) > 0 ? 201 : 200;

        return response()->json([
            'message' => 'Cobros generados.',
            'created' => count($created),
            'skipped' => count($skipped),
            'data'    => MensualidadResource::collection(collect($created)),
        ], $statusCode);
    }

    public function pay(MensualidadPayRequest $request, Mensualidad $mensualidad)
    {
        $providerId = $request->input('provider_id', $mensualidad->proveedor_id);
        $proveedor = Proveedor::find($providerId);
        if (!$proveedor) {
            return response()->json(['message' => 'Proveedor no encontrado'], 404);
        }

        $providerEmail = $proveedor->email;
        $providerName = $proveedor->nombre;
        $providerPhone = $proveedor->tel;

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
        $fromName  = config('mail.from.name', 'Rosa Mexicano');
        if (!$fromEmail) {
            return response()->json(['message' => 'Servicio de correo no configurado'], 500);
        }

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

        $mensualidad->status = 'paid';
        $mensualidad->payment_date = $paymentDate;
        $mensualidad->proveedor_id = $proveedor->id;
        $mensualidad->nombre = $proveedor->nombre;
        $mensualidad->save();
        $mensualidad->load('proveedor');

        Mailer::create([
            'mail'    => $storedLink ?? 'recibo-no-guardado',
            'asunto'  => 'Pago de proveedor',
            'mensaje' => 'Pago de proveedor registrado',
            'status'  => $mailStatus,
            'fecha'   => now()->toDateString(),
        ]);

        if ($mailStatus === 0) {
            return response()->json([
                'message' => 'Pago registrado, pero el correo no pudo enviarse',
                'data'    => new MensualidadResource($mensualidad),
            ], 202);
        }

        return new MensualidadResource($mensualidad);
    }

    private function sendChargeEmail(?Proveedor $proveedor, Mensualidad $mensualidad, ?string $bulkPdf = null): int
    {
        if (!$proveedor || empty($proveedor->email)) {
            return 0;
        }

        $fromEmail = config('mail.from.address');
        $fromName  = config('mail.from.name', 'Rosa Mexicano');
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

<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMailerRequest;
use App\Http\Requests\UpdateMailerRequest;
use App\Http\Requests\MailerResendRequest;
use App\Http\Resources\MailerResource;
use App\Models\Mailer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Throwable;

class MailerController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = Mailer::query();

        if (($status = $request->get('status')) !== null) {
            $q->where('status', (int) $status);
        }

        if ($date = $request->get('fecha')) {
            $q->whereDate('fecha', $date);
        }

        if ($search = $request->get('search')) {
            $like = '%' . Str::lower($search) . '%';
            $q->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(asunto) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(mensaje) LIKE ?', [$like]);
            });
        }

        $q->orderByDesc('fecha')->orderByDesc('id');

        return MailerResource::collection($q->paginate($perPage));
    }

    public function store(StoreMailerRequest $request)
    {
        $data = $request->validated();
        $data['fecha'] = $data['fecha'] ?? now()->toDateString();

        $mailer = Mailer::create($data);
        return new MailerResource($mailer);
    }

    public function show(Mailer $mailer)
    {
        return new MailerResource($mailer);
    }

    public function update(UpdateMailerRequest $request, Mailer $mailer)
    {
        $mailer->update($request->validated());
        return new MailerResource($mailer);
    }

    public function destroy(Mailer $mailer)
    {
        $mailer->delete();
        return response()->noContent();
    }

    public function resend(MailerResendRequest $request)
    {
        $data = $request->validated();
        $fromEmail = config('mail.from.address');
        $fromName = config('mail.from.name', 'Rosa Mexicano');

        if (!$fromEmail) {
            return response()->json(['message' => 'Servicio de correo no configurado'], 500);
        }

        $attachments = [];
        $mailSource = $data['url'] ?? (!empty($data['pdf']) ? 'pdf-adjunto' : 'manual');

        if (!empty($data['pdf'])) {
            $pdf = $this->decodeBase64Pdf($data['pdf']);
            if (!$pdf) {
                return response()->json(['message' => 'PDF inválido.'], 422);
            }
            $attachments[] = $pdf; // expects ['binary','name','mime']
        }

        if (!empty($data['url'])) {
            $file = $this->getStoredFileFromPublicUrl($data['url']);
            if (!$file) {
                return response()->json(['message' => 'No se pudo obtener el archivo indicado.'], 422);
            }
            $attachments[] = $file;
        }

        $status = 0;

        try {
            Mail::send([], [], function (\Illuminate\Mail\Message $message) use ($data, $fromEmail, $fromName, $attachments) {
                $message->from($fromEmail, $fromName)
                    ->to($data['email'])
                    ->subject($data['subject'] ?? 'Información solicitada');

                $body = (string) ($data['body'] ?? '');

                // If you want to choose automatically, keep this heuristic or pass a boolean like $data['is_html']
                $isHtml = isset($data['is_html'])
                    ? (bool) $data['is_html']
                    : Str::contains($body, ['<p', '<div', '<br', '</']);

                if ($isHtml) {
                    $message->html($body);
                    // Optional: add a plain-text alternative
                    $message->text(trim(strip_tags($body)));
                } else {
                    $message->text($body);
                }

                foreach ($attachments as $file) {
                    // $file must be ['binary' => ..., 'name' => ..., 'mime' => ...]
                    $message->attachData($file['binary'], $file['name'], ['mime' => $file['mime']]);
                }
            });

            $status = 1;
        } catch (\Throwable $e) {
            \Log::error('No se pudo reenviar correo manual', [
                'error' => $e->getMessage(),
                'email' => $data['email'] ?? null,
            ]);
        }

        $mailer = null;
        if ($status === 1) {
            $mailer = Mailer::create([
                'mail' => $mailSource,
                'email' => $data['email'],
                'asunto' => $data['subject'] ?? 'Información solicitada',
                'mensaje' => $data['body'] ?? '',
                'status' => $status,
                'fecha' => now()->toDateString(),
            ]);
        }

        return response()->json([
            'message' => $status === 1 ? 'Correo reenviado correctamente.' : 'No se pudo enviar el correo.',
            'mail' => ['sent' => $status === 1, 'status' => $status],
            'data' => $mailer ? new MailerResource($mailer) : null,
        ], $status === 1 ? 200 : 202);
    }

    private function decodeBase64Pdf(string $base64): ?array
    {
        $clean = preg_replace('/\s+/', '', $base64);
        if (str_contains($clean, 'base64,')) {
            $clean = explode('base64,', $clean, 2)[1] ?? '';
        }

        $binary = base64_decode($clean, true);
        if ($binary === false) {
            return null;
        }

        return [
            'binary' => $binary,
            'name' => 'documento_' . now()->format('Ymd_His') . '.pdf',
            'mime' => 'application/pdf',
        ];
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

            $mime = $disk->mimeType($relativePath) ?: 'application/octet-stream';
            return [
                'binary' => $disk->get($relativePath),
                'name' => basename($relativePath) ?: 'archivo',
                'mime' => $mime,
            ];
        } catch (Throwable $e) {
            Log::warning('No se pudo obtener archivo para reenviar', [
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
        if (Str::startsWith($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }

        return $relative;
    }
}

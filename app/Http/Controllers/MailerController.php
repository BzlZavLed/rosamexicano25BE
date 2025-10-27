<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMailerRequest;
use App\Http\Requests\UpdateMailerRequest;
use App\Http\Resources\MailerResource;
use App\Models\Mailer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
}

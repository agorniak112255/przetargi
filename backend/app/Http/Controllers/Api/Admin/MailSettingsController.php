<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SmtpTestMail;
use App\Services\MailSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailSettingsController extends Controller
{
    public function __construct(
        private readonly MailSettingsService $settings,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->publicView());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mailer' => ['sometimes', 'string', 'in:smtp,log'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'scheme' => ['nullable', 'string', 'in:smtp,smtps'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'verify_peer' => ['sometimes', 'boolean'],
        ]);

        $this->settings->update($data);

        return response()->json($this->settings->publicView());
    }

    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:255'],
        ]);

        $this->settings->applyToConfig();
        $view = $this->settings->publicView();

        try {
            Mail::to($data['to'])->send(new SmtpTestMail(
                settings: $view,
                recipient: $data['to'],
                sentAt: now()->timezone(config('app.timezone', 'UTC'))->format('d.m.Y H:i'),
            ));
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Wysłano e-mail testowy do '.$data['to'],
            'settings' => $view,
        ]);
    }
}

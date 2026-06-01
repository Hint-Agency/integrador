<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use Illuminate\Http\Request;
use Spatie\WebhookClient\Exceptions\InvalidWebhookSignature;
use Spatie\WebhookClient\WebhookConfig;
use Symfony\Component\HttpFoundation\Response;
use App\WebhookClient\WebhookProcessor;

class WebhookController extends Controller
{
    public function handleWebhook(string $platform, Request $request): Response
    {
        $platformModel = Platform::query()->where('slug', $platform)->first();

        if (! $platformModel) {
            return response()->json([
                'status' => 'error',
                'message' => 'Webhook no recibido: no se encontró la plataforma.',
            ], 400);
        }

        if (! $platformModel->secret_key || ! $platformModel->signature) {
            return response()->json([
                'status' => 'error',
                'message' => 'Webhook no recibido: falta la llave secreta o la configuración de firma.',
            ], 400);
        }

        $config = new WebhookConfig([
            'name' => $platformModel->slug,
            'signing_secret' => $platformModel->secret_key,
            'signature_header_name' => $platformModel->signature,
            'signature_validator' => \App\WebhookClient\WebhookCustomSignatureValidator::class,
            'webhook_profile' => \App\WebhookClient\WebhookCustomProfile::class,
            'webhook_response' => \App\WebhookClient\WebhookCustomResponse::class,
            'webhook_model' => \Spatie\WebhookClient\Models\WebhookCall::class,
            'store_headers' => ['platform' => json_encode($platformModel->only(['id', 'name', 'slug', 'type']))],
            'process_webhook_job' => \App\Jobs\WebhookCustomProcessJob::class,
        ]);

        $processor = new WebhookProcessor($request, $config);
        try {
            $processor->process();
        } catch (InvalidWebhookSignature) {
            return response()->json([
                'status' => 'error',
                'message' => 'Webhook no recibido: firma inválida.',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook recibido.',
        ], 200);
    }
}

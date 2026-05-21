<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\HubSpot\ContactPropertyChangedEvent;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Services\Treble\TrebleStatusWebhookService;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function handleWebhook(Client $client, string $platform, Request $request): Response
    {
        $connection = PlatformConnection::query()
            ->where('client_id', $client->id)
            ->where('platform_type', $platform)
            ->where('active', true)
            ->first();

        if (! $connection) {
            return response()->json([
                'status' => 'error',
                'message' => 'Webhook not received, platform connection not found.',
            ], 404);
        }

        if ($platform !== 'hubspot') {
            return response()->json([
                'status' => 'error',
                'message' => 'Webhook platform not supported in Lite mode.',
            ], 422);
        }

        if (! $this->isValidHashedSignature($request, $connection)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        $payloads = $this->extractPayloads($request->all());
        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            event(new ContactPropertyChangedEvent($client, $connection, $payload));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook received',
        ], 200);
    }

    public function handleTrebleStatusWebhook(
        Client $client,
        Request $request,
        TrebleStatusWebhookService $trebleStatusWebhookService
    ): Response {
        $connection = PlatformConnection::query()
            ->where('client_id', $client->id)
            ->where('platform_type', 'treble')
            ->where('active', true)
            ->first();

        if (! $connection) {
            return response()->json([
                'status' => 'error',
                'message' => 'Treble platform connection not found.',
            ], 404);
        }

        if (! $this->isValidSecretToken($request, $connection)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        if (! $request->isJson()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Treble callback requires application/json payload.',
            ], 422);
        }

        $payload = $request->all();
        $payloads = $this->extractPayloads($payload);
        $results = [];
        \Log::info('Processing Treble status webhook callback', ['payload' => $payload, 'extracted_payloads' => $payloads]);
        foreach ($payloads as $item) {
            if (! is_array($item)) {
                continue;
            }

            $result = $trebleStatusWebhookService->process($client, $connection, $item);
            if (! ($result['success'] ?? false)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $result['message'] ?? 'Treble callback processing failed.',
                ], (int) ($result['status_code'] ?? 422));
            }

            $results[] = $result;
        }

        return response()->json([
            'status' => 'success',
            'processed' => count($results),
            'results' => $results,
        ], 200);
    }

    private function isValidHashedSignature(Request $request, PlatformConnection $connection): bool
    {
        $headerName = (string) ($connection->signature_header ?? '');
        $secret = (string) ($connection->webhook_secret ?? '');

        if ($headerName === '' || $secret === '') {
            return false;
        }

        $signature = $request->header($headerName) ?? $request->query($headerName);
        if (! is_string($signature) || trim($signature) === '') {
            return false;
        }

        $computedSignature = hash('sha256', $secret . $request->getContent());

        return hash_equals($signature, $computedSignature);
    }

    private function isValidSecretToken(Request $request, PlatformConnection $connection): bool
    {
        $headerName = (string) ($connection->signature_header ?? '');
        $secret = (string) ($connection->webhook_secret ?? '');

        if ($headerName === '' || $secret === '') {
            return false;
        }

        $providedSecret = $request->header($headerName) ?? $request->query($headerName);

        return is_string($providedSecret)
            && trim($providedSecret) !== ''
            && hash_equals($secret, trim($providedSecret));
    }

    private function extractPayloads(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['data', 'items', 'results', 'objects', 'records', 'entities'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [$payload];
    }
}

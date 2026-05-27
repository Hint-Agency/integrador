<?php

namespace App\Services\Hubspot;

use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class HubspotFilePropertyService
{
    public function hydrateFileProperties(Event $event, array $payload): array
    {
        $fileProperties = $event->properties()
            ->where('type', 'file')
            ->get(['key', 'name']);

        if ($fileProperties->isEmpty()) {
            return $payload;
        }

        $attachments = [];
        $errors = [];

        foreach ($fileProperties as $property) {
            $source = Arr::get($payload, $property->key);
            $attachment = $this->downloadFileAttachment($source, $property->key);

            if ($attachment['success'] ?? false) {
                $attachments[$property->key] = $attachment['file'];
            } elseif (($attachment['skipped'] ?? false) === false) {
                $errors[] = [
                    'property_key' => $property->key,
                    ...($attachment['error'] ?? []),
                ];
            }
        }

        if (! empty($attachments)) {
            $payload['_file_attachments'] = $attachments;
        }

        if (! empty($errors)) {
            $payload['_file_attachment_errors'] = $errors;
        }

        return $payload;
    }

    public function normalizeFileValueForOdoo(mixed $source, string $fallbackName = 'file'): ?array
    {
        if (is_array($source)) {
            $base64 = Arr::get($source, 'base64')
                ?? Arr::get($source, 'content_base64')
                ?? Arr::get($source, 'content');
            $name = Arr::get($source, 'name')
                ?? Arr::get($source, 'filename')
                ?? $fallbackName;

            if (is_scalar($base64) && trim((string) $base64) !== '' && is_scalar($name)) {
                return [
                    'name' => (string) $name,
                    'base64' => (string) $base64,
                    'mime_type' => Arr::get($source, 'mime_type') ?? Arr::get($source, 'content_type'),
                    'source_url' => Arr::get($source, 'source_url'),
                    'size_bytes' => Arr::get($source, 'size_bytes'),
                    'hubspot_file_id' => Arr::get($source, 'hubspot_file_id'),
                ];
            }
        }

        $attachment = $this->downloadFileAttachment($source, $fallbackName);
        if (! ($attachment['success'] ?? false)) {
            return null;
        }

        return [
            'name' => Arr::get($attachment, 'file.filename', $fallbackName),
            'base64' => Arr::get($attachment, 'file.content_base64'),
            'mime_type' => Arr::get($attachment, 'file.mime_type'),
            'source_url' => Arr::get($attachment, 'file.source_url'),
            'size_bytes' => Arr::get($attachment, 'file.size_bytes'),
            'hubspot_file_id' => Arr::get($attachment, 'file.hubspot_file_id'),
        ];
    }

    private function downloadFileAttachment(mixed $source, string $fallbackName): array
    {
        if (! is_string($source) || $source === '') {
            return ['success' => false, 'skipped' => true];
        }

        if (preg_match('/^\d+$/', trim($source)) === 1) {
            return $this->downloadHubspotFileIdAttachment(trim($source), $fallbackName);
        }

        if (! str_starts_with($source, 'http://') && ! str_starts_with($source, 'https://')) {
            return ['success' => false, 'skipped' => true];
        }

        return $this->downloadUrlAttachment($source, $fallbackName);
    }

    private function downloadHubspotFileIdAttachment(string $fileId, string $fallbackName): array
    {
        $token = config('hubspot.access_token');
        if (! is_string($token) || trim($token) === '') {
            return [
                'success' => false,
                'skipped' => false,
                'error' => [
                    'file_id' => $fileId,
                    'error' => 'HubSpot access token is not configured.',
                ],
            ];
        }

        try {
            $baseUrl = rtrim((string) config('hubspot.base_url', 'https://api.hubapi.com'), '/');
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->get($baseUrl.'/files/v3/files/'.$fileId.'/signed-url');

            if (! $response->ok()) {
                return [
                    'success' => false,
                    'skipped' => false,
                    'error' => [
                        'file_id' => $fileId,
                        'status_code' => $response->status(),
                        'response' => $response->json() ?? $response->body(),
                    ],
                ];
            }

            $data = $response->json() ?? [];
            $url = Arr::get($data, 'url');
            if (! is_string($url) || trim($url) === '') {
                return [
                    'success' => false,
                    'skipped' => false,
                    'error' => [
                        'file_id' => $fileId,
                        'error' => 'HubSpot signed URL response did not include a URL.',
                    ],
                ];
            }

            $filename = $this->filenameFromHubspotSignedUrlResponse($data, $fallbackName);
            $download = $this->downloadUrlAttachment($url, $filename, true);
            if (($download['success'] ?? false) && isset($download['file'])) {
                $download['file']['hubspot_file_id'] = $fileId;
            }

            return $download;
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'skipped' => false,
                'error' => [
                    'file_id' => $fileId,
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function downloadUrlAttachment(string $source, string $fallbackName, bool $preferFallbackName = false): array
    {
        try {
            $response = Http::timeout(15)->get($source);
            if (! $response->ok()) {
                return [
                    'success' => false,
                    'skipped' => false,
                    'error' => [
                        'url' => $source,
                        'status_code' => $response->status(),
                    ],
                ];
            }

            $binary = $response->body();
            $filename = $preferFallbackName
                ? $fallbackName
                : basename(parse_url($source, PHP_URL_PATH) ?: $fallbackName);

            return [
                'success' => true,
                'file' => [
                    'filename' => $filename,
                    'mime_type' => $response->header('content-type'),
                    'content_base64' => base64_encode($binary),
                    'size_bytes' => strlen($binary),
                    'source_url' => $source,
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'skipped' => false,
                'error' => [
                    'url' => $source,
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function filenameFromHubspotSignedUrlResponse(array $data, string $fallbackName): string
    {
        $name = Arr::get($data, 'name');
        if (! is_scalar($name) || trim((string) $name) === '') {
            return $fallbackName;
        }

        $filename = trim((string) $name);
        $extension = Arr::get($data, 'extension');
        if (is_scalar($extension) && trim((string) $extension) !== '') {
            $extension = ltrim(trim((string) $extension), '.');
            if ($extension !== '' && ! str_ends_with(strtolower($filename), '.'.strtolower($extension))) {
                $filename .= '.'.$extension;
            }
        }

        return $filename;
    }
}

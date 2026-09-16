<?php

namespace App\Services\Generic;

use App\Helpers\GuzzleHelper;
use App\Services\RateLimitService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class GenericHttpAdapter
{
    public function __construct(
        protected RateLimitService $rateLimitService
    ) {}

    public function send(
        string $platform,
        string $endpoint,
        string $method,
        array $headers,
        array $query,
        array $body,
        int $timeout,
        array $retryPolicy
    ): array {
        $this->assertDomainAllowed($endpoint);

        $client = new Client([
            'timeout' => $timeout,
            'http_errors' => false,
        ]);

        $attempt = 1;
        $maxAttempts = (int) ($retryPolicy['max_attempts'] ?? 1);

        while (true) {
            $this->rateLimitService->throttle($platform, $endpoint);
            $start = microtime(true);

            try {
                $response = $client->request($method, $endpoint, [
                    'headers' => $headers,
                    'query' => $query,
                    'json' => $body,
                ]);

                $latency = (int) round((microtime(true) - $start) * 1000);
                $status = $response->getStatusCode();
                $bodyContents = (string) $response->getBody();
                $decoded = json_decode($bodyContents, true);

                $data = is_array($decoded) ? $decoded : ['raw' => $bodyContents];

                $normalized = $this->normalizeResponse(
                    $status,
                    $endpoint,
                    $method,
                    $data,
                    $latency,
                    $attempt,
                    $response->getHeaderLine('x-request-id') ?: $response->getHeaderLine('request-id')
                );

                if ($normalized['retryable'] && $attempt < $maxAttempts) {
                    $retryAfter = $this->getRetryAfterSeconds($response->getHeaderLine('retry-after'));
                    $backoffMs = $this->rateLimitService->computeBackoffMs($attempt, $retryAfter);
                    $this->rateLimitService->logBackoff($platform, $endpoint, $status, $retryAfter, $attempt, $backoffMs);

                    usleep($backoffMs * 1000);
                    $attempt++;

                    continue;
                }

                return $normalized;
            } catch (ConnectException $exception) {
                $latency = (int) round((microtime(true) - $start) * 1000);
                $error = $this->buildErrorPayload($exception);

                $normalized = $this->normalizeResponse(
                    0,
                    $endpoint,
                    $method,
                    [],
                    $latency,
                    $attempt,
                    null,
                    $error,
                    true
                );

                if ($attempt < $maxAttempts) {
                    $backoffMs = $this->rateLimitService->computeBackoffMs($attempt, null);
                    $this->rateLimitService->logBackoff($platform, $endpoint, 0, null, $attempt, $backoffMs);
                    usleep($backoffMs * 1000);
                    $attempt++;

                    continue;
                }

                return $normalized;
            } catch (RequestException $exception) {
                $latency = (int) round((microtime(true) - $start) * 1000);
                $status = $exception->getResponse()?->getStatusCode() ?? 0;
                $error = $this->buildErrorPayload($exception);

                $normalized = $this->normalizeResponse(
                    $status,
                    $endpoint,
                    $method,
                    [],
                    $latency,
                    $attempt,
                    null,
                    $error,
                    $this->isRetryable($status)
                );

                if ($normalized['retryable'] && $attempt < $maxAttempts) {
                    $retryAfter = $this->getRetryAfterSeconds($exception->getResponse()?->getHeaderLine('retry-after'));
                    $backoffMs = $this->rateLimitService->computeBackoffMs($attempt, $retryAfter);
                    $this->rateLimitService->logBackoff($platform, $endpoint, $status, $retryAfter, $attempt, $backoffMs);
                    usleep($backoffMs * 1000);
                    $attempt++;

                    continue;
                }

                return $normalized;
            }
        }
    }

    private function normalizeResponse(
        int $status,
        string $endpoint,
        string $method,
        array $data,
        int $latency,
        int $attempt,
        ?string $requestId,
        ?array $error = null,
        ?bool $retryable = null
    ): array {
        $success = $status >= 200 && $status < 300;
        $retryable = $retryable ?? $this->isRetryable($status);
        $error ??= $success ? null : $this->extractApiError($data, $status);

        return [
            'success' => $success,
            'status_code' => $status,
            'retryable' => $retryable,
            'request_id' => $requestId,
            'external_id' => $this->extractExternalId($data),
            'latency_ms' => $latency,
            'attempt' => $attempt,
            'endpoint' => $endpoint,
            'method' => Str::upper($method),
            'data' => $data,
            'error' => $error ?? [
                'code' => null,
                'message' => null,
                'details' => null,
            ],
        ];
    }

    private function extractApiError(array $data, int $status): array
    {
        $nestedError = Arr::get($data, 'error');
        $code = Arr::get($data, 'code')
            ?? (is_array($nestedError) ? Arr::get($nestedError, 'code') : null);
        $message = Arr::get($data, 'message')
            ?? Arr::get($data, 'detail')
            ?? Arr::get($data, 'title')
            ?? (is_string($nestedError) ? $nestedError : null)
            ?? 'External API request failed with HTTP '.$status.'.';
        $details = Arr::get($data, 'errors')
            ?? (is_array($nestedError) ? Arr::get($nestedError, 'details') : null)
            ?? $data;

        return [
            'code' => is_scalar($code) ? (string) $code : null,
            'message' => is_scalar($message) ? (string) $message : 'External API request failed.',
            'details' => is_string($details)
                ? $details
                : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function extractExternalId(array $data): ?string
    {
        $candidate = Arr::get($data, 'external_id')
            ?? Arr::get($data, 'id')
            ?? Arr::get($data, 'data.id');

        return $candidate ? (string) $candidate : null;
    }

    private function isRetryable(int $status): bool
    {
        if ($status === 0) {
            return true;
        }

        if ($status === 429) {
            return true;
        }

        return $status >= 500 && $status <= 599;
    }

    private function buildErrorPayload(\Exception $exception): array
    {
        if ($exception instanceof RequestException) {
            $message = GuzzleHelper::getCompleteErrorMessage($exception);
            $details = GuzzleHelper::getCompleteResponseBody($exception);
        } else {
            $message = $exception->getMessage();
            $details = null;
        }

        return [
            'code' => $exception->getCode() ?: null,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function assertDomainAllowed(string $endpoint): void
    {
        $allowed = config('generic-platforms.policy.allowed_domains', []);
        if (empty($allowed)) {
            return;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        if (! $host) {
            return;
        }

        foreach ($allowed as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return;
            }
        }

        throw new \RuntimeException('Endpoint domain not allowed for generic platform requests.');
    }

    private function getRetryAfterSeconds(?string $retryAfterHeader): ?int
    {
        if (! $retryAfterHeader) {
            return null;
        }

        if (is_numeric($retryAfterHeader)) {
            return (int) $retryAfterHeader;
        }

        $timestamp = strtotime($retryAfterHeader);
        if ($timestamp === false) {
            return null;
        }

        $diff = $timestamp - time();

        return $diff > 0 ? $diff : null;
    }
}

<?php

namespace Tests\Unit;

use App\Services\Generic\GenericHttpAdapter;
use App\Services\RateLimitService;
use ReflectionMethod;
use Tests\TestCase;

class GenericHttpAdapterTest extends TestCase
{
    public function test_it_extracts_structured_validation_details_from_422_response(): void
    {
        $adapter = new GenericHttpAdapter($this->createMock(RateLimitService::class));
        $normalize = new ReflectionMethod($adapter, 'normalizeResponse');

        $response = $normalize->invoke(
            $adapter,
            422,
            'https://api.example.com/api/quotes',
            'POST',
            [
                'code' => 'price_mismatch',
                'title' => 'Quote validation failed.',
                'errors' => [
                    'partidas.0.precioUnitario' => ['The price differs from SAE.'],
                ],
            ],
            25,
            1,
            'req_422'
        );

        $this->assertFalse($response['success']);
        $this->assertFalse($response['retryable']);
        $this->assertSame(422, $response['status_code']);
        $this->assertSame('price_mismatch', $response['error']['code']);
        $this->assertSame('Quote validation failed.', $response['error']['message']);
        $this->assertJson($response['error']['details']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Services\Hubspot\HubspotFilePropertyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubspotFilePropertyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_downloads_and_attaches_file_properties(): void
    {
        Http::fake([
            'https://files.example.com/*' => Http::response('file-content', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'File Event',
            'event_type_id' => 'object.updated',
            'type' => 'webhook',
            'subscription_type' => 'object.propertyChange',
            'active' => true,
        ]);

        $property = Property::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Attachment',
            'key' => 'document_url',
            'type' => 'file',
            'active' => true,
        ]);

        $event->properties()->sync([$property->id]);

        $service = app(HubspotFilePropertyService::class);
        $result = $service->hydrateFileProperties($event, [
            'document_url' => 'https://files.example.com/file-1.pdf',
        ]);

        $this->assertArrayHasKey('_file_attachments', $result);
        $this->assertArrayHasKey('document_url', $result['_file_attachments']);
        $this->assertSame('application/pdf', $result['_file_attachments']['document_url']['mime_type']);
        $this->assertNotEmpty($result['_file_attachments']['document_url']['content_base64']);
    }

    public function test_it_normalizes_file_url_for_odoo_binary_fields(): void
    {
        Http::fake([
            'https://files.example.com/*' => Http::response('file-content', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $service = app(HubspotFilePropertyService::class);
        $result = $service->normalizeFileValueForOdoo(
            'https://files.example.com/constancia.pdf',
            'constancia.pdf'
        );

        $this->assertSame('constancia.pdf', $result['name']);
        $this->assertSame(base64_encode('file-content'), $result['base64']);
        $this->assertSame('application/pdf', $result['mime_type']);
    }

    public function test_it_resolves_hubspot_file_id_to_signed_url_for_odoo_binary_fields(): void
    {
        config([
            'hubspot.access_token' => 'token_123',
            'hubspot.base_url' => 'https://api.hubapi.test',
        ]);

        Http::fake([
            'https://api.hubapi.test/files/v3/files/268543396066/signed-url' => Http::response([
                'name' => 'constancia fiscal',
                'extension' => 'pdf',
                'url' => 'https://signed-files.example.com/constancia',
            ], 200),
            'https://signed-files.example.com/constancia' => Http::response('file-content', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $service = app(HubspotFilePropertyService::class);
        $result = $service->normalizeFileValueForOdoo('268543396066', 'constancia.pdf');

        $this->assertSame('constancia fiscal.pdf', $result['name']);
        $this->assertSame(base64_encode('file-content'), $result['base64']);
        $this->assertSame('application/pdf', $result['mime_type']);
        $this->assertSame('268543396066', $result['hubspot_file_id']);
    }
}

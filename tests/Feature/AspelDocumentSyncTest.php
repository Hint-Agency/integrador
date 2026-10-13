<?php

namespace Tests\Feature;

use App\Models\Config;
use App\Models\Event;
use App\Models\EventHttpConfig;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\Aspel\AspelService;
use App\Services\Generic\AuthStrategyResolver;
use App\Services\Generic\GenericHttpAdapter;
use App\Services\Hubspot\HubspotService;
use Database\Seeders\AspelDocumentFlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AspelDocumentSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_polling_updates_the_linked_deal_adds_note_and_persists_nested_cursor(): void
    {
        config()->set('hubspot.access_token', 'token_123');
        config()->set('hubspot.base_url', 'https://api.hubapi.test');

        [$hubspot, $aspel, $pollingEvent, $syncEvent, $record] = $this->context('invoices', 'amount', 'clave_factura');

        Http::fake(function (Request $request) {
            if ($request->method() === 'PATCH' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/deals/123456789') {
                return Http::response(['id' => '123456789', 'properties' => $request->data()['properties'] ?? []], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.hubapi.test/crm/v3/objects/notes') {
                return Http::response(['id' => 'NOTE-1'], 201);
            }

            if ($request->method() === 'PUT') {
                return Http::response([], 200);
            }

            return Http::response(['error' => 'Unexpected HubSpot request'], 500);
        });

        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->andReturn($this->httpResponse([
            'items' => [[
                'tipoDocumento' => 'F',
                'cveDoc' => '0000000002',
                'versionSinc' => '2026-10-06T18:45:40.197',
            ]],
            'hasMore' => false,
            'nextCursor' => [
                'sinceTs' => '2026-10-06T18:45:40.197',
                'sinceClave' => '0000000002',
            ],
        ], '/api/invoices/changes'))->ordered();
        $adapter->shouldReceive('send')->once()->andReturn($this->httpResponse(
            $this->documentDetail('F', '0000000002', 3.0),
            '/api/invoices/0000000002'
        ))->ordered();

        Config::query()->create([
            'key' => 'aspel.invoices.cursor.'.$pollingEvent->id.'.since_ts',
            'value' => ['value' => '2026-10-06T00:00:00'],
            'is_encrypted' => false,
        ]);
        Config::query()->create([
            'key' => 'aspel.invoices.cursor.'.$pollingEvent->id.'.since_clave',
            'value' => ['value' => ''],
            'is_encrypted' => false,
        ]);

        $service = new AspelService($aspel, app(AuthStrategyResolver::class), $pollingEvent, $record);
        $result = $service->getUpdatedInvoices([], $adapter);

        $this->assertTrue($result['success'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('0000000002', data_get(
            Config::query()->where('key', 'aspel.invoices.cursor.'.$pollingEvent->id.'.since_clave')->first()?->value,
            'value'
        ));
        $this->assertDatabaseHas('event_idempotency_keys', [
            'event_id' => $pollingEvent->id,
            'status' => 'success',
        ]);

        Http::assertSent(function (Request $request): bool {
            $properties = $request->data()['properties'] ?? [];

            return $request->method() === 'PATCH'
                && $request->url() === 'https://api.hubapi.test/crm/v3/objects/deals/123456789'
                && ($properties['amount'] ?? null) === 3.0
                && ($properties['clave_factura'] ?? null) === '0000000002';
        });
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.hubapi.test/crm/v3/objects/notes'
                && str_contains((string) data_get($request->data(), 'properties.hs_note_body'), 'Factura sincronizada')
                && str_contains((string) data_get($request->data(), 'properties.hs_note_body'), '0000000002');
        });
    }

    public function test_document_sync_rejects_multiple_deal_ids_without_calling_hubspot(): void
    {
        Http::fake();
        [$hubspot, , , $syncEvent] = $this->context('remissions', 'amount_remision', 'clave_remision');
        $record = Record::query()->create([
            'event_id' => $syncEvent->id,
            'event_type' => 'object.updated',
            'status' => 'processing',
            'payload' => [],
            'message' => 'processing',
        ]);

        $service = app()->make(HubspotService::class, [
            'platform' => $hubspot,
            'event' => $syncEvent,
            'record' => $record,
        ]);
        $detail = $this->documentDetail('R', '0000177996', 3.0);
        $detail['hubspotDealId'] = null;
        $detail['hubspotDealIds'] = ['123', '456'];
        $detail['origenMultiple'] = true;

        $result = $service->syncAspelDocumentToHubspotDeal([
            'aspel_detail' => $detail,
            'source_event_id' => data_get($syncEvent->meta, 'mapping_event_id'),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['status']);
        $this->assertSame('multiple_hubspot_deal_ids', data_get($result, 'data.warning_reason'));
        Http::assertNothingSent();
    }

    public function test_document_flow_seeder_creates_both_polling_flows_and_configurable_mappings(): void
    {
        $hubspot = Platform::query()->create([
            'name' => 'HubSpot', 'slug' => 'hubspot', 'type' => 'hubspot', 'active' => true,
        ]);
        $aspel = Platform::query()->create([
            'name' => 'ASPEL', 'slug' => 'aspel', 'type' => 'generic',
            'settings' => ['service_driver' => 'aspel'], 'active' => true,
        ]);
        $template = Event::query()->create([
            'platform_id' => $aspel->id,
            'name' => 'ASPEL template',
            'event_type_id' => 'generic.external.call',
            'method_name' => 'getUpdatedContacts',
            'type' => 'schedule',
            'active' => true,
        ]);
        EventHttpConfig::query()->create([
            'event_id' => $template->id,
            'method' => 'GET',
            'base_url' => 'https://sae.test',
            'path' => 'api/contacts/changes',
            'active' => true,
        ]);

        $this->seed(AspelDocumentFlowSeeder::class);
        $this->seed(AspelDocumentFlowSeeder::class);

        $remissions = Event::query()->where('method_name', 'getUpdatedRemissions')->firstOrFail();
        $invoices = Event::query()->where('method_name', 'getUpdatedInvoices')->firstOrFail();

        $this->assertSame('api/remissions/changes', $remissions->httpConfig?->path);
        $this->assertSame('api/invoices/changes', $invoices->httpConfig?->path);
        $this->assertSame('*/5 * * * *', $remissions->schedule_expression);
        $this->assertSame('syncAspelDocumentToHubspotDeal', $remissions->to_event?->method_name);
        $this->assertSame($remissions->id, data_get($remissions->to_event?->meta, 'mapping_event_id'));
        $this->assertCount(2, $remissions->propertyRelationships);
        $this->assertCount(2, $invoices->propertyRelationships);
        $this->assertSame(2, Event::query()->where('method_name', 'syncAspelDocumentToHubspotDeal')->count());
        $this->assertSame(1, Property::query()->where('platform_id', $hubspot->id)->where('key', 'amount_remision')->count());
    }

    private function context(string $scope, string $amountTarget, string $keyTarget): array
    {
        $hubspot = Platform::query()->create([
            'name' => 'HubSpot', 'slug' => 'hubspot', 'type' => 'hubspot',
            'credentials' => ['access_token' => 'token_123'], 'active' => true,
        ]);
        $aspel = Platform::query()->create([
            'name' => 'ASPEL', 'slug' => 'aspel', 'type' => 'generic',
            'credentials' => ['api_key' => 'aspel-token'],
            'settings' => ['service_driver' => 'aspel'], 'active' => true,
        ]);

        $syncEvent = Event::query()->create([
            'platform_id' => $hubspot->id,
            'name' => 'Sync '.$scope,
            'event_type_id' => 'object.updated',
            'method_name' => 'syncAspelDocumentToHubspotDeal',
            'type' => 'webhook',
            'active' => true,
        ]);
        $pollingEvent = Event::query()->create([
            'platform_id' => $aspel->id,
            'name' => 'Polling '.$scope,
            'event_type_id' => 'generic.external.call',
            'method_name' => $scope === 'invoices' ? 'getUpdatedInvoices' : 'getUpdatedRemissions',
            'to_event_id' => $syncEvent->id,
            'type' => 'schedule',
            'meta' => ['take' => 200, 'overlap_minutes' => 15, 'initial_lookback_hours' => 24],
            'active' => true,
        ]);
        $syncEvent->update(['meta' => [
            'object_type' => 'deals',
            'mapping_event_id' => $pollingEvent->id,
        ]]);
        EventHttpConfig::query()->create([
            'event_id' => $pollingEvent->id,
            'method' => 'GET',
            'base_url' => 'https://api.example.com',
            'path' => '/api/'.$scope.'/changes',
            'active' => true,
        ]);

        foreach ([['cveDoc', $keyTarget, 'string'], ['importe', $amountTarget, 'number']] as [$sourceKey, $targetKey, $type]) {
            $source = Property::query()->create([
                'platform_id' => $aspel->id, 'name' => $sourceKey, 'key' => $sourceKey,
                'type' => $type, 'active' => true,
            ]);
            $target = Property::query()->create([
                'platform_id' => $hubspot->id, 'name' => $targetKey, 'key' => $targetKey,
                'type' => $type, 'active' => true, 'meta' => ['hubspot_object_type' => 'deals'],
            ]);
            PropertyRelationship::query()->create([
                'event_id' => $pollingEvent->id,
                'property_id' => $source->id,
                'related_property_id' => $target->id,
                'mapping_key' => $sourceKey,
                'active' => true,
                'meta' => $type === 'number' ? ['transform' => 'decimal'] : [],
            ]);
        }

        $record = Record::query()->create([
            'event_id' => $pollingEvent->id,
            'event_type' => 'generic.external.call',
            'status' => 'processing',
            'payload' => [],
            'message' => 'processing',
        ]);

        return [$hubspot, $aspel, $pollingEvent, $syncEvent->fresh(), $record];
    }

    private function documentDetail(string $type, string $cveDoc, float $amount): array
    {
        return [
            'tipoDocumento' => $type,
            'cveDoc' => $cveDoc,
            'serie' => '',
            'folio' => 2,
            'claveCliente' => '58331',
            'status' => 'E',
            'importe' => $amount,
            'fechaDocumento' => '2023-05-15T00:00:00',
            'versionSinc' => '2026-10-06T18:45:40.197',
            'claveCotizacion' => '0000012709',
            'clavesCotizacion' => ['0000012709'],
            'hubspotDealId' => '123456789',
            'hubspotDealIds' => ['123456789'],
            'origenMultiple' => false,
            'pdfDisponible' => false,
            'pdfEstado' => 'fuente_no_configurada',
        ];
    }

    private function httpResponse(array $data, string $endpoint): array
    {
        return [
            'success' => true,
            'status_code' => 200,
            'retryable' => false,
            'request_id' => 'req-1',
            'external_id' => null,
            'latency_ms' => 5,
            'attempt' => 1,
            'endpoint' => 'https://api.example.com'.$endpoint,
            'method' => 'GET',
            'data' => $data,
            'error' => ['code' => null, 'message' => null, 'details' => null],
        ];
    }
}

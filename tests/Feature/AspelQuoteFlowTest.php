<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventHttpConfig;
use App\Models\Platform;
use App\Services\EventTriggerService;
use App\Services\Generic\GenericHttpAdapter;
use App\Services\Hubspot\AspelQuotePreparationService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\Hubspot\HubspotService;
use Database\Seeders\AspelQuoteFlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AspelQuoteFlowTest extends TestCase
{
    use RefreshDatabase;

    private function flow(): array
    {
        $hubspot = Platform::create(['name' => 'HubSpot', 'slug' => 'hubspot', 'type' => 'hubspot', 'active' => true]);
        $aspel = Platform::create(['name' => 'ASPEL', 'slug' => 'aspel', 'type' => 'generic',
            'settings' => ['service_driver' => 'aspel'], 'credentials' => ['api_key' => 'test'], 'active' => true]);
        foreach ([['createQuote', 'POST', 'api/quotes'], ['syncLineItemWarehouseInventory', 'GET', 'api/almacenes/{sku}']] as [$method, $verb, $path]) {
            $event = Event::create(['platform_id' => $aspel->id, 'name' => $method, 'method_name' => $method,
                'event_type_id' => 'generic.external.call', 'type' => 'node', 'active' => true]);
            EventHttpConfig::create(['event_id' => $event->id, 'method' => $verb, 'base_url' => 'https://sae.test',
                'path' => $path, 'auth_mode' => 'bearer_api_key', 'active' => true]);
        }
        $this->seed(AspelQuoteFlowSeeder::class);
        return [$hubspot, Event::where('method_name', 'prepareAspelQuote')->firstOrFail()];
    }

    private function api(
        array $contactChanges = [],
        string $status = '',
        array $quoteIds = ['Q1'],
        array $quoteOverrides = []
    ): HubspotApiServiceRefactored
    {
        $api = Mockery::mock(HubspotApiServiceRefactored::class);
        $api->shouldReceive('getObjectAssociations')->with('deals', 'D1', 'quotes')
            ->andReturn(['success' => true, 'data' => ['results' => array_map(
                static fn (string $id): array => ['toObjectId' => $id], $quoteIds
            )]]);
        $api->shouldReceive('getObjectAssociations')->with('deals', 'D1', 'contacts')
            ->andReturn(['success' => true, 'data' => ['results' => [['toObjectId' => 'C1',
                'associationTypes' => [['category' => 'USER_DEFINED', 'typeId' => 1]]]]]]);
        $api->shouldReceive('getObjectAssociations')->with('quotes', Mockery::on(
            static fn (string $id): bool => in_array($id, $quoteIds, true)
        ), 'line_items')
            ->andReturn(['success' => true, 'data' => ['results' => [['toObjectId' => 'L1']]]]);
        $api->shouldReceive('getObject')->andReturnUsing(static function ($object, $id, $properties) use ($contactChanges, $status, $quoteOverrides): array {
            $values = match ($object) {
                'quotes' => array_merge(
                    ['hs_sender_email' => 'vendedor@empresa.com', 'sync_status_aspel' => $status],
                    $quoteOverrides[$id] ?? []
                ),
                'contacts' => array_merge(['clave' => '58329', 'lista_de_precios' => '4', 'firstname' => 'Ana', 'lastname' => 'Perez',
                    'calle_envio' => 'Calle real', 'num_int_envio' => '2', 'num_ext_envio' => '100',
                    'poblacion_envio' => 'Merida', 'referencia_envio' => 'Porton azul'], $contactChanges),
                'line_items' => ['clave' => '10020004', 'quantity' => '1', 'almacen_id' => '4', 'prices_list' => '4', 'price' => '125.5'],
                default => [],
            };
            return ['success' => true, 'data' => ['id' => $id, 'properties' => $values]];
        });
        $api->shouldReceive('updateObject')->andReturn(['success' => true, 'data' => []]);
        $api->shouldReceive('addNoteToObject')->andReturn(['success' => true, 'data' => ['id' => 'NOTE1']]);
        return $api;
    }

    private function pricing(int $list = 4, bool $tax = false): void
    {
        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldReceive('send')->once()->withArgs(static fn ($platform, $endpoint, $method, $headers, $query, $body): bool =>
            $endpoint === 'https://sae.test/api/almacenes/10020004' && $method === 'GET'
            && $query === ['claveCliente' => '58329', 'cveAlmacen' => '4'] && $body === [])
            ->andReturn(['success' => true, 'data' => [['cveArt' => '10020004', 'cveAlm' => '4', 'listaPrecio' => $list,
                'precio' => 125.5, 'incluyeImpuestos' => $tax, 'exist' => 10]]]);
        $this->app->instance(GenericHttpAdapter::class, $adapter);
    }

    public function test_seeded_flow_and_stage_conditions_are_idempotent(): void
    {
        [$hubspot, $prepare] = $this->flow();
        $count = Event::count();
        $mappings = $prepare->propertyRelationships()->count();
        $this->seed(AspelQuoteFlowSeeder::class);
        $this->assertSame($count, Event::count());
        $this->assertSame($mappings, $prepare->propertyRelationships()->count());
        $root = Event::where('event_type_id', 'deal.propertyChange')->firstOrFail();
        foreach (['1316578321', '1160755464', '1316741897', '1316738978'] as $stage) {
            $this->assertTrue(app(EventTriggerService::class)->evaluateEventTriggers($root, ['propertyName' => 'dealstage', 'propertyValue' => $stage]));
        }
        $this->assertFalse(app(EventTriggerService::class)->evaluateEventTriggers($root, ['propertyName' => 'dealstage', 'propertyValue' => 'other']));
        $this->assertFalse(app(EventTriggerService::class)->evaluateEventTriggers($root, ['propertyName' => 'sync_status_aspel', 'propertyValue' => 'success']));
        $this->assertSame('createQuote', $prepare->to_event->method_name);
        $this->assertSame('syncQuoteExecutionResponse', $prepare->to_event->to_event->method_name);
        $mappingContext = app(\App\Services\EventMappingContextResolver::class)->resolve($prepare);
        $this->assertSame($prepare->to_event->platform_id, $mappingContext['target_platform_id']);
    }

    public function test_preparation_builds_mapped_quote_and_validates_current_prices_without_assumed_iva(): void
    {
        [, $event] = $this->flow();
        $this->pricing();
        $result = app(AspelQuotePreparationService::class)->prepare($event, ['objectId' => 'D1'], $this->api());
        $this->assertTrue($result['success']);
        $body = $result['data']['output_payload'];
        $this->assertSame('Q1', $body['hubspotQuoteId']);
        $this->assertSame('58329', $body['claveCliente']);
        $this->assertSame('Ana Perez', $body['direccionEnvio']['nombre']);
        $this->assertSame(125.5, $body['partidas'][0]['precioUnitario']);
        $this->assertArrayNotHasKey('iva', $body['partidas'][0]);
        $this->assertSame('{}', json_encode($body['camposLibres']));
    }

    public function test_missing_address_blocks_emission_and_notes_deal_without_defaulting_sn(): void
    {
        [, $event] = $this->flow();
        $this->pricing();
        $result = app(AspelQuotePreparationService::class)->prepare($event, ['objectId' => 'D1'], $this->api(['num_int_envio' => '']));
        $this->assertSame('warning', $result['status']);
        $this->assertSame([], $result['data']['output_payload']);
        $this->assertArrayHasKey('direccionEnvio.numeroInterior', $result['data']['context']['errors']);
        $this->assertSame('NOTE1', $result['data']['hubspot_note']['data']['id']);
    }

    public function test_list_mismatch_blocks_emission_without_adjusting_prices(): void
    {
        [, $event] = $this->flow();
        $this->pricing(3);
        $result = app(AspelQuotePreparationService::class)->prepare($event, ['objectId' => 'D1'], $this->api());
        $this->assertSame('price_or_list_mismatch', $result['data']['reason']);
        $this->assertSame([], $result['data']['output_payload']);
    }

    public function test_tax_included_prices_and_failed_quotes_cannot_be_emitted(): void
    {
        [, $event] = $this->flow();
        $this->pricing(4, true);
        $result = app(AspelQuotePreparationService::class)->prepare($event, ['objectId' => 'D1'], $this->api());
        $this->assertSame('tax_included_price', $result['data']['reason']);
        $result = app(AspelQuotePreparationService::class)->prepare($event, ['objectId' => 'D1'], $this->api([], 'error'));
        $this->assertSame('missing_or_ambiguous_quote', $result['data']['reason']);
    }

    public function test_new_quote_copy_with_inherited_error_is_not_confused_with_previously_attempted_quote(): void
    {
        [, $event] = $this->flow();
        $this->pricing();
        \App\Models\Record::create([
            'event_id' => $event->id,
            'event_type' => 'object.updated',
            'status' => 'warning',
            'payload' => ['objectId' => 'D1'],
            'details' => ['status_update' => ['data' => ['id' => 'Q1']]],
        ]);
        $api = $this->api([], '', ['Q1', 'Q2'], [
            'Q1' => ['sync_status_aspel' => 'error', 'hs_createdate' => '2026-09-23T20:01:52Z'],
            'Q2' => ['sync_status_aspel' => 'error', 'hs_createdate' => '2026-09-23T20:32:53Z'],
        ]);

        $result = app(AspelQuotePreparationService::class)->prepare($event, ['objectId' => 'D1'], $api);

        $this->assertTrue($result['success']);
        $this->assertSame('Q2', $result['data']['output_payload']['hubspotQuoteId']);
    }

    public function test_writeback_persists_document_identifiers_and_existing_status(): void
    {
        [$hubspot] = $this->flow();
        $event = Event::where('method_name', 'syncQuoteExecutionResponse')->firstOrFail();
        $api = Mockery::mock(HubspotApiServiceRefactored::class);
        $api->shouldReceive('updateObject')->once()->withArgs(static fn ($object, $id, $properties): bool =>
            $object === 'quotes' && $id === 'Q1' && $properties['aspel_cve_doc'] === 'C0001'
            && (string) $properties['aspel_folio'] === '1' && $properties['sync_status_aspel'] === 'already_exists')
            ->andReturn(['success' => true]);
        $api->shouldReceive('addNoteToObject')->once()->withArgs(static fn ($object, $id, $text): bool =>
            $object === 'deals' && $id === 'D1' && str_contains($text, 'Documento SAE: C0001')
            && str_contains($text, 'Folio: 1') && str_contains($text, 'HubSpot Quote ID: Q1'))
            ->andReturn(['success' => true, 'data' => ['id' => 'N2']]);
        $result = (new HubspotService($hubspot, $event, null, $api))->syncQuoteExecutionResponse([
            'hubspotQuoteId' => 'Q1', 'hubspotDealId' => 'D1',
            'destination_response' => ['status_code' => 200, 'data' => [
                'cveDoc' => 'C0001', 'folio' => 1, 'serie' => 'C', 'importe' => 270,
                'fechaDocumento' => '2026-09-23T00:00:00-06:00',
            ]],
        ]);
        $this->assertTrue($result['success']);
        $this->assertSame('N2', $result['data']['hubspot_note']['data']['id']);
    }

    public function test_emission_validation_failure_notes_the_deal_and_traces_note_in_record(): void
    {
        [, $prepare] = $this->flow();
        $event = $prepare->to_event;
        $payload = ['hubspotDealId' => 'D1', 'hubspotQuoteId' => 'Q1', 'source_event_id' => $prepare->id];
        $record = \App\Models\Record::create(['event_id' => $event->id, 'event_type' => 'generic.external.call',
            'status' => 'init', 'payload' => $payload]);
        $api = Mockery::mock(HubspotApiServiceRefactored::class);
        $api->shouldReceive('updateObject')->once()->withArgs(static fn ($object, $id, $properties): bool =>
            $object === 'quotes' && $id === 'Q1' && $properties['sync_status_aspel'] === 'error')->andReturn(['success' => true]);
        $api->shouldReceive('addNoteToObject')->once()->withArgs(static fn ($object, $id, $text): bool =>
            $object === 'deals' && $id === 'D1' && str_contains($text, 'invalid_aspel_quote_payload'))
            ->andReturn(['success' => true, 'data' => ['id' => 'N1']]);
        $adapter = Mockery::mock(GenericHttpAdapter::class);
        $adapter->shouldNotReceive('send');
        (new \App\Jobs\Generic\EndpointExecutionJob($event, $record, $payload))->handle(
            app(\App\Services\EventProcessingService::class), $adapter,
            app(\App\Services\EventLoggingService::class), app(\App\Services\RateLimitService::class), $api
        );
        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('deals', data_get($record->details, 'hubspot_note.object_type'));
        $this->assertSame('N1', data_get($record->details, 'hubspot_note.note_id'));
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ExecuteEventJob;
use App\Jobs\ProcessNextEventJob;
use App\Models\Event;
use App\Models\Platform;
use App\Models\Property;
use App\Models\PropertyRelationship;
use App\Services\AzureSql\AzureSqlService;
use App\Services\EventLoggingService;
use App\Services\EventProcessingService;
use App\Services\Hubspot\HubspotApiServiceRefactored;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AzureSqlServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTS_DEFAULT_QUERY = 'SELECT * FROM [dbo].[inventtable] WHERE [modifieddatetime] >= DATEADD(MINUTE, -24, GETDATE()) ORDER BY [modifieddatetime] ASC';
    private const PRODUCTS_48_HOURS_QUERY = 'SELECT * FROM [dbo].[inventtable] WHERE [modifieddatetime] >= DATEADD(MINUTE, -48, GETDATE()) ORDER BY [modifieddatetime] ASC';
    private const ACCOUNTS_DEFAULT_QUERY = 'SELECT * FROM [dbo].[custtable] WHERE [modifieddatetime] >= DATEADD(MINUTE, -24, GETDATE()) ORDER BY [modifieddatetime] ASC';
    private const CONTACTS_DEFAULT_QUERY = 'SELECT * FROM [dbo].[contactos_cl] WHERE [modifieddatetime] >= DATEADD(MINUTE, -24, GETDATE()) ORDER BY [modifieddatetime] ASC';
    private const CUSTOMER_UPDATE_QUERY = 'UPDATE [dbo].[custtable] SET [custname] = ? WHERE [accountnum] = ?';
    private const CUSTOMER_CHAIN_UPDATE_QUERY = 'UPDATE [dbo].[custtable] SET [Cadenas_Empresas] = ? WHERE [accountnum] = ?';
    private const CONTACT_UPDATE_QUERY = 'UPDATE [dbo].[contactos_cl] SET [locator] = ? WHERE [accountnum] = ? AND [Tipo] = ?';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_event_processing_service_resolves_azure_sql_driver_for_generic_platform(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Azure SQL MACO',
            'slug' => 'azure-sql-maco',
            'type' => 'generic',
            'settings' => [
                'service_driver' => 'azure_sql',
            ],
            'active' => true,
        ]);

        $serviceClass = app(EventProcessingService::class)->getServiceClass($platform);

        $this->assertSame(AzureSqlService::class, $serviceClass);
    }

    public function test_azure_sql_service_applies_hubspot_runtime_token_from_next_event_platform(): void
    {
        config()->set('hubspot.access_token', null);

        $hubspotPlatform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'hubspot-token-from-next-platform',
            ],
            'active' => true,
        ]);

        [$platform, $event] = $this->makeAzureSqlEvent('syncProducts', 'azure_sql.products.sync');

        $nextEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event->update([
            'to_event_id' => $nextEvent->id,
        ]);

        new AzureSqlService($platform, $event->fresh('to_event.platform'), null, Mockery::mock(HubspotApiServiceRefactored::class));

        $this->assertSame('hubspot-token-from-next-platform', config('hubspot.access_token'));
    }

    public function test_sync_products_uses_identificador_db_then_sku_and_updates_existing_product(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('syncProducts', 'azure_sql.products.sync');
        $record = $this->makeRecord($event);

        $this->attachRelationship($event, $platform, 'ProductName', 'name');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::PRODUCTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'itemid' => 'SKU-001',
                    'ProductName' => 'Teclado mecanico',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'identificador_db', 'SKU-001', ['identificador_db'])
            ->andReturn(['success' => true, 'data' => ['results' => []]]);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'sku', 'SKU-001', ['sku'])
            ->andReturn(['success' => true, 'data' => ['results' => [['id' => '2001']]]]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('products', '2001', ['name' => 'Teclado mecanico'])
            ->andReturn(['success' => true, 'data' => ['id' => '2001']]);

        $service = new AzureSqlService($platform, $event, $record, $hubspotApi);
        $result = $service->syncProducts();

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.rows_updated'));
        $this->assertSame(['2001'], data_get($result, 'data.hubspot_updated_ids'));
        $this->assertSame(24, data_get($result, 'data.sync_window_hours'));
    }

    public function test_sync_products_prepares_batch_output_when_next_event_is_hubspot_product_update(): void
    {
        $hubspotPlatform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        [$platform, $event] = $this->makeAzureSqlEvent('syncProducts', 'azure_sql.products.sync');
        $nextEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event->update([
            'to_event_id' => $nextEvent->id,
        ]);

        $record = $this->makeRecord($event);

        $this->attachRelationship($event, $platform, 'itemid', 'identificador_db');
        $this->attachRelationship($event, $platform, 'ProductName', 'name');
        $this->attachRelationship($event, $platform, 'modifieddatetime', 'date_modificacion_db');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::PRODUCTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'itemid' => 'SKU-001',
                    'ProductName' => 'Teclado mecanico',
                    'modifieddatetime' => '2026-05-13 10:15:00',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldNotReceive('searchObjectByProperty');
        $hubspotApi->shouldNotReceive('updateObject');

        $service = new AzureSqlService($platform, $event->fresh('to_event.platform'), $record, $hubspotApi);
        $result = $service->syncProducts();

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.output_payload_count'));
        $this->assertSame('SKU-001', data_get($result, 'data.output_payload.0.identificador_db'));
        $this->assertSame('Teclado mecanico', data_get($result, 'data.output_payload.0.name'));
        $this->assertSame('2026-05-13 10:15:00', data_get($result, 'data.output_payload.0.date_modificacion_db'));
        $this->assertSame('next_event', data_get($result, 'data.dispatch_mode'));
    }

    public function test_sync_products_can_use_a_48_hour_modifieddatetime_window(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('syncProducts', 'azure_sql.products.sync', [
            'sync_window_hours' => 48,
        ]);
        $record = $this->makeRecord($event);

        $this->attachRelationship($event, $platform, 'ProductName', 'name');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::PRODUCTS_48_HOURS_QUERY)
            ->andReturn([
                (object) [
                    'itemid' => 'SKU-048',
                    'ProductName' => 'Producto reciente',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('products', 'identificador_db', 'SKU-048', ['identificador_db'])
            ->andReturn(['success' => true, 'data' => ['results' => [['id' => '20048']]]]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('products', '20048', ['name' => 'Producto reciente'])
            ->andReturn(['success' => true, 'data' => ['id' => '20048']]);

        $service = new AzureSqlService($platform, $event, $record, $hubspotApi);
        $result = $service->syncProducts();

        $this->assertTrue($result['success']);
        $this->assertSame(48, data_get($result, 'data.sync_window_hours'));
    }

    public function test_sync_accounts_skips_hubspot_owned_columns_and_matches_by_email_when_identifier_misses(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('syncAccounts', 'azure_sql.accounts.sync');
        $record = $this->makeRecord($event);

        $this->attachRelationship($event, $platform, 'custname', 'name');
        $this->attachRelationship($event, $platform, 'Cadenas_Empresas', 'cadena_empresas');
        $this->attachRelationship($event, $platform, 'modifieddatetime', 'date_modificacion_db');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::ACCOUNTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'accountnum' => 'ACCT-100',
                    'custname' => 'Cliente Norte',
                    'Correo' => 'cliente@example.com',
                    'Telefono' => '8095550101',
                    'Cadenas_Empresas' => 'No debe sobrescribirse',
                    'modifieddatetime' => '2026-05-13 11:15:00',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'identificador_db', 'ACCT-100', ['identificador_db'])
            ->andReturn(['success' => true, 'data' => ['results' => []]]);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'email', 'cliente@example.com', ['email'])
            ->andReturn(['success' => true, 'data' => ['results' => [['id' => 'cmp-10']]]]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('companies', 'cmp-10', [
                'name' => 'Cliente Norte',
                'date_modificacion_db' => '2026-05-13 11:15:00',
            ])
            ->andReturn(['success' => true, 'data' => ['id' => 'cmp-10']]);

        $service = new AzureSqlService($platform, $event, $record, $hubspotApi);
        $result = $service->syncAccounts();

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.rows_updated'));
        $this->assertSame(24, data_get($result, 'data.sync_window_hours'));
        $this->assertSame('modifieddatetime', data_get($result, 'data.modified_filter_column'));
    }

    public function test_sync_accounts_continues_matching_when_identifier_search_fails(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('syncAccounts', 'azure_sql.accounts.sync');
        $record = $this->makeRecord($event);

        $this->attachRelationship($event, $platform, 'custname', 'name');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::ACCOUNTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'accountnum' => 'CL-000002536',
                    'custname' => 'Cliente con fallback',
                    'Correo' => null,
                    'Telefono' => '8095552536',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'identificador_db', 'CL-000002536', ['identificador_db'])
            ->andReturn([
                'success' => false,
                'error' => [
                    'status' => 'error',
                    'message' => 'There was a problem with the request.',
                    'correlationId' => '019e8eca-88de-7a09-8f92-d412dfbed2ea',
                ],
            ]);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'phone', '8095552536', ['phone'])
            ->andReturn([
                'success' => true,
                'data' => ['results' => [['id' => 'cmp-2536']]],
            ]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('companies', 'cmp-2536', ['name' => 'Cliente con fallback'])
            ->andReturn(['success' => true, 'data' => ['id' => 'cmp-2536']]);

        $service = new AzureSqlService($platform, $event, $record, $hubspotApi);
        $result = $service->syncAccounts();

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.rows_updated'));
        $this->assertSame(0, data_get($result, 'data.rows_warning'));
        $this->assertSame(['cmp-2536'], data_get($result, 'data.hubspot_updated_ids'));
    }

    public function test_sync_accounts_prepares_batch_output_when_next_event_is_hubspot_company_update(): void
    {
        $hubspotPlatform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio-accounts-batch',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        [$platform, $event] = $this->makeAzureSqlEvent('syncAccounts', 'azure_sql.accounts.sync');
        $nextEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Actualización de empresas',
            'event_type_id' => 'company.updated',
            'method_name' => 'updateCompany',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event->update([
            'to_event_id' => $nextEvent->id,
        ]);

        $record = $this->makeRecord($event);

        $this->attachRelationship($event, $platform, 'accountnum', 'codigo_unico_por_cuenta');
        $this->attachRelationship($event, $platform, 'custname', 'name');
        $this->attachRelationship($event, $platform, 'Correo', 'email');
        $this->attachRelationship($event, $platform, 'Telefono', 'phone');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::ACCOUNTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'accountnum' => 'CL-000002536',
                    'custname' => 'Cliente Batch',
                    'Correo' => 'cliente@example.com',
                    'Telefono' => '809-959-0400',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldNotReceive('searchObjectByProperty');
        $hubspotApi->shouldNotReceive('updateObject');

        $service = new AzureSqlService($platform, $event->fresh('to_event.platform'), $record, $hubspotApi);
        $result = $service->syncAccounts();

        $this->assertTrue($result['success']);
        $this->assertSame('next_event', data_get($result, 'data.dispatch_mode'));
        $this->assertSame(1, data_get($result, 'data.output_payload_count'));
        $this->assertSame('CL-000002536', data_get($result, 'data.output_payload.0.codigo_unico_por_cuenta'));
        $this->assertSame('Cliente Batch', data_get($result, 'data.output_payload.0.name'));
    }

    public function test_sync_contacts_does_not_overwrite_locator_or_tipo_from_sql(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('syncContacts', 'azure_sql.contacts.sync');
        $record = $this->makeRecord($event);

        $this->attachRelationship($event, $platform, 'accountnum', 'identificador_db');
        $this->attachRelationship($event, $platform, 'locator', 'phone');
        $this->attachRelationship($event, $platform, 'Tipo', 'tipo_locator');
        $this->attachRelationship($event, $platform, 'modifieddatetime', 'date_modificacion_db');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::CONTACTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'accountnum' => 'CT-77',
                    'locator' => 'persona@example.com',
                    'Tipo' => 'correo',
                    'modifieddatetime' => '2026-05-13 12:15:00',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('contacts', 'identificador_db', 'CT-77', ['identificador_db'])
            ->andReturn(['success' => true, 'data' => ['results' => [['id' => 'ct-77']]]]);
        $hubspotApi->shouldReceive('updateObject')
            ->once()
            ->with('contacts', 'ct-77', [
                'identificador_db' => 'CT-77',
                'date_modificacion_db' => '2026-05-13 12:15:00',
            ])
            ->andReturn(['success' => true, 'data' => ['id' => 'ct-77']]);

        $service = new AzureSqlService($platform, $event, $record, $hubspotApi);
        $result = $service->syncContacts();

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.rows_updated'));
        $this->assertSame(24, data_get($result, 'data.sync_window_hours'));
        $this->assertSame('modifieddatetime', data_get($result, 'data.modified_filter_column'));
    }

    public function test_sync_contacts_prepares_batch_output_when_next_event_is_hubspot_contact_update(): void
    {
        $hubspotPlatform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        [$platform, $event] = $this->makeAzureSqlEvent('syncContacts', 'azure_sql.contacts.sync');
        $nextEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Actualización de contactos',
            'event_type_id' => 'contact.updated',
            'method_name' => 'updateContact',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event->update([
            'to_event_id' => $nextEvent->id,
        ]);

        $record = $this->makeRecord($event);

        $this->attachRelationship($event, $platform, 'accountnum', 'identificador_db');
        $this->attachRelationship($event, $platform, 'modifieddatetime', 'date_modificacion_db');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::CONTACTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'accountnum' => 'CT-77',
                    'locator' => 'persona@example.com',
                    'Tipo' => 'correo',
                    'modifieddatetime' => '2026-05-13 12:15:00',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldNotReceive('searchObjectByProperty');
        $hubspotApi->shouldNotReceive('updateObject');

        $service = new AzureSqlService($platform, $event->fresh('to_event.platform'), $record, $hubspotApi);
        $result = $service->syncContacts();

        $this->assertTrue($result['success']);
        $this->assertSame('next_event', data_get($result, 'data.dispatch_mode'));
        $this->assertSame(1, data_get($result, 'data.output_payload_count'));
        $this->assertSame('CT-77', data_get($result, 'data.output_payload.0.identificador_db'));
        $this->assertSame('2026-05-13 12:15:00', data_get($result, 'data.output_payload.0.date_modificacion_db'));
    }

    public function test_update_customer_updates_custtable_using_mapped_payload_and_accountnum_key(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('updateCustomer', 'azure_sql.customer.update');
        $record = $this->makeRecord($event);

        $this->attachWriteRelationship($event, $platform, 'nombre_de_la_cuenta', 'custname');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('update')
            ->once()
            ->with(self::CUSTOMER_UPDATE_QUERY, ['Cliente Nuevo', 'CL-000002536'])
            ->andReturn(1);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $service = new AzureSqlService($platform, $event, $record, Mockery::mock(HubspotApiServiceRefactored::class));
        $result = $service->updateCustomer([
            'accountnum' => 'CL-000002536',
            'nombre_de_la_cuenta' => 'Cliente Nuevo',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
        $this->assertSame(['custname'], data_get($result, 'data.executed.0.updated_columns'));
    }

    public function test_update_customer_can_update_cadenas_empresas_from_hubspot_company_name_mapping(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('updateCustomer', 'azure_sql.customer.update');
        $record = $this->makeRecord($event);

        $this->attachWriteRelationship($event, $platform, 'nombre_de_la_cuenta', 'Cadenas_Empresas');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('update')
            ->once()
            ->with(self::CUSTOMER_CHAIN_UPDATE_QUERY, ['Comercializadora Demo 2ww', 'CL-000002536'])
            ->andReturn(1);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $service = new AzureSqlService($platform, $event, $record, Mockery::mock(HubspotApiServiceRefactored::class));
        $result = $service->updateCustomer([
            'accountnum' => 'CL-000002536',
            'nombre_de_la_cuenta' => 'Comercializadora Demo 2ww',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
        $this->assertSame(['Cadenas_Empresas'], data_get($result, 'data.executed.0.updated_columns'));
    }

    public function test_update_contact_updates_contactos_cl_using_accountnum_and_tipo_keys(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('updateContact', 'azure_sql.contact.update');
        $record = $this->makeRecord($event);

        $this->attachWriteRelationship($event, $platform, 'email', 'locator');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('update')
            ->once()
            ->with(self::CONTACT_UPDATE_QUERY, ['nuevo@example.com', 'CL-000002536', 'correo'])
            ->andReturn(1);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $service = new AzureSqlService($platform, $event, $record, Mockery::mock(HubspotApiServiceRefactored::class));
        $result = $service->updateContact([
            'accountnum' => 'CL-000002536',
            'Tipo' => 'correo',
            'email' => 'nuevo@example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, data_get($result, 'data.updated_count'));
        $this->assertSame(['accountnum', 'Tipo'], data_get($result, 'data.executed.0.key_columns'));
    }

    public function test_update_customer_returns_warning_when_key_column_is_missing(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('updateCustomer', 'azure_sql.customer.update');
        $record = $this->makeRecord($event);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldNotReceive('update');

        $service = new AzureSqlService($platform, $event, $record, Mockery::mock(HubspotApiServiceRefactored::class));
        $result = $service->updateCustomer([
            'custname' => 'Cliente sin llave',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['status']);
        $this->assertSame(0, data_get($result, 'data.updated_count'));
        $this->assertSame('missing_key_columns', data_get($result, 'data.warnings.0.reason'));
        $this->assertSame(['accountnum'], data_get($result, 'data.warnings.0.missing'));
    }

    public function test_execute_event_job_logs_warning_when_azure_sql_service_returns_warning(): void
    {
        [$platform, $event] = $this->makeAzureSqlEvent('syncProducts', 'azure_sql.products.sync');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::PRODUCTS_DEFAULT_QUERY)
            ->andReturn([]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $this->app->instance(HubspotApiServiceRefactored::class, $hubspotApi);

        $job = new ExecuteEventJob($event);
        $job->handle(app(EventLoggingService::class), app(EventProcessingService::class));

        $record = $event->records()->latest('id')->first();

        $this->assertNotNull($record);
        $this->assertSame('warning', $record->status);
        $this->assertSame('inventtable', data_get($record->details, 'table'));
    }

    public function test_execute_event_job_dispatches_next_event_job_when_output_payload_is_prepared(): void
    {
        Queue::fake();

        $hubspotPlatform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        [$platform, $event] = $this->makeAzureSqlEvent('syncProducts', 'azure_sql.products.sync');
        $nextEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Actualización de productos',
            'event_type_id' => 'product.updated',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event->update([
            'to_event_id' => $nextEvent->id,
        ]);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::PRODUCTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'itemid' => 'SKU-001',
                    'ProductName' => 'Teclado mecanico',
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $this->attachRelationship($event, $platform, 'itemid', 'identificador_db');
        $this->attachRelationship($event, $platform, 'ProductName', 'name');

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldNotReceive('searchObjectByProperty');
        $hubspotApi->shouldNotReceive('updateObject');
        $this->app->instance(HubspotApiServiceRefactored::class, $hubspotApi);

        $job = new ExecuteEventJob($event->fresh('to_event.platform'));
        $job->handle(app(EventLoggingService::class), app(EventProcessingService::class));

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($event): bool {
            return $job->event->id === $event->id
                && ($job->data[0]['identificador_db'] ?? null) === 'SKU-001'
                && ($job->data[0]['name'] ?? null) === 'Teclado mecanico';
        });
    }

    public function test_execute_event_job_dispatches_company_creation_fallback_when_account_is_not_found(): void
    {
        Queue::fake();

        $hubspotPlatform = Platform::query()->create([
            'name' => 'Hubspot corripio',
            'slug' => 'hubspot-corripio-accounts',
            'type' => 'hubspot',
            'credentials' => [
                'access_token' => 'token_123',
            ],
            'active' => true,
        ]);

        [$platform, $event] = $this->makeAzureSqlEvent('syncAccounts', 'azure_sql.accounts.sync');
        $createEvent = Event::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => 'Creación de empresa HubSpot',
            'event_type_id' => 'company.created',
            'method_name' => 'createCompany',
            'type' => 'webhook',
            'active' => true,
        ]);

        $event->update([
            'to_event_id' => $createEvent->id,
        ]);

        $this->attachRelationship($event, $platform, 'accountnum', 'identificador_db');
        $this->attachRelationship($event, $platform, 'custname', 'name');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->with(self::ACCOUNTS_DEFAULT_QUERY)
            ->andReturn([
                (object) [
                    'accountnum' => 'CL-000002536',
                    'custname' => 'Cliente Nuevo',
                    'Correo' => null,
                    'Telefono' => null,
                ],
            ]);

        DB::shouldReceive('purge')->once()->with('azure_sql_runtime');
        DB::shouldReceive('connection')->once()->with('azure_sql_runtime')->andReturn($connection);

        $hubspotApi = Mockery::mock(HubspotApiServiceRefactored::class);
        $hubspotApi->shouldReceive('searchObjectByProperty')
            ->once()
            ->with('companies', 'identificador_db', 'CL-000002536', ['identificador_db'])
            ->andReturn([
                'success' => true,
                'data' => ['results' => []],
            ]);
        $hubspotApi->shouldNotReceive('updateObject');
        $this->app->instance(HubspotApiServiceRefactored::class, $hubspotApi);

        $job = new ExecuteEventJob($event->fresh('to_event.platform'));
        $job->handle(app(EventLoggingService::class), app(EventProcessingService::class));

        Queue::assertPushed(ProcessNextEventJob::class, function (ProcessNextEventJob $job) use ($event): bool {
            return $job->event->id === $event->id
                && ($job->data[0]['identificador_db'] ?? null) === 'CL-000002536'
                && ($job->data[0]['name'] ?? null) === 'Cliente Nuevo';
        });

        $record = $event->records()->latest('id')->first();

        $this->assertNotNull($record);
        $this->assertSame('success', $record->status);
        $this->assertSame(1, data_get($record->details, 'rows_prepared_for_fallback'));
        $this->assertSame('CL-000002536', data_get($record->details, 'output_payload.0.identificador_db'));
    }

    private function makeAzureSqlEvent(string $methodName, string $eventTypeId, array $meta = []): array
    {
        $platform = Platform::query()->create([
            'name' => 'Azure SQL MACO',
            'slug' => 'azure-sql-maco',
            'type' => 'generic',
            'credentials' => [
                'service_driver' => 'azure_sql',
                'username' => 'sqladmin',
                'password' => 'secret',
            ],
            'settings' => [
                'service_driver' => 'azure_sql',
                'host' => 'sql-crm-maco.database.windows.net',
                'port' => '1433',
                'database' => 'DB-CRM',
                'encrypt' => true,
                'trust_server_certificate' => false,
                'login_timeout' => 30,
            ],
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => $eventTypeId,
            'event_type_id' => $eventTypeId,
            'method_name' => $methodName,
            'type' => 'schedule',
            'schedule_expression' => '0 * * * *',
            'meta' => $meta,
            'active' => true,
        ]);

        return [$platform, $event];
    }

    private function makeRecord(Event $event)
    {
        return $event->records()->create([
            'event_type' => $event->event_type_id,
            'status' => 'init',
            'payload' => [],
            'message' => 'Init',
        ]);
    }

    private function attachRelationship(Event $event, Platform $sourcePlatform, string $sourceKey, string $targetKey): void
    {
        $hubspotPlatform = Platform::query()->firstOrCreate([
            'slug' => 'hubspot-test',
        ], [
            'name' => 'HubSpot Test',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $sourcePlatform->id,
            'name' => $sourceKey,
            'key' => $sourceKey,
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        $targetProperty = Property::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => $targetKey,
            'key' => $targetKey,
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        PropertyRelationship::query()->create([
            'event_id' => $event->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => null,
            'active' => true,
        ]);
    }

    private function attachWriteRelationship(Event $event, Platform $targetPlatform, string $sourceKey, string $targetKey): void
    {
        $hubspotPlatform = Platform::query()->firstOrCreate([
            'slug' => 'hubspot-write-source',
        ], [
            'name' => 'HubSpot Write Source',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $sourceProperty = Property::query()->create([
            'platform_id' => $hubspotPlatform->id,
            'name' => $sourceKey,
            'key' => $sourceKey,
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        $targetProperty = Property::query()->create([
            'platform_id' => $targetPlatform->id,
            'name' => $targetKey,
            'key' => $targetKey,
            'type' => 'string',
            'required' => false,
            'active' => true,
        ]);

        PropertyRelationship::query()->create([
            'event_id' => $event->id,
            'property_id' => $sourceProperty->id,
            'related_property_id' => $targetProperty->id,
            'mapping_key' => null,
            'active' => true,
        ]);
    }
}

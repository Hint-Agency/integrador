<?php

namespace Tests\Unit;

use App\Services\SignedQuotesPipelineService;
use PHPUnit\Framework\TestCase;

class SignedQuotesPipelineServiceTest extends TestCase
{
    public function test_it_normalizes_and_evaluates_entity_actions(): void
    {
        $service = new SignedQuotesPipelineService;

        $quotes = $service->normalizeQuotes([[
            'quote_id' => 'Q-1',
            'entities' => [
                'company' => [
                    'odoo_id' => null,
                    'fields' => ['name' => 'ACME'],
                    'sync_snapshots' => ['odoo' => []],
                ],
                'contact' => [
                    'odoo_id' => 10,
                    'fields' => ['email' => 'contact@acme.test'],
                    'sync_snapshots' => ['odoo' => ['email' => 'old@acme.test']],
                ],
                'products' => [[
                    'odoo_id' => 20,
                    'fields' => ['name' => 'SKU-1'],
                    'sync_snapshots' => ['odoo' => ['name' => 'SKU-1']],
                ]],
            ],
        ]]);

        $evaluated = $service->evaluateEntities($quotes, 'odoo');

        $this->assertSame('create', $evaluated[0]['entity_actions']['company']['action']);
        $this->assertSame('update', $evaluated[0]['entity_actions']['contact']['action']);
        $this->assertSame('no_change', $evaluated[0]['entity_actions']['products'][0]['action']);
    }

    public function test_it_builds_hubspot_sync_metadata(): void
    {
        $service = new SignedQuotesPipelineService;

        $metadata = $service->buildHubspotSyncMetadata([
            'action' => 'update',
            'changed_fields' => ['name', 'email'],
        ], 'odoo');

        $this->assertSame('update', $metadata['sync_operation_odoo']);
        $this->assertSame(['name', 'email'], $metadata['updated_fields_odoo']);
        $this->assertArrayHasKey('last_sync_odoo', $metadata);
    }

    public function test_it_builds_canonical_quote_sync_marker_properties(): void
    {
        $service = new SignedQuotesPipelineService;

        $properties = $service->buildHubspotQuoteSyncProperties([], 'odoo', [
            'odoo_id' => 901,
            'sync_status' => 'already_exists',
        ]);

        $this->assertSame(901, $properties['odoo_id']);
        $this->assertSame('already_exists', $properties['sync_status_odoo']);
        $this->assertSame('', $properties['last_error_odoo']);
        $this->assertArrayHasKey('last_sync_odoo', $properties);
    }
}

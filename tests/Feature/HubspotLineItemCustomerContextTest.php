<?php

namespace Tests\Feature;

use App\Services\Hubspot\HubspotApiServiceRefactored;
use App\Services\Hubspot\HubspotLineItemCustomerContextService;
use Mockery;
use Tests\TestCase;

class HubspotLineItemCustomerContextTest extends TestCase
{
    private function response(array $data): array
    {
        return ['success' => true, 'data' => $data];
    }

    private function apiForDeal(): HubspotApiServiceRefactored
    {
        $api = Mockery::mock(HubspotApiServiceRefactored::class);
        $api->shouldReceive('getObjectAssociations')->once()->with('line_items', 'LI1', 'deals')
            ->andReturn($this->response(['results' => [['toObjectId' => 'D1']]]));
        return $api;
    }

    public function test_internal_association_name_resolves_primary_contact_and_customer_clave(): void
    {
        $api = $this->apiForDeal();
        $api->shouldReceive('getAssociationTypes')->once()->with('deals', 'contacts')
            ->andReturn($this->response(['results' => [['id' => '101', 'name' => 'main_contact']]]));
        $api->shouldReceive('getObjectAssociations')->once()->with('deals', 'D1', 'contacts')
            ->andReturn($this->response(['results' => [
                ['toObjectId' => 'C1', 'associationTypes' => [['category' => 'USER_DEFINED', 'typeId' => 101, 'label' => 'Cliente principal']]],
                ['toObjectId' => 'C2', 'associationTypes' => [['category' => 'HUBSPOT_DEFINED', 'typeId' => 3]]],
            ]]));
        $api->shouldReceive('getObject')->once()->with('contacts', 'C1', ['clave'])
            ->andReturn($this->response(['properties' => ['clave' => ' 42 ']]));

        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', []);

        $this->assertTrue($result['success']);
        $this->assertSame(['hubspot_deal_id' => 'D1', 'hubspot_customer_contact_id' => 'C1', 'claveCliente' => '42'], $result['context']);
    }

    public function test_multiple_deals_do_not_choose_an_arbitrary_customer(): void
    {
        $api = Mockery::mock(HubspotApiServiceRefactored::class);
        $api->shouldReceive('getObjectAssociations')->once()->with('line_items', 'LI1', 'deals')
            ->andReturn($this->response(['results' => [['toObjectId' => 'D1'], ['toObjectId' => 'D2']]]));
        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', []);
        $this->assertFalse($result['success']);
        $this->assertSame('missing_or_ambiguous_deal', $result['reason']);
    }

    public function test_explicit_primary_type_id_selects_contact_among_normal_associations(): void
    {
        $api = $this->apiForDeal();
        $api->shouldNotReceive('getAssociationTypes');
        $api->shouldReceive('getObjectAssociations')->once()->with('deals', 'D1', 'contacts')
            ->andReturn($this->response(['results' => [
                ['toObjectId' => 'C1', 'associationTypes' => [
                    ['category' => 'HUBSPOT_DEFINED', 'typeId' => 3],
                    ['category' => 'HUBSPOT_DEFINED', 'typeId' => 1],
                ]],
                ['toObjectId' => 'C2', 'associationTypes' => [['category' => 'HUBSPOT_DEFINED', 'typeId' => 3]]],
            ]]));
        $api->shouldReceive('getObject')->once()->with('contacts', 'C1', ['clave'])
            ->andReturn($this->response(['properties' => ['clave' => '42']]));
        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', ['primary_contact_association_type_id' => 1]);
        $this->assertTrue($result['success']);
        $this->assertSame('C1', $result['context']['hubspot_customer_contact_id']);
        $this->assertSame('42', $result['context']['claveCliente']);
    }

    public function test_optional_category_rejects_an_association_with_different_category(): void
    {
        $api = $this->apiForDeal();
        $api->shouldReceive('getObjectAssociations')->once()->with('deals', 'D1', 'contacts')
            ->andReturn($this->response(['results' => [['toObjectId' => 'C1',
                'associationTypes' => [['category' => 'HUBSPOT_DEFINED', 'typeId' => 1]]]]]));
        $api->shouldNotReceive('getObject');
        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', [
            'primary_contact_association_type_id' => 1, 'primary_contact_association_category' => 'USER_DEFINED',
        ]);
        $this->assertSame('missing_or_ambiguous_main_contact', $result['reason']);
    }

    public function test_quote_only_line_item_resolves_the_quote_deal(): void
    {
        $api = Mockery::mock(HubspotApiServiceRefactored::class);
        $api->shouldReceive('getObjectAssociations')->once()->with('line_items', 'LI1', 'deals')
            ->andReturn($this->response(['results' => []]));
        $api->shouldReceive('getObjectAssociations')->once()->with('line_items', 'LI1', 'quotes')
            ->andReturn($this->response(['results' => [['toObjectId' => 'Q1']]]));
        $api->shouldReceive('getObjectAssociations')->once()->with('quotes', 'Q1', 'deals')
            ->andReturn($this->response(['results' => [['toObjectId' => 'D1']]]));
        $api->shouldReceive('getObjectAssociations')->once()->with('deals', 'D1', 'contacts')
            ->andReturn($this->response(['results' => []]));
        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', ['primary_contact_association_type_id' => 101]);
        $this->assertSame('missing_or_ambiguous_main_contact', $result['reason']);
        $this->assertSame('D1', $result['context']['deal_id']);
    }

    public function test_multiple_primary_contacts_across_pages_are_rejected(): void
    {
        $api = $this->apiForDeal();
        $contact = static fn (string $id): array => ['toObjectId' => $id,
            'associationTypes' => [['category' => 'USER_DEFINED', 'typeId' => 101]]];
        $api->shouldReceive('getObjectAssociations')->once()->with('deals', 'D1', 'contacts')
            ->andReturn($this->response(['results' => [$contact('C1')], 'paging' => ['next' => ['after' => 'C1']]]));
        $api->shouldReceive('getObjectAssociations')->once()->with('deals', 'D1', 'contacts', 'C1')
            ->andReturn($this->response(['results' => [$contact('C2')]]));
        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', ['primary_contact_association_type_id' => 101]);
        $this->assertFalse($result['success']);
        $this->assertSame(['C1', 'C2'], $result['context']['contact_ids']);
    }

    public function test_missing_customer_clave_is_reported_without_fallback_pricing(): void
    {
        $api = $this->apiForDeal();
        $api->shouldReceive('getObjectAssociations')->once()->with('deals', 'D1', 'contacts')
            ->andReturn($this->response(['results' => [['toObjectId' => 'C1',
                'associationTypes' => [['category' => 'USER_DEFINED', 'typeId' => 101]]]]]));
        $api->shouldReceive('getObject')->once()->with('contacts', 'C1', ['aspel_id'])
            ->andReturn($this->response(['properties' => ['aspel_id' => '']]));
        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', [
            'primary_contact_association_type_id' => 101, 'customer_clave_property' => 'aspel_id',
        ]);
        $this->assertFalse($result['success']);
        $this->assertSame('missing_customer_clave', $result['reason']);
    }

    public function test_uncreated_association_and_api_failures_do_not_continue(): void
    {
        $api = $this->apiForDeal();
        $api->shouldReceive('getAssociationTypes')->once()->andReturn($this->response(['results' => []]));
        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', []);
        $this->assertSame('primary_contact_association_unresolved', $result['reason']);

        $api = Mockery::mock(HubspotApiServiceRefactored::class);
        $api->shouldReceive('getObjectAssociations')->once()->andReturn(['success' => false, 'status_code' => 403]);
        $result = app(HubspotLineItemCustomerContextService::class)->resolve($api, 'LI1', []);
        $this->assertSame('error', $result['status']);
    }
}

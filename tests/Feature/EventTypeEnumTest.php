<?php

namespace Tests\Feature;

use App\Enums\EventType;
use App\Models\Event;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTypeEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_grouped_event_type_options(): void
    {
        $groups = EventType::groupedOptions();

        $this->assertNotEmpty($groups);
        $this->assertSame('Eventos principales', $groups[0]['label']);
        $this->assertTrue(collect($groups)->contains(
            fn (array $group): bool => collect($group['options'])->contains(
                fn (array $option): bool => $option['value'] === EventType::GENERIC_EXTERNAL_CALL->value
            )
        ));
    }

    public function test_it_filters_grouped_options_for_platform_type(): void
    {
        $groups = EventType::groupedOptions('generic');
        $options = collect($groups)->flatMap(fn (array $group) => $group['options']);

        $this->assertTrue($options->contains(fn (array $option): bool => $option['value'] === EventType::GENERIC_EXTERNAL_CALL->value));
        $this->assertTrue($options->contains(fn (array $option): bool => $option['value'] === EventType::AZURE_SQL_CUSTOMER_UPDATE->value));
        $this->assertTrue($options->contains(fn (array $option): bool => $option['value'] === EventType::AZURE_SQL_CONTACT_UPDATE->value));
        $this->assertFalse($options->contains(fn (array $option): bool => $option['value'] === EventType::ODOO_GET_LIST_PRICES->value));
    }

    public function test_it_includes_hubspot_subscription_based_property_changes_for_hubspot_platforms(): void
    {
        $groups = EventType::groupedOptions('hubspot');
        $options = collect($groups)->flatMap(fn (array $group) => $group['options']);

        $this->assertTrue($options->contains(fn (array $option): bool => $option['value'] === EventType::HUBSPOT_CONTACT_PROPERTY_CHANGE->value));
        $this->assertTrue($options->contains(fn (array $option): bool => $option['value'] === EventType::HUBSPOT_COMPANY_PROPERTY_CHANGE->value));
        $this->assertTrue($options->contains(fn (array $option): bool => $option['value'] === EventType::HUBSPOT_DEAL_PROPERTY_CHANGE->value));
        $this->assertTrue($options->contains(fn (array $option): bool => $option['value'] === EventType::HUBSPOT_OBJECT_PROPERTY_CHANGE->value));
        $this->assertFalse($options->contains(fn (array $option): bool => $option['value'] === EventType::ODOO_GET_STORE_PRODUCTS->value));
    }

    public function test_event_model_resolves_label_and_dispatch_class_from_enum(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Generic',
            'slug' => 'generic-main',
            'type' => 'generic',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Generic Call',
            'event_type_id' => EventType::GENERIC_EXTERNAL_CALL->value,
            'type' => 'webhook',
            'active' => true,
        ]);

        $this->assertSame('Llamada HTTP genérica', $event->getEventTypeLabel());
        $this->assertSame(\App\Events\Generic\ExternalCallEvent::class, $event->getEventClass());
    }

    public function test_event_model_resolves_hubspot_method_name_from_subscription_type(): void
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot-main',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Contact Property Change',
            'event_type_id' => EventType::HUBSPOT_CONTACT_PROPERTY_CHANGE->value,
            'type' => 'webhook',
            'subscription_type' => 'contact.propertyChange',
            'method_name' => null,
            'active' => true,
        ]);

        $this->assertSame('contactPropertyChange', $event->getMethodName());
    }

    public function test_event_model_resolves_azure_sql_update_methods_from_event_type(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Azure SQL',
            'slug' => 'azure-sql-main',
            'type' => 'generic',
            'settings' => [
                'service_driver' => 'azure_sql',
            ],
            'active' => true,
        ]);

        $customerEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Azure SQL Customer Update',
            'event_type_id' => EventType::AZURE_SQL_CUSTOMER_UPDATE->value,
            'type' => 'webhook',
            'method_name' => null,
            'active' => true,
        ]);
        $contactEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Azure SQL Contact Update',
            'event_type_id' => EventType::AZURE_SQL_CONTACT_UPDATE->value,
            'type' => 'webhook',
            'method_name' => null,
            'active' => true,
        ]);

        $this->assertSame('updateCustomer', $customerEvent->getMethodName());
        $this->assertSame('updateContact', $contactEvent->getMethodName());
        $this->assertSame(\App\Events\Object\UpdateObjectEvent::class, $customerEvent->getEventClass());
        $this->assertSame(\App\Events\Object\UpdateObjectEvent::class, $contactEvent->getEventClass());
    }

    public function test_event_model_resolves_hubspot_method_name_from_event_type_when_subscription_is_missing(): void
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot-fallback',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Contact Property Change Fallback',
            'event_type_id' => EventType::HUBSPOT_CONTACT_PROPERTY_CHANGE->value,
            'type' => 'webhook',
            'subscription_type' => null,
            'method_name' => null,
            'active' => true,
        ]);

        $this->assertSame('contactPropertyChange', $event->getMethodName());
    }

    public function test_event_model_trims_subscription_type_before_method_resolution(): void
    {
        $platform = Platform::query()->create([
            'name' => 'HubSpot',
            'slug' => 'hubspot-trimmed',
            'type' => 'hubspot',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Contact Property Change Trim',
            'event_type_id' => EventType::HUBSPOT_CONTACT_PROPERTY_CHANGE->value,
            'type' => 'webhook',
            'subscription_type' => '  contact.propertyChange  ',
            'method_name' => null,
            'active' => true,
        ]);

        $this->assertSame('contactPropertyChange', $event->getMethodName());
    }

    public function test_event_model_resolves_legacy_odoo_partner_event_to_v2_company_job_surface(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Odoo directoGroup',
            'slug' => 'odoo-directogroup',
            'type' => 'odoo',
            'active' => true,
        ]);

        $event = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Odoo Partner Created Company',
            'event_type_id' => 'odoo.partner.created.company',
            'type' => 'webhook',
            'method_name' => null,
            'active' => true,
        ]);

        $this->assertSame('resPartnerCreateCompany', $event->getMethodName());
        $this->assertSame(\App\Events\Company\CreateCompanyEvent::class, $event->getEventClass());
    }

    public function test_event_model_resolves_legacy_odoo_product_sync_events(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Odoo directoGroup',
            'slug' => 'odoo-products',
            'type' => 'odoo',
            'active' => true,
        ]);

        $createEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Odoo Sync Create Products',
            'event_type_id' => 'odoo.sync.create.products',
            'type' => 'schedule',
            'method_name' => null,
            'active' => true,
        ]);
        $updateEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Odoo Sync Update Products',
            'event_type_id' => 'odoo.sync.update.products',
            'type' => 'schedule',
            'method_name' => null,
            'active' => true,
        ]);

        $this->assertSame('syncCreateProducts', $createEvent->getMethodName());
        $this->assertSame(\App\Events\Product\CreateProductEvent::class, $createEvent->getEventClass());
        $this->assertSame('syncUpdateProducts', $updateEvent->getMethodName());
        $this->assertSame(\App\Events\Product\UpdateProductEvent::class, $updateEvent->getEventClass());
    }

    public function test_event_model_resolves_legacy_odoo_invoice_and_subscription_events(): void
    {
        $platform = Platform::query()->create([
            'name' => 'Odoo directoGroup',
            'slug' => 'odoo-invoices',
            'type' => 'odoo',
            'active' => true,
        ]);

        $subscriptionEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Odoo Create Sale Subscription',
            'event_type_id' => 'odoo.create.sale.subscription',
            'type' => 'webhook',
            'method_name' => null,
            'active' => true,
        ]);
        $invoiceEvent = Event::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Odoo Account Move',
            'event_type_id' => 'invoice.created',
            'subscription_type' => 'account.move',
            'type' => 'webhook',
            'method_name' => null,
            'active' => true,
        ]);

        $this->assertSame('createSaleSubscription', $subscriptionEvent->getMethodName());
        $this->assertSame(\App\Events\Invoice\CreateRecurringInvoiceEvent::class, $subscriptionEvent->getEventClass());
        $this->assertSame('accountMoveCreatedUpdated', $invoiceEvent->getMethodName());
        $this->assertSame(\App\Events\Invoice\CreateInvoiceEvent::class, $invoiceEvent->getEventClass());
    }
}

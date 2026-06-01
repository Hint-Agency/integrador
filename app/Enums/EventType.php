<?php

namespace App\Enums;

enum EventType: string
{
    case COMPANY_CREATED = 'company.created';
    case COMPANY_UPDATED = 'company.updated';
    case PRODUCT_CREATED = 'product.created';
    case PRODUCT_UPDATED = 'product.updated';
    case INVOICE_CREATED = 'invoice.created';
    case INVOICE_RECURRING_CREATED = 'invoice.recurring.created';
    case SALE_ORDER_CREATED = 'sale_order.created';
    case QUOTES_SENDING_DATA = 'quotes.sending_data';
    case RESPONSE_SEND = 'response.send';
    case OBJECT_UPDATED = 'object.updated';
    case HUBSPOT_CONTACT_PROPERTY_CHANGE = 'contact.propertyChange';
    case HUBSPOT_COMPANY_PROPERTY_CHANGE = 'company.propertyChange';
    case HUBSPOT_DEAL_PROPERTY_CHANGE = 'deal.propertyChange';
    case HUBSPOT_OBJECT_PROPERTY_CHANGE = 'object.propertyChange';
    case HUBSPOT_PROPERTY_CHANGED_LEGACY = 'hubspot.property.changed';
    case ODOO_GET_LIST_PRICES = 'odoo.get_list_prices';
    case ODOO_GET_STORE_PRODUCTS = 'odoo.get_store_products';
    case AZURE_SQL_PRODUCTS_SYNC = 'azure_sql.products.sync';
    case AZURE_SQL_ACCOUNTS_SYNC = 'azure_sql.accounts.sync';
    case AZURE_SQL_CONTACTS_SYNC = 'azure_sql.contacts.sync';
    case NEXT_EVENT = 'next.event';
    case GENERIC_EXTERNAL_CALL = 'generic.external.call';

    public function label(): string
    {
        return match ($this) {
            self::COMPANY_CREATED => 'Empresa creada',
            self::COMPANY_UPDATED => 'Empresa actualizada',
            self::PRODUCT_CREATED => 'Producto creado',
            self::PRODUCT_UPDATED => 'Producto actualizado',
            self::INVOICE_CREATED => 'Factura creada',
            self::INVOICE_RECURRING_CREATED => 'Factura recurrente creada',
            self::SALE_ORDER_CREATED => 'Orden de venta creada',
            self::QUOTES_SENDING_DATA => 'Envío de datos de cotización',
            self::RESPONSE_SEND => 'Envío de respuesta',
            self::OBJECT_UPDATED => 'Objeto actualizado',
            self::HUBSPOT_CONTACT_PROPERTY_CHANGE => 'Cambio de propiedad de contacto HubSpot',
            self::HUBSPOT_COMPANY_PROPERTY_CHANGE => 'Cambio de propiedad de empresa HubSpot',
            self::HUBSPOT_DEAL_PROPERTY_CHANGE => 'Cambio de propiedad de negocio HubSpot',
            self::HUBSPOT_OBJECT_PROPERTY_CHANGE => 'Cambio de propiedad de objeto HubSpot',
            self::HUBSPOT_PROPERTY_CHANGED_LEGACY => 'Cambio de propiedad HubSpot (legacy)',
            self::ODOO_GET_LIST_PRICES => 'Obtener listas de precios Odoo',
            self::ODOO_GET_STORE_PRODUCTS => 'Obtener productos de tienda Odoo',
            self::AZURE_SQL_PRODUCTS_SYNC => 'Sincronización de productos Azure SQL',
            self::AZURE_SQL_ACCOUNTS_SYNC => 'Sincronización de cuentas Azure SQL',
            self::AZURE_SQL_CONTACTS_SYNC => 'Sincronización de contactos Azure SQL',
            self::NEXT_EVENT => 'Siguiente evento',
            self::GENERIC_EXTERNAL_CALL => 'Llamada HTTP genérica',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::COMPANY_CREATED => 'Crea o reenvía una empresa dentro del flujo de integración.',
            self::COMPANY_UPDATED => 'Actualiza una empresa en la plataforma destino.',
            self::PRODUCT_CREATED => 'Crea un producto en la plataforma destino.',
            self::PRODUCT_UPDATED => 'Actualiza un producto existente en la plataforma destino.',
            self::INVOICE_CREATED => 'Crea o sincroniza una factura u objeto de facturación posterior.',
            self::INVOICE_RECURRING_CREATED => 'Crea una factura recurrente o artefacto de suscripción.',
            self::SALE_ORDER_CREATED => 'Crea una orden de venta en la plataforma destino.',
            self::QUOTES_SENDING_DATA => 'Transfiere datos de cotización para procesamiento posterior.',
            self::RESPONSE_SEND => 'Envía el payload de respuesta al siguiente paso de integración.',
            self::OBJECT_UPDATED => 'Actualiza un objeto genérico usando propiedades mapeadas.',
            self::HUBSPOT_CONTACT_PROPERTY_CHANGE => 'Procesa payloads HubSpot contact.propertyChange.',
            self::HUBSPOT_COMPANY_PROPERTY_CHANGE => 'Procesa payloads HubSpot company.propertyChange.',
            self::HUBSPOT_DEAL_PROPERTY_CHANGE => 'Procesa payloads HubSpot deal.propertyChange.',
            self::HUBSPOT_OBJECT_PROPERTY_CHANGE => 'Procesa payloads HubSpot object.propertyChange.',
            self::HUBSPOT_PROPERTY_CHANGED_LEGACY => 'Alias legacy conservado por compatibilidad con configuraciones previas.',
            self::ODOO_GET_LIST_PRICES => 'Obtiene listas de precios Odoo para productos o variantes.',
            self::ODOO_GET_STORE_PRODUCTS => 'Obtiene productos desde catálogos Odoo.',
            self::AZURE_SQL_PRODUCTS_SYNC => 'Lee productos desde Azure SQL y actualiza productos HubSpot existentes.',
            self::AZURE_SQL_ACCOUNTS_SYNC => 'Lee cuentas/clientes desde Azure SQL y actualiza empresas HubSpot existentes.',
            self::AZURE_SQL_CONTACTS_SYNC => 'Lee contactos desde Azure SQL y reconcilia contactos HubSpot existentes.',
            self::NEXT_EVENT => 'Evento interno de control para continuar un pipeline.',
            self::GENERIC_EXTERNAL_CALL => 'Ejecuta una llamada HTTP para una plataforma genérica sin SDK.',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::HUBSPOT_CONTACT_PROPERTY_CHANGE,
            self::HUBSPOT_COMPANY_PROPERTY_CHANGE,
            self::HUBSPOT_DEAL_PROPERTY_CHANGE,
            self::HUBSPOT_OBJECT_PROPERTY_CHANGE => 'HubSpot',
            self::HUBSPOT_PROPERTY_CHANGED_LEGACY => 'Legacy',
            self::ODOO_GET_LIST_PRICES,
            self::ODOO_GET_STORE_PRODUCTS => 'Sincronización Odoo',
            self::AZURE_SQL_PRODUCTS_SYNC,
            self::AZURE_SQL_ACCOUNTS_SYNC,
            self::AZURE_SQL_CONTACTS_SYNC => 'Sincronización Azure SQL',
            self::GENERIC_EXTERNAL_CALL => 'HTTP genérico',
            self::NEXT_EVENT => 'Control de flujo',
            default => 'Eventos principales',
        };
    }

    /**
     * @return list<string>
     */
    public function suggestedPlatforms(): array
    {
        return match ($this) {
            self::HUBSPOT_CONTACT_PROPERTY_CHANGE,
            self::HUBSPOT_COMPANY_PROPERTY_CHANGE,
            self::HUBSPOT_DEAL_PROPERTY_CHANGE,
            self::HUBSPOT_OBJECT_PROPERTY_CHANGE,
            self::HUBSPOT_PROPERTY_CHANGED_LEGACY => ['hubspot'],
            self::ODOO_GET_LIST_PRICES,
            self::ODOO_GET_STORE_PRODUCTS => ['odoo'],
            self::AZURE_SQL_PRODUCTS_SYNC,
            self::AZURE_SQL_ACCOUNTS_SYNC,
            self::AZURE_SQL_CONTACTS_SYNC => ['generic'],
            self::GENERIC_EXTERNAL_CALL => ['generic'],
            default => ['*'],
        };
    }

    public function isSuggestedForPlatform(?string $platformType): bool
    {
        if (! $platformType) {
            return true;
        }

        $platforms = $this->suggestedPlatforms();

        return in_array('*', $platforms, true) || in_array($platformType, $platforms, true);
    }

    public function eventClass(): ?string
    {
        return match ($this) {
            self::COMPANY_CREATED => \App\Events\Company\CreateCompanyEvent::class,
            self::COMPANY_UPDATED => \App\Events\Company\UpdateCompanyEvent::class,
            self::PRODUCT_CREATED => \App\Events\Product\CreateProductEvent::class,
            self::PRODUCT_UPDATED => \App\Events\Product\UpdateProductEvent::class,
            self::INVOICE_CREATED => \App\Events\Invoice\CreateInvoiceEvent::class,
            self::INVOICE_RECURRING_CREATED => \App\Events\Invoice\CreateRecurringInvoiceEvent::class,
            self::SALE_ORDER_CREATED => \App\Events\SaleOrder\CreateSaleOrderEvent::class,
            self::QUOTES_SENDING_DATA => \App\Events\Quotes\SendingQuotesDataEvent::class,
            self::RESPONSE_SEND => \App\Events\Response\SendResponseEvent::class,
            self::OBJECT_UPDATED => \App\Events\Object\UpdateObjectEvent::class,
            self::HUBSPOT_CONTACT_PROPERTY_CHANGE => \App\Events\Object\UpdateObjectEvent::class,
            self::HUBSPOT_COMPANY_PROPERTY_CHANGE => \App\Events\Object\UpdateObjectEvent::class,
            self::HUBSPOT_DEAL_PROPERTY_CHANGE => \App\Events\Object\UpdateObjectEvent::class,
            self::HUBSPOT_OBJECT_PROPERTY_CHANGE => \App\Events\Object\UpdateObjectEvent::class,
            self::HUBSPOT_PROPERTY_CHANGED_LEGACY => \App\Events\Object\UpdateObjectEvent::class,
            self::ODOO_GET_LIST_PRICES => \App\Events\Odoo\GetListPricesEvent::class,
            self::ODOO_GET_STORE_PRODUCTS => \App\Events\Odoo\GetStoreProductsEvent::class,
            self::AZURE_SQL_PRODUCTS_SYNC => \App\Events\Object\UpdateObjectEvent::class,
            self::AZURE_SQL_ACCOUNTS_SYNC => \App\Events\Object\UpdateObjectEvent::class,
            self::AZURE_SQL_CONTACTS_SYNC => \App\Events\Object\UpdateObjectEvent::class,
            self::NEXT_EVENT => \App\Events\NextEvent::class,
            self::GENERIC_EXTERNAL_CALL => \App\Events\Generic\ExternalCallEvent::class,
        };
    }

    public function toOption(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'group' => $this->group(),
            'platforms' => $this->suggestedPlatforms(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $eventType): string => $eventType->value,
            self::cases(),
        );
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    public static function tryFromSubscriptionType(?string $value): ?self
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = trim(strtolower($value));

        foreach (self::cases() as $eventType) {
            if (strtolower($eventType->value) === $normalized) {
                return $eventType;
            }
        }

        return null;
    }

    public static function groupedOptions(?string $platformType = null): array
    {
        $groups = [];

        foreach (self::cases() as $eventType) {
            if ($eventType === self::HUBSPOT_PROPERTY_CHANGED_LEGACY) {
                continue;
            }

            if (! $eventType->isSuggestedForPlatform($platformType)) {
                continue;
            }

            $group = $eventType->group();

            if (! isset($groups[$group])) {
                $groups[$group] = [
                    'label' => $group,
                    'options' => [],
                ];
            }

            $groups[$group]['options'][] = $eventType->toOption();
        }

        return array_values($groups);
    }
}

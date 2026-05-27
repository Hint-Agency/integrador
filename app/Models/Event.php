<?php

namespace App\Models;

use App\Enums\EventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'event_type_id',
        'platform_id',
        'to_event_id',
        'type',
        'schedule_expression',
        'last_executed_at',
        'command_sql',
        'enable_update_hubdb',
        'hubdb_table_id',
        'subscription_type',
        'method_name',
        'endpoint_api',
        'active',
        'payload_mapping',
        'meta',
    ];

    protected $casts = [
        'active' => 'boolean',
        'enable_update_hubdb' => 'boolean',
        'last_executed_at' => 'datetime',
        'payload_mapping' => 'array',
        'meta' => 'array',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function to_event(): BelongsTo
    {
        return $this->belongsTo(self::class, 'to_event_id');
    }

    public function from_events(): HasMany
    {
        return $this->hasMany(self::class, 'to_event_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function propertyRelationships(): HasMany
    {
        return $this->hasMany(PropertyRelationship::class);
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_event')
            ->withTimestamps();
    }

    public function eventTriggers(): HasMany
    {
        return $this->hasMany(EventTrigger::class);
    }

    public function httpConfig(): HasOne
    {
        return $this->hasOne(EventHttpConfig::class);
    }

    public function idempotencyKeys(): HasMany
    {
        return $this->hasMany(EventIdempotencyKey::class);
    }

    public function getMethodName(): ?string
    {
        if (is_string($this->method_name) && trim($this->method_name) !== '') {
            return trim($this->method_name);
        }

        $eventTypeId = strtolower(trim((string) ($this->event_type_id ?? '')));
        $mappedCoreMethod = match ($eventTypeId) {
            'product.updated' => 'updateProducts',
            'product.created' => 'createProducts',
            default => null,
        };

        if ($mappedCoreMethod) {
            return $mappedCoreMethod;
        }

        if (($this->platform?->type ?? null) === 'hubspot') {
            $subscriptionType = strtolower(trim((string) ($this->subscription_type ?: $this->event_type_id ?: '')));

            $mappedMethod = match ($subscriptionType) {
                'contact.propertychange' => 'contactPropertyChange',
                'company.propertychange' => 'companyPropertyChange',
                'deal.propertychange' => 'dealPropertyChange',
                'object.propertychange' => 'objectPropertyChange',
                'invoice.propertychange' => 'invoicePropertyChange',
                'hubspot.property.changed' => 'objectPropertyChange',
                'contact.creation' => 'contactCreatedWebhook',
                'company.creation' => 'companyCreatedWebhook',
                default => null,
            };

            if ($mappedMethod) {
                return $mappedMethod;
            }
        }

        if (($this->platform?->type ?? null) === 'odoo') {
            $subscriptionType = strtolower(trim((string) ($this->subscription_type ?: $this->event_type_id ?: $this->name ?: '')));

            $mappedMethod = match ($subscriptionType) {
                'odoo.partner.created.company',
                'odoo.partner.created',
                'company.created' => 'resPartnerCreateCompany',
                'odoo.partner.updated.company',
                'odoo.partner.updated',
                'account.partner',
                'res.partner',
                'company.updated' => 'resPartnerUpdate',
                'odoo.sync.create.products',
                'odoo.product.created',
                'product.created' => 'syncCreateProducts',
                'odoo.sync.update.products',
                'odoo.product.updated',
                'product.updated' => 'syncUpdateProducts',
                'odoo.create.sale.order',
                'sale_order.created' => 'createSaleOrder',
                'odoo.create.sale.subscription',
                'invoice.recurring.created',
                'quotes.sending_data' => 'createSaleSubscription',
                'account.move',
                'invoice.created',
                'object.updated' => 'accountMoveCreatedUpdated',
                'odoo.sale_order.canceled' => 'saleOrderCanceled',
                'odoo.sale_subscription.canceled' => 'saleSubscriptionCanceled',
                default => null,
            };

            if ($mappedMethod) {
                return $mappedMethod;
            }
        }

        return null;
    }

    public function getSubscriptionType(): ?string
    {
        return $this->subscription_type ?: $this->event_type_id;
    }

    public function getEventTypeEnum(): ?EventType
    {
        return EventType::tryFrom((string) $this->event_type_id);
    }

    public function getEventTypeLabel(): ?string
    {
        return $this->getEventTypeEnum()?->label();
    }

    public function getEventClass(): ?string
    {
        $eventClass = $this->getEventTypeEnum()?->eventClass();
        if ($eventClass) {
            return $eventClass;
        }

        if (($this->platform?->type ?? null) !== 'odoo') {
            return null;
        }

        $eventType = strtolower(trim((string) ($this->subscription_type ?: $this->event_type_id ?: $this->name ?: '')));

        return match ($eventType) {
            'odoo.partner.created.company',
            'odoo.partner.created',
            'company.created' => \App\Events\Company\CreateCompanyEvent::class,
            'odoo.partner.updated.company',
            'odoo.partner.updated',
            'account.partner',
            'res.partner',
            'company.updated' => \App\Events\Company\UpdateCompanyEvent::class,
            'odoo.sync.create.products',
            'odoo.product.created',
            'product.created' => \App\Events\Product\CreateProductEvent::class,
            'odoo.sync.update.products',
            'odoo.product.updated',
            'product.updated' => \App\Events\Product\UpdateProductEvent::class,
            'odoo.create.sale.order',
            'sale_order.created' => \App\Events\SaleOrder\CreateSaleOrderEvent::class,
            'odoo.create.sale.subscription',
            'invoice.recurring.created',
            'quotes.sending_data' => \App\Events\Invoice\CreateRecurringInvoiceEvent::class,
            'account.move',
            'invoice.created' => \App\Events\Invoice\CreateInvoiceEvent::class,
            'object.updated' => \App\Events\Object\UpdateObjectEvent::class,
            default => null,
        };
    }
}

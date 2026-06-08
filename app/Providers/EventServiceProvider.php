<?php

namespace App\Providers;

use App\Events\Company\CreateCompanyEvent;
use App\Events\Company\UpdateCompanyEvent;
use App\Events\Contact\CreateContactEvent;
use App\Events\Contact\UpdateContactEvent;
use App\Events\Generic\ExternalCallEvent;
use App\Events\Invoice\CreateInvoiceEvent;
use App\Events\Invoice\CreateRecurringInvoiceEvent;
use App\Events\NextEvent;
use App\Events\Object\UpdateObjectEvent;
use App\Events\Odoo\GetListPricesEvent;
use App\Events\Odoo\GetStoreProductsEvent;
use App\Events\Product\CreateProductEvent;
use App\Events\Product\UpdateProductEvent;
use App\Events\Quotes\SendingQuotesDataEvent;
use App\Events\Response\SendResponseEvent;
use App\Events\SaleOrder\CreateSaleOrderEvent;
use App\Listeners\Company\CreateCompanyListener;
use App\Listeners\Company\UpdateCompanyListener;
use App\Listeners\Contact\CreateContactListener;
use App\Listeners\Contact\UpdateContactListener;
use App\Listeners\Generic\ExternalCallListener;
use App\Listeners\Invoice\CreateInvoiceListener;
use App\Listeners\Invoice\CreateRecurringInvoiceListener;
use App\Listeners\NextEventListener;
use App\Listeners\Object\UpdateObjectListener;
use App\Listeners\Odoo\GetListPricesListener;
use App\Listeners\Odoo\GetStoreProductsListener;
use App\Listeners\Product\CreateProductListener;
use App\Listeners\Product\UpdateProductListener;
use App\Listeners\Quotes\SendingQuotesDataListener;
use App\Listeners\Response\SendResponseListener;
use App\Listeners\SaleOrder\CreateSaleOrderListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        CreateCompanyEvent::class => [
            CreateCompanyListener::class,
        ],
        UpdateCompanyEvent::class => [
            UpdateCompanyListener::class,
        ],
        CreateContactEvent::class => [
            CreateContactListener::class,
        ],
        UpdateContactEvent::class => [
            UpdateContactListener::class,
        ],
        CreateProductEvent::class => [
            CreateProductListener::class,
        ],
        UpdateProductEvent::class => [
            UpdateProductListener::class,
        ],
        CreateInvoiceEvent::class => [
            CreateInvoiceListener::class,
        ],
        CreateRecurringInvoiceEvent::class => [
            CreateRecurringInvoiceListener::class,
        ],
        CreateSaleOrderEvent::class => [
            CreateSaleOrderListener::class,
        ],
        SendingQuotesDataEvent::class => [
            SendingQuotesDataListener::class,
        ],
        SendResponseEvent::class => [
            SendResponseListener::class,
        ],
        UpdateObjectEvent::class => [
            UpdateObjectListener::class,
        ],
        GetListPricesEvent::class => [
            GetListPricesListener::class,
        ],
        GetStoreProductsEvent::class => [
            GetStoreProductsListener::class,
        ],
        NextEvent::class => [
            NextEventListener::class,
        ],
        ExternalCallEvent::class => [
            ExternalCallListener::class,
        ],
    ];

    /**
     * Disable auto-discovery to avoid duplicate listener registration.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

<?php

namespace App\Services\Odoo;

use App\Models\Event;
use App\Models\Platform;
use App\Models\PropertyRelationship;
use App\Models\Record;
use App\Services\Base\BaseService;
use Illuminate\Support\Arr;

class OdooService extends BaseService
{
    protected OdooApiService $odooApiService;

    public function __construct(
        Platform $platform,
        ?Event $event = null,
        ?Record $record = null,
        $odooApiService = null
    ) {
        parent::__construct($platform, $event, $record);

        $this->odooApiService = $odooApiService instanceof OdooApiService ? $odooApiService : app()->make(OdooApiService::class, [
            'platform' => $platform,
        ]);
    }

    public function resPartnerCreateCompany(array $payload): array
    {
        $partnerResult = $this->createUpdatePartner($payload, [], 'company');
        $partnerId = (int) ($partnerResult['id'] ?? 0);

        if ($partnerId <= 0) {
            return [
                'success' => false,
                'message' => 'Odoo company creation failed.',
                'data' => [
                    'reason' => 'partner_create_update_failed',
                    'partner_result' => $partnerResult,
                ],
            ];
        }

        return $this->success('Odoo company created or updated.', [
            'id' => $partnerId,
            'operation' => $partnerResult['operation'] ?? 'created',
            'matched_by' => $partnerResult['matched_by'] ?? null,
            'output_payload' => [
                'id' => $payload['hubspot_object_id'] ?? $payload['id'] ?? Arr::get($payload, 'company.id'),
                'properties' => [
                    'odoo_id' => $partnerId,
                    'sync_status_odoo' => 'success',
                    'last_sync_odoo' => now()->toISOString(),
                    'last_error_odoo' => '',
                ],
            ],
        ]);
    }

    public function resPartnerCreateContact(): void
    {
        if (! $this->record) {
            return;
        }

        $payload = $this->record->payload ?? [];
        $response = $this->tryExecuteKw('res.partner', 'create', [[
            'name' => Arr::get($payload, 'name') ?? trim((Arr::get($payload, 'firstname', '').' '.Arr::get($payload, 'lastname', ''))),
            'email' => Arr::get($payload, 'email'),
            'phone' => Arr::get($payload, 'phone'),
            'type' => 'contact',
        ]]);

        $this->record->update([
            'status' => $response['success'] ? 'success' : 'error',
            'message' => $response['success']
                ? 'Odoo contact created.'
                : 'Odoo contact creation failed.',
            'details' => $response['success']
                ? ['id' => Arr::get($response, 'data.result')]
                : ['error' => $response['error'] ?? null],
        ]);
    }

    public function resPartnerCreateOrUpdateContact(array $payload): array
    {
        $parentId = Arr::get($payload, 'parent_id')
            ?? Arr::get($payload, 'company.odoo_id')
            ?? Arr::get($payload, 'company.partner_id');

        $partnerResult = $this->createUpdatePartner(
            $payload,
            [],
            'contact',
            is_numeric($parentId) ? (int) $parentId : null
        );
        $partnerId = (int) ($partnerResult['id'] ?? 0);

        if ($partnerId <= 0) {
            return [
                'success' => false,
                'message' => 'Odoo contact creation/update failed.',
                'data' => [
                    'reason' => 'contact_create_update_failed',
                    'partner_result' => $partnerResult,
                ],
            ];
        }

        return $this->success('Odoo contact created or updated.', [
            'id' => $partnerId,
            'operation' => $partnerResult['operation'] ?? 'created',
            'matched_by' => $partnerResult['matched_by'] ?? null,
            'output_payload' => [
                'id' => $payload['hubspot_object_id'] ?? $payload['hubspot_contact_id'] ?? $payload['id'] ?? Arr::get($payload, 'contact.id'),
                'properties' => [
                    'odoo_id' => $partnerId,
                    'sync_status_odoo' => 'success',
                    'last_sync_odoo' => now()->toISOString(),
                    'last_error_odoo' => '',
                ],
            ],
        ]);
    }

    public function createUpdateContact(array $companyData, array $relatedPropertiesMap = [], string $contactType = 'company', ?int $parentId = null): int
    {
        return (int) ($this->createUpdatePartner($companyData, $relatedPropertiesMap, $contactType, $parentId)['id'] ?? 0);
    }

    /**
     * @return array{id:int, operation:string, matched_by?:string|null}
     */
    public function createUpdatePartner(array $companyData, array $relatedPropertiesMap = [], string $contactType = 'company', ?int $parentId = null): array
    {
        $partnerPayload = $this->buildPartnerPayload($companyData, $relatedPropertiesMap, $contactType, $parentId);

        if (empty($partnerPayload['name']) && empty($partnerPayload['email']) && empty($partnerPayload['vat'])) {
            return [
                'id' => 0,
                'operation' => 'error',
            ];
        }

        $existing = $this->findExistingPartner($partnerPayload);
        $existingId = (int) ($existing['id'] ?? 0);
        if ($existingId > 0) {
            $response = $this->tryExecuteKw('res.partner', 'write', [[$existingId], $partnerPayload]);

            $error = $response['error'] ?? null;

            return [
                'id' => $response['success'] ? $existingId : 0,
                'operation' => $response['success'] ? 'updated' : 'error',
                'matched_by' => $existing['matched_by'] ?? null,
                'error' => $error,
            ];
        }

        $response = $this->tryExecuteKw('res.partner', 'create', [$partnerPayload]);

        $id = Arr::get($response, 'data.result');

        return [
            'id' => is_numeric($id) ? (int) $id : 0,
            'operation' => is_numeric($id) ? 'created' : 'error',
            'matched_by' => null,
            'error' => $response['success'] ? null : ($response['error'] ?? null),
        ];
    }

    public function syncCreateProducts(): array
    {
        return $this->syncProducts('create');
    }

    public function syncUpdateProducts(): array
    {
        return $this->syncProducts('update');
    }

    public function createSaleOrder(array $data): array
    {
        $response = $this->tryExecuteKw('sale.order', 'create', [[
            'partner_id' => $data['partner_id'] ?? null,
            'origin' => $data['origin'] ?? null,
            'note' => $data['note'] ?? null,
        ]]);

        if (! $response['success']) {
            return [
                'success' => false,
                'message' => 'Odoo sale order creation failed.',
                'data' => ['error' => $response['error'] ?? null],
            ];
        }

        return $this->success('Odoo sale order created.', [
            'id' => Arr::get($response, 'data.result'),
        ]);
    }

    public function createSaleSubscription(array $data): array
    {
        $quotes = Arr::get($data, 'quotes');
        if (is_array($quotes) && array_is_list($quotes)) {
            $created = [];
            $errors = [];
            $outputPayload = [];

            foreach ($quotes as $index => $quote) {
                if (! is_array($quote)) {
                    $errors[] = [
                        'index' => $index,
                        'error' => 'Invalid quote payload.',
                    ];

                    continue;
                }

                $result = $this->createSaleSubscription($this->buildSaleSubscriptionPayloadFromProcessedQuote($quote));
                if ($result['success'] ?? false) {
                    $created[] = $result['data'];
                    $outputPayload[] = $this->buildHubspotQuoteWritebackPayload($quote, $result['data']);
                } else {
                    $errors[] = [
                        'index' => $index,
                        'quote_id' => Arr::get($quote, 'quote_id'),
                        'error' => $result['message'] ?? 'Odoo sale subscription creation failed.',
                        'details' => $result['data'] ?? [],
                    ];
                    $outputPayload[] = $this->buildHubspotQuoteErrorPayload($quote, $result);
                }
            }

            return [
                'success' => empty($errors) || ! empty($created),
                'status' => empty($errors) ? null : 'warning',
                'message' => empty($errors)
                    ? 'Odoo sale subscriptions created.'
                    : 'Odoo sale subscription creation completed with warnings.',
                'data' => [
                    'created_count' => count($created),
                    'error_count' => count($errors),
                    'created' => $created,
                    'errors' => $errors,
                    'output_payload' => $outputPayload,
                ],
            ];
        }

        $subscriptionModel = $this->setting('odoo.models.sale_subscription', 'dp.sale.subscription');
        $quote = Arr::get($data, 'general', $data);
        $companyPartnerId = $this->normalizeMany2OneId(Arr::get($data, 'company.partner_id'));
        if ($companyPartnerId === null) {
            return [
                'success' => false,
                'message' => 'Missing Odoo company partner id for subscription creation.',
                'data' => [
                    'reason' => 'missing_company_partner_id',
                    'expected_source' => 'company.partner_id',
                ],
            ];
        }

        $quote['partner_id'] = $companyPartnerId;
        $quote['partner_invoice_id'] = $this->normalizeMany2OneId(Arr::get($data, 'company.partner_invoice_id')) ?? $companyPartnerId;
        $quote['partner_shipping_id'] = $this->normalizeMany2OneId(Arr::get($data, 'company.partner_shipping_id')) ?? $companyPartnerId;

        $existingSubscription = $this->findExistingSaleSubscription($quote);
        if ($existingSubscription !== null) {
            return $this->success('Odoo sale subscription already exists.', [
                'id' => Arr::get($existingSubscription, 'id'),
                'model' => $subscriptionModel,
                'operation' => 'already_exists',
                'existing' => $existingSubscription,
                'quote_identifiers' => $this->saleSubscriptionLookupCandidates($quote),
            ]);
        }

        if (! empty($data['products']) && is_array($data['products'])) {
            $quote['order_line_ids'] = array_map(
                fn (array $product): array => [0, 0, $this->normalizeSaleLine($product)],
                $data['products']
            );
        }

        foreach ($this->defaults() as $key => $value) {
            $quote[$key] ??= $value;
        }

        $normalizedHeader = $this->normalizeSaleSubscriptionHeaderFields($quote);
        if ($normalizedHeader['unresolved'] !== []) {
            return [
                'success' => false,
                'message' => 'Odoo sale subscription has unresolved relational fields.',
                'data' => [
                    'reason' => 'unresolved_sale_subscription_relational_fields',
                    'unresolved' => $normalizedHeader['unresolved'],
                ],
            ];
        }

        $quote = $normalizedHeader['payload'];
        $quote = $this->filterSaleSubscriptionPayload($quote);

        $response = $this->tryExecuteKw($subscriptionModel, 'create', [$quote]);

        if (! $response['success']) {
            return [
                'success' => false,
                'message' => 'Odoo sale subscription creation failed.',
                'data' => ['error' => $response['error'] ?? null],
            ];
        }

        return $this->success('Odoo sale subscription created.', [
            'id' => Arr::get($response, 'data.result'),
            'model' => $subscriptionModel,
            'operation' => 'created',
        ]);
    }

    public function saleOrderCanceled(array $data): array
    {
        $targets = $this->resolveSaleOrderCancelTargets($data);
        if ($targets === []) {
            return [
                'success' => false,
                'message' => 'Missing sale order identifier for cancel operation.',
                'data' => [
                    'accepted_identifiers' => [
                        'sale_order_id',
                        'odoo_id',
                        'x_studio_quote_id',
                        'folio',
                        'hs_quote_number',
                    ],
                ],
            ];
        }

        $canceled = [];
        $warnings = [];
        $errors = [];

        foreach ($targets as $target) {
            $orderId = $target['sale_order_id'] ?? null;
            if (! $orderId) {
                $orderId = $this->findSaleOrderIdForCancelTarget($target);
            }

            if (! $orderId) {
                $warnings[] = [
                    'reason' => 'sale_order_not_found',
                    'target' => $target,
                ];

                continue;
            }

            $response = $this->tryExecuteKw('sale.order', 'action_cancel', [[(int) $orderId]]);
            if (! $response['success']) {
                $errors[] = [
                    'sale_order_id' => (int) $orderId,
                    'target' => $target,
                    'error' => $response['error'] ?? null,
                ];

                continue;
            }

            $canceled[] = [
                'sale_order_id' => (int) $orderId,
                'target' => $target,
                'response' => Arr::get($response, 'data.result'),
            ];
        }

        return [
            'success' => empty($errors) || ! empty($canceled) || ! empty($warnings),
            'status' => (! empty($warnings) || ! empty($errors)) ? 'warning' : null,
            'message' => empty($warnings) && empty($errors)
                ? 'Odoo sale order canceled.'
                : 'Odoo sale order cancel completed with warnings.',
            'data' => [
                'canceled_count' => count($canceled),
                'warning_count' => count($warnings),
                'error_count' => count($errors),
                'canceled' => $canceled,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
        ];
    }

    public function saleSubscriptionCanceled(array $data): array
    {
        $targets = $this->resolveSaleOrderCancelTargets($data);
        if ($targets === []) {
            return [
                'success' => false,
                'message' => 'Missing sale subscription identifier for cancel operation.',
                'data' => [
                    'accepted_identifiers' => [
                        'subscription_id',
                        'odoo_subscription_id',
                        'odoo_id',
                        'x_studio_quote_id',
                        'folio',
                        'hs_quote_number',
                    ],
                ],
            ];
        }

        $model = $this->setting('odoo.models.sale_subscription', 'dp.sale.subscription');
        $canceled = [];
        $warnings = [];
        $errors = [];
        $outputPayload = [];

        foreach ($targets as $target) {
            $subscriptionId = $target['subscription_id'] ?? null;
            if (! $subscriptionId) {
                $subscription = $this->findSaleSubscriptionForCancelTarget($target, $model);
                $subscriptionId = $subscription['id'] ?? null;
            } else {
                $subscription = ['id' => $subscriptionId];
            }

            if (! $subscriptionId) {
                $warnings[] = [
                    'reason' => 'sale_subscription_not_found',
                    'target' => $target,
                    'model' => $model,
                ];
                $outputPayload[] = $this->buildSaleSubscriptionCancelWritebackPayload($target, [
                    'operation' => 'not_found',
                    'status' => 'warning',
                    'error' => 'Odoo sale subscription was not found for archived quote.',
                    'model' => $model,
                ]);

                continue;
            }

            $response = $this->cancelSaleSubscription((int) $subscriptionId, $model);
            if (! ($response['success'] ?? false)) {
                $errors[] = [
                    'subscription_id' => (int) $subscriptionId,
                    'target' => $target,
                    'model' => $model,
                    'error' => $response['error'] ?? null,
                ];
                $outputPayload[] = $this->buildSaleSubscriptionCancelWritebackPayload($target, [
                    'operation' => 'cancel_error',
                    'status' => 'error',
                    'subscription_id' => (int) $subscriptionId,
                    'error' => $response['error']['message'] ?? $response['error'] ?? 'Odoo sale subscription cancel failed.',
                    'model' => $model,
                ]);

                continue;
            }

            $canceled[] = [
                'subscription_id' => (int) $subscriptionId,
                'model' => $model,
                'target' => $target,
                'subscription' => $subscription ?? null,
                'response' => Arr::get($response, 'data.result'),
            ];
            $outputPayload[] = $this->buildSaleSubscriptionCancelWritebackPayload($target, [
                'operation' => 'cancelled',
                'status' => 'cancelled',
                'subscription_id' => (int) $subscriptionId,
                'model' => $model,
            ]);
        }

        return [
            'success' => empty($errors) || ! empty($canceled) || ! empty($warnings),
            'status' => (! empty($warnings) || ! empty($errors)) ? 'warning' : null,
            'message' => empty($warnings) && empty($errors)
                ? 'Odoo sale subscription canceled.'
                : 'Odoo sale subscription cancel completed with warnings.',
            'data' => [
                'canceled_count' => count($canceled),
                'warning_count' => count($warnings),
                'error_count' => count($errors),
                'canceled' => $canceled,
                'warnings' => $warnings,
                'errors' => $errors,
                'output_payload' => $outputPayload,
            ],
        ];
    }

    public function accountMovePosted(string $subscriptionType, array $payload, $record): array
    {
        return $this->accountMoveCreatedUpdated($payload);
    }

    public function accountMoveCreatedUpdated(array $payload): array
    {
        $invoiceId = $payload['id'] ?? Arr::get($payload, 'invoice.id');
        if (! $invoiceId) {
            return [
                'success' => false,
                'message' => 'Missing Odoo account.move id.',
                'data' => ['reason' => 'missing_account_move_id'],
            ];
        }

        $invoiceResponse = $this->tryExecuteKw('account.move', 'search_read', [[['id', '=', (int) $invoiceId]]], [
            'limit' => 1,
        ]);

        if (! $invoiceResponse['success']) {
            return [
                'success' => false,
                'message' => 'Odoo account.move lookup failed.',
                'data' => ['error' => $invoiceResponse['error'] ?? null],
            ];
        }

        $invoice = Arr::get($invoiceResponse, 'data.result.0');
        if (! is_array($invoice)) {
            return [
                'success' => false,
                'message' => 'Odoo account.move was not found.',
                'data' => ['invoice_id' => (int) $invoiceId],
            ];
        }

        foreach (['state', 'stated'] as $stateKey) {
            if (! isset($invoice[$stateKey]) && isset($payload[$stateKey]) && is_scalar($payload[$stateKey])) {
                $invoice[$stateKey] = $payload[$stateKey];
            }
        }

        $invoice['status_in_payment'] ??= $this->firstScalar([
            Arr::get($invoice, 'payment_state'),
            Arr::get($invoice, 'state'),
            Arr::get($invoice, 'stated'),
        ]);

        if (($invoice['move_type'] ?? null) !== 'out_invoice') {
            return [
                'success' => true,
                'status' => 'warning',
                'message' => 'Odoo account.move ignored because it is not an outgoing invoice.',
                'data' => [
                    'reason' => 'unsupported_move_type',
                    'invoice_id' => (int) $invoiceId,
                    'move_type' => $invoice['move_type'] ?? null,
                    'invoice' => $invoice,
                    'output_payload' => [],
                ],
            ];
        }

        $warnings = [];
        $saleOrder = null;
        $origin = $invoice['invoice_origin'] ?? null;
        if (is_string($origin) && trim($origin) !== '') {
            $saleOrderResponse = $this->tryExecuteKw(
                $this->setting('odoo.models.sale_subscription', 'dp.sale.subscription'),
                'search_read',
                [[['name', '=', $origin]]],
                ['limit' => 1]
            );

            if ($saleOrderResponse['success']) {
                $saleOrder = Arr::get($saleOrderResponse, 'data.result.0');
                if (! is_array($saleOrder)) {
                    $warnings[] = [
                        'reason' => 'sale_subscription_not_found',
                        'invoice_origin' => $origin,
                    ];
                    $saleOrder = null;
                }
            } else {
                $warnings[] = [
                    'reason' => 'sale_subscription_lookup_failed',
                    'invoice_origin' => $origin,
                    'error' => $saleOrderResponse['error'] ?? null,
                ];
            }
        } else {
            $warnings[] = [
                'reason' => 'missing_invoice_origin',
                'invoice_id' => (int) $invoiceId,
            ];
        }

        $dealId = $this->firstScalar([
            Arr::get($saleOrder, 'x_studio_deal_id'),
            Arr::get($saleOrder, 'x_studio_hubspot_deal_id'),
            Arr::get($invoice, 'x_studio_deal_id'),
            Arr::get($payload, 'deal_id'),
            Arr::get($payload, 'hubspot_deal_id'),
        ]);

        if ($dealId === null) {
            $warnings[] = [
                'reason' => 'hubspot_deal_id_missing',
                'invoice_id' => (int) $invoiceId,
                'invoice_origin' => $origin,
            ];
        }

        $outputPayload = [
            ...$invoice,
            'invoice' => $invoice,
            'saleOrder' => $saleOrder,
            'deal_id' => $dealId,
            'hubspot_deal_id' => $dealId,
            'warnings' => $warnings,
        ];

        return [
            'success' => true,
            'status' => $warnings === [] ? null : 'warning',
            'message' => $warnings === []
                ? 'Odoo invoice payload prepared.'
                : 'Odoo invoice payload prepared with warnings.',
            'data' => [
            'invoice' => $invoice,
            'saleOrder' => $saleOrder,
                'deal_id' => $dealId,
                'warnings' => $warnings,
                'output_payload' => $outputPayload,
            ],
        ];
    }

    public function resPartnerUpdate(string $subscriptionType, array $payload, $record): array
    {
        $partnerId = $payload['id'] ?? Arr::get($payload, 'partner.id');
        if (! $partnerId) {
            return [
                'success' => false,
                'message' => 'Missing Odoo res.partner id.',
                'data' => [
                    'reason' => 'missing_partner_id',
                    'subscription_type' => $subscriptionType,
                ],
            ];
        }

        $response = $this->tryExecuteKw('res.partner', 'search_read', [[['id', '=', (int) $partnerId]]], [
            'limit' => 1,
        ]);

        if (! $response['success']) {
            return [
                'success' => false,
                'message' => 'Odoo partner lookup failed.',
                'data' => ['error' => $response['error'] ?? null],
            ];
        }

        $partner = Arr::get($response, 'data.result.0');
        if (! is_array($partner)) {
            return [
                'success' => false,
                'message' => 'Odoo partner was not found.',
                'data' => ['partner_id' => (int) $partnerId],
            ];
        }

        $hubspotId = $partner['x_studio_id_hubspot']
            ?? $partner['x_studio_hubspot_id']
            ?? $payload['hubspot_object_id']
            ?? null;

        return $this->success('Odoo partner payload prepared.', [
            'partner' => $partner,
            'output_payload' => [
                'id' => $hubspotId,
                'properties' => $partner,
                'source' => [
                    'platform' => 'odoo',
                    'model' => 'res.partner',
                    'id' => (int) $partnerId,
                ],
            ],
        ]);
    }

    public function getListPricesByProduct(array $variant, $listPriceRecord): array
    {
        $productTemplateId = $this->normalizeMany2OneId($variant['product_tmpl_id'] ?? $variant['template_id'] ?? null);
        if (! $productTemplateId) {
            return [
                'success' => false,
                'message' => 'Missing product template id for list price lookup.',
                'data' => [],
            ];
        }

        $response = $this->tryExecuteKw(
            'product.pricelist.item',
            'search_read',
            [[['product_tmpl_id', '=', (int) $productTemplateId]]],
            ['fields' => ['pricelist_id', 'fixed_price', 'min_quantity'], 'limit' => 100]
        );

        if (! $response['success']) {
            return [
                'success' => false,
                'message' => 'Odoo list price lookup failed.',
                'data' => ['error' => $response['error'] ?? null],
            ];
        }

        $listPrices = $this->hydratePricelistCurrencies(Arr::get($response, 'data.result', []));

        return $this->success('Odoo list prices fetched.', [
            'variant' => $variant,
            'list_prices' => $listPrices,
        ]);
    }

    public function testConnection(): array
    {
        $missing = $this->odooApiService->missingConfigKeys();

        if (! empty($missing)) {
            return [
                'success' => false,
                'message' => 'Odoo credentials are incomplete.',
                'data' => [
                    'configured' => false,
                    'missing' => $missing,
                    'source' => 'platform.credentials/settings',
                ],
            ];
        }

        $auth = $this->tryAuthenticate();
        if (! $auth['success'] || ($auth['uid'] ?? 0) <= 0) {
            return [
                'success' => false,
                'message' => 'Odoo credentials configured but authentication failed.',
                'data' => [
                    'configured' => true,
                    'status_code' => $auth['status_code'] ?? 0,
                    'adapter' => $auth['adapter'] ?? null,
                    'error' => $auth['error'] ?? null,
                ],
            ];
        }

        return [
            'success' => true,
            'message' => 'Odoo credentials validated.',
            'data' => [
                'configured' => true,
                'uid' => $auth['uid'],
                'adapter' => $auth['adapter'] ?? null,
            ],
        ];
    }

    private function success(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    private function tryExecuteKw(string $model, string $method, array $args = [], array $kwargs = []): array
    {
        try {
            return $this->odooApiService->executeKw($model, $method, $args, $kwargs);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => 'Odoo request failed before execution.',
                'error' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function tryAuthenticate(): array
    {
        try {
            return $this->odooApiService->authenticate();
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => 'Odoo authentication failed before request.',
                'error' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function syncProducts(string $operation): array
    {
        $domain = $this->productSyncDomain($operation);
        $fields = $this->productSyncFields();

        $response = $this->tryExecuteKw(
            $this->setting('odoo.models.product', 'product.product'),
            'search_read',
            [$domain],
            [
                'fields' => $fields,
                'limit' => (int) $this->setting('odoo.products.limit', 200),
                'offset' => (int) $this->setting('odoo.products.offset', 0),
                'order' => $this->setting('odoo.products.order', 'id asc'),
            ]
        );

        if (! $response['success']) {
            return [
                'success' => false,
                'message' => 'Odoo product sync ('.$operation.') failed.',
                'data' => ['error' => $response['error'] ?? null],
            ];
        }

        $products = Arr::get($response, 'data.result', []);
        if (! is_array($products)) {
            return [
                'success' => false,
                'message' => 'Odoo product sync returned an invalid product list.',
                'data' => [
                    'reason' => 'invalid_product_response',
                    'response' => Arr::except($response, ['error']),
                ],
            ];
        }

        $products = array_map(fn (array $product): array => $this->hydrateProduct($product), $products);

        return $this->success('Odoo products fetched for '.$operation.' sync.', [
            'operation' => $operation,
            'products' => $products,
            'output_payload' => $products,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveSaleOrderCancelTargets(array $data): array
    {
        $quotes = Arr::get($data, 'quotes');
        if (is_array($quotes) && array_is_list($quotes)) {
            return array_values(array_filter(array_map(
                fn (mixed $quote): ?array => is_array($quote) ? $this->normalizeSaleOrderCancelTarget($quote) : null,
                $quotes
            )));
        }

        $target = $this->normalizeSaleOrderCancelTarget($data);

        return $target === [] ? [] : [$target];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSaleOrderCancelTarget(array $payload): array
    {
        $properties = Arr::get($payload, 'properties', []);
        if (! is_array($properties)) {
            $properties = [];
        }

        $target = [
            'sale_order_id' => $this->firstNumeric([
                Arr::get($payload, 'sale_order_id'),
                Arr::get($payload, 'odoo_sale_order_id'),
                Arr::get($payload, 'odoo_id'),
                Arr::get($properties, 'odoo_sale_order_id'),
                Arr::get($properties, 'odoo_id'),
            ]),
            'subscription_id' => $this->firstNumeric([
                Arr::get($payload, 'subscription_id'),
                Arr::get($payload, 'odoo_subscription_id'),
                Arr::get($payload, 'odoo_id'),
                Arr::get($properties, 'subscription_id'),
                Arr::get($properties, 'odoo_subscription_id'),
                Arr::get($properties, 'odoo_id'),
            ]),
            'quote_id' => $this->firstScalar([
                Arr::get($payload, 'x_studio_quote_id'),
                Arr::get($payload, 'hubspot_quote_id'),
                Arr::get($payload, 'quote_id'),
                Arr::get($payload, 'id'),
                Arr::get($properties, 'x_studio_quote_id'),
                Arr::get($properties, 'hs_object_id'),
            ]),
            'folio' => $this->firstScalar([
                Arr::get($payload, 'folio'),
                Arr::get($payload, 'hs_quote_number'),
                Arr::get($properties, 'folio'),
                Arr::get($properties, 'hs_quote_number'),
                Arr::get($properties, 'hs_title'),
            ]),
            'deal_id' => $this->firstScalar([
                Arr::get($payload, 'deal_id'),
                Arr::get($payload, 'hubspot_deal_id'),
                Arr::get($payload, 'associations.deals.0.id'),
                Arr::get($payload, 'associations.deals.results.0.id'),
                Arr::get($payload, 'raw.associations.deals.0.id'),
                Arr::get($payload, 'raw.associations.deals.results.0.id'),
            ]),
        ];

        return array_filter($target, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function findSaleOrderIdForCancelTarget(array $target): ?int
    {
        $searchFields = $this->setting('odoo.sale_order_cancel.search_fields');
        if (! is_array($searchFields) || $searchFields === []) {
            $searchFields = [
                'quote_id' => ['x_studio_quote_id', 'client_order_ref', 'origin'],
                'folio' => ['name', 'client_order_ref', 'origin'],
            ];
        }

        foreach ($searchFields as $targetKey => $fields) {
            $value = $target[$targetKey] ?? null;
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            foreach ((array) $fields as $field) {
                if (! is_string($field) || trim($field) === '') {
                    continue;
                }

                $response = $this->tryExecuteKw('sale.order', 'search_read', [[[$field, '=', (string) $value]]], [
                    'fields' => ['id', 'name'],
                    'limit' => 1,
                ]);

                if (! $response['success']) {
                    continue;
                }

                $id = Arr::get($response, 'data.result.0.id');
                if (is_numeric($id)) {
                    return (int) $id;
                }
            }
        }

        return null;
    }

    private function findSaleSubscriptionForCancelTarget(array $target, string $model): ?array
    {
        $searchFields = $this->setting('odoo.sale_subscription_cancel.search_fields');
        if (! is_array($searchFields) || $searchFields === []) {
            $searchFields = [
                'quote_id' => ['x_studio_quote_id'],
                'folio' => ['x_studio_folio', 'name'],
            ];
        }

        foreach ($searchFields as $targetKey => $fields) {
            $value = $target[$targetKey] ?? null;
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            foreach ((array) $fields as $field) {
                if (! is_string($field) || trim($field) === '') {
                    continue;
                }

                $response = $this->tryExecuteKw($model, 'search_read', [[[$field, '=', (string) $value]]], [
                    'fields' => ['id', 'name', 'state', 'x_studio_quote_id'],
                    'limit' => 1,
                ]);

                if (! ($response['success'] ?? false)) {
                    continue;
                }

                $subscription = Arr::get($response, 'data.result.0');
                if (is_array($subscription) && is_numeric($subscription['id'] ?? null)) {
                    return $subscription + [
                        'matched_by' => $field,
                        'matched_value' => (string) $value,
                    ];
                }
            }
        }

        return null;
    }

    private function cancelSaleSubscription(int $subscriptionId, string $model): array
    {
        $method = $this->setting('odoo.sale_subscription_cancel.method');
        if (is_string($method) && trim($method) !== '') {
            return $this->tryExecuteKw($model, trim($method), [[$subscriptionId]]);
        }

        return $this->tryExecuteKw($model, 'write', [[$subscriptionId], [
            'state' => $this->setting('odoo.sale_subscription_cancel.cancelled_state', 'cancelled'),
        ]]);
    }

    private function buildSaleSubscriptionCancelWritebackPayload(array $target, array $result): array
    {
        $quoteId = $this->firstScalar([
            $target['quote_id'] ?? null,
            $target['hs_object_id'] ?? null,
        ]);

        $status = (string) ($result['status'] ?? 'cancelled');
        $error = $result['error'] ?? null;
        if (is_array($error)) {
            $error = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return array_filter([
            'id' => $quoteId,
            'quote_id' => $quoteId,
            'hubspot_quote_id' => $quoteId,
            'hubspot_deal_id' => $target['deal_id'] ?? null,
            'deal_id' => $target['deal_id'] ?? null,
            'operation' => $result['operation'] ?? 'cancelled',
            'properties' => [
                'sync_status_odoo' => $status,
                'last_sync_odoo' => now()->toISOString(),
                'last_error_odoo' => is_scalar($error) ? (string) $error : '',
            ],
            'destination_response' => [
                'data' => array_filter([
                    'id' => $result['subscription_id'] ?? null,
                    'model' => $result['model'] ?? $this->setting('odoo.models.sale_subscription', 'dp.sale.subscription'),
                    'operation' => $result['operation'] ?? null,
                    'status' => $status,
                    'target' => $target,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function firstScalar(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate) || trim((string) $candidate) === '') {
                continue;
            }

            return trim((string) $candidate);
        }

        return null;
    }

    private function firstNumeric(array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            if (! is_numeric($candidate)) {
                continue;
            }

            return (int) $candidate;
        }

        return null;
    }

    private function productSyncDomain(string $operation): array
    {
        $configured = $this->setting('odoo.products.domain.'.$operation);
        if (is_array($configured)) {
            return $configured;
        }

        $since = $this->setting('odoo.products.since');
        if (! is_string($since) || trim($since) === '') {
            $lookbackHours = (int) $this->setting('odoo.products.lookback_hours', 5);
            $since = now()->subHours(max(1, $lookbackHours))->format('Y-m-d H:i:s');
        }

        $field = $operation === 'create' ? 'create_date' : 'write_date';

        return [[$field, '>=', $since]];
    }

    /**
     * @return list<string>
     */
    private function productSyncFields(): array
    {
        $configured = $this->setting('odoo.products.fields');
        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_filter($configured, 'is_string')));
        }

        return [
            'id',
            'name',
            'product_tmpl_id',
            'display_name',
            'default_code',
            'lst_price',
            'list_price',
            'is_product_variant',
            'currency_id',
            'qty_available',
            'uom_id',
            'categ_id',
            'product_subscription_pricing_ids',
            'create_date',
            'write_date',
        ];
    }

    private function hydrateProduct(array $product): array
    {
        $listPrices = $this->getListPricesByProduct($product, $this->record);
        if ($listPrices['success'] ?? false) {
            $product['list_prices'] = Arr::get($listPrices, 'data.list_prices', []);
            $product = array_replace($product, $this->buildHubspotCurrencyPriceProperties($product['list_prices']));
        }

        $pricingIds = $product['product_subscription_pricing_ids'] ?? null;
        if (is_array($pricingIds) && isset($pricingIds[0])) {
            $pricingResponse = $this->tryExecuteKw(
                $this->setting('odoo.models.subscription_pricing', 'sale.subscription.pricing'),
                'search_read',
                [[['id', '=', (int) $pricingIds[0]]]],
                ['fields' => ['id', 'name', 'price', 'plan_id'], 'limit' => 1]
            );

            if ($pricingResponse['success']) {
                $product['product_subscription_pricing'] = Arr::get($pricingResponse, 'data.result.0');
                $product['product_subscription_pricing_ids'] = Arr::get($pricingResponse, 'data.result.0.plan_id.1')
                    ?? $product['product_subscription_pricing_ids'];
            }
        }

        return $product;
    }

    /**
     * @param  list<array<string, mixed>>  $listPrices
     * @return list<array<string, mixed>>
     */
    private function hydratePricelistCurrencies(array $listPrices): array
    {
        $pricelistIds = collect($listPrices)
            ->map(fn (array $price): ?int => $this->normalizeMany2OneId($price['pricelist_id'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($pricelistIds === []) {
            return $listPrices;
        }

        $response = $this->tryExecuteKw(
            'product.pricelist',
            'search_read',
            [[['id', 'in', $pricelistIds]]],
            ['fields' => ['id', 'name', 'currency_id'], 'limit' => count($pricelistIds)]
        );

        if (! ($response['success'] ?? false)) {
            return $listPrices;
        }

        $pricelists = collect(Arr::get($response, 'data.result', []))
            ->filter(fn ($pricelist): bool => is_array($pricelist) && isset($pricelist['id']))
            ->keyBy(fn (array $pricelist): int => (int) $pricelist['id']);

        return array_map(function (array $price) use ($pricelists): array {
            $pricelistId = $this->normalizeMany2OneId($price['pricelist_id'] ?? null);
            $pricelist = $pricelistId ? $pricelists->get($pricelistId) : null;

            if (! is_array($pricelist)) {
                return $price;
            }

            $price['pricelist'] = $pricelist;

            if (isset($pricelist['currency_id'])) {
                $price['currency_id'] = $pricelist['currency_id'];
            }

            $currencyCode = $this->normalizeCurrencyCode(
                Arr::get($pricelist, 'currency_id.1')
                    ?? Arr::get($pricelist, 'currency_id.name')
                    ?? Arr::get($pricelist, 'currency_code')
            );

            if ($currencyCode !== null) {
                $price['currency_code'] = $currencyCode;
            }

            return $price;
        }, $listPrices);
    }

    /**
     * @param  list<array<string, mixed>>  $listPrices
     * @return array<string, float|int>
     */
    private function buildHubspotCurrencyPriceProperties(array $listPrices): array
    {
        $propertyByCurrency = $this->setting('odoo.catalogs.price_currency_properties', []);
        if (! is_array($propertyByCurrency) || $propertyByCurrency === []) {
            return [];
        }

        $properties = [];

        foreach ($listPrices as $price) {
            $fixedPrice = Arr::get($price, 'fixed_price');
            if ($fixedPrice === null || $fixedPrice === '' || ! is_numeric($fixedPrice)) {
                continue;
            }

            $currencyCode = $this->normalizeCurrencyCode(
                Arr::get($price, 'currency_code')
                    ?? Arr::get($price, 'currency_id.1')
                    ?? Arr::get($price, 'pricelist.currency_id.1')
            );

            if ($currencyCode === null) {
                continue;
            }

            $property = $propertyByCurrency[$currencyCode]
                ?? $propertyByCurrency[strtolower($currencyCode)]
                ?? null;

            if (! is_string($property) || trim($property) === '') {
                continue;
            }

            $numericPrice = (float) $fixedPrice;
            $properties[$property] = floor($numericPrice) === $numericPrice
                ? (int) $numericPrice
                : $numericPrice;
        }

        return $properties;
    }

    private function normalizeCurrencyCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $code = strtoupper(trim((string) $value));

        return $code !== '' ? $code : null;
    }

    private function buildPartnerPayload(array $data, array $relatedPropertiesMap, string $contactType, ?int $parentId): array
    {
        $payload = [
            'name' => $data['name'] ?? Arr::get($data, 'company.name'),
            'email' => $data['email'] ?? Arr::get($data, 'company.email'),
            'phone' => $data['phone'] ?? Arr::get($data, 'company.phone'),
            'vat' => $data['vat'] ?? Arr::get($data, 'company.vat'),
            'company_type' => $contactType === 'contact' ? 'person' : 'company',
            'is_company' => $contactType !== 'contact',
        ];

        foreach ($data as $key => $value) {
            if (! is_string($key) || $this->isTechnicalPayloadKey($key) || $value === null || $value === '') {
                continue;
            }

            if ($this->isOdooFilePayload($value, $relatedPropertiesMap[$key] ?? null)) {
                $file = $this->normalizeOdooFilePayload($value);
                if ($file !== null) {
                    $payload[$key] = $file['base64'];
                    $payload[$key.'_filename'] = $file['name'];
                }

                continue;
            }

            if (is_scalar($value)) {
                $normalized = $this->normalizeOdooFieldValue($key, $value);
                if ($this->isUnresolvedRelationalValue($normalized)) {
                    continue;
                }

                $payload[$key] = $normalized;
            }
        }

        if ($parentId) {
            $payload['parent_id'] = $parentId;
        }

        foreach ($this->defaults() as $key => $value) {
            if (! is_string($key) || array_key_exists($key, $payload)) {
                continue;
            }

            $normalized = $this->normalizeOdooFieldValue($key, $value);
            if (! $this->isUnresolvedRelationalValue($normalized)) {
                $payload[$key] = $normalized;
            }
        }

        return array_filter($payload, fn ($value): bool => $value !== null
            && $value !== ''
            && ! $this->isUnresolvedRelationalValue($value));
    }

    private function isOdooFilePayload(mixed $value, mixed $type = null): bool
    {
        if (! is_array($value)) {
            return false;
        }

        return $type === 'file'
            || isset($value['base64'])
            || isset($value['content_base64'])
            || isset($value['content']);
    }

    /**
     * @return array{name:string, base64:string}|null
     */
    private function normalizeOdooFilePayload(array $value): ?array
    {
        $base64 = $value['base64'] ?? $value['content_base64'] ?? $value['content'] ?? null;
        $name = $value['name'] ?? $value['filename'] ?? 'file';

        if (! is_scalar($base64) || trim((string) $base64) === '' || ! is_scalar($name)) {
            return null;
        }

        return [
            'name' => (string) $name,
            'base64' => (string) $base64,
        ];
    }

    private function findExistingPartnerId(array $partnerPayload): ?int
    {
        $existing = $this->findExistingPartner($partnerPayload);
        $id = $existing['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array{id:int, matched_by:string, matched_value:string}|null
     */
    private function findExistingPartner(array $partnerPayload): ?array
    {
        $candidates = [
            ['x_studio_hubspot_id', $partnerPayload['x_studio_hubspot_id'] ?? null],
            ['x_studio_id_hubspot', $partnerPayload['x_studio_id_hubspot'] ?? null],
            ['vat', $partnerPayload['vat'] ?? null],
            ['email', $partnerPayload['email'] ?? null],
            ['name', $partnerPayload['name'] ?? null],
        ];

        foreach ($candidates as [$field, $value]) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $response = $this->tryExecuteKw('res.partner', 'search_read', [[[$field, '=', (string) $value]]], [
                'fields' => ['id'],
                'limit' => 1,
            ]);

            if ($response['success']) {
                $id = Arr::get($response, 'data.result.0.id');
                if (is_numeric($id)) {
                    return [
                        'id' => (int) $id,
                        'matched_by' => $field,
                        'matched_value' => (string) $value,
                    ];
                }
            }
        }

        return null;
    }

    private function normalizeOdooFieldValue(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $this->isRelationalField($key) ? $this->unresolvedRelationalValue($key, $value) : $value;
        }

        if (in_array($key, ['date_order', 'create_date', 'write_date'], true) && is_scalar($value)) {
            try {
                return \Carbon\Carbon::parse((string) $value)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return $value;
            }
        }

        $division = Arr::get($this->catalogs(), 'divisions.'.$value);
        if (is_string($division) && $division !== '') {
            return $division;
        }

        $model = Arr::get($this->relationalModels(), $key);
        if (! is_string($model) || $model === '') {
            return $value;
        }

        $catalogId = $this->catalogRelationalId($key, $value);
        if ($catalogId !== null && $this->odooRecordExists($model, $catalogId)) {
            return $catalogId;
        }

        if (is_numeric($value) && $this->odooRecordExists($model, (int) $value)) {
            return (int) $value;
        }

        foreach ($this->relationalLookupCandidates($key, $value) as [$field, $operator, $candidate]) {
            if (! is_scalar($candidate) || trim((string) $candidate) === '') {
                continue;
            }

            $response = $this->tryExecuteKw($model, 'search_read', [[[$field, $operator, (string) $candidate]]], [
                'fields' => ['id', 'name'],
                'limit' => 1,
            ]);

            $id = Arr::get($response, 'data.result.0.id');
            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return $this->unresolvedRelationalValue($key, $value);
    }

    private function normalizeMany2OneId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function buildSaleSubscriptionPayloadFromProcessedQuote(array $quote): array
    {
        $entityResults = Arr::get($quote, 'entity_results', []);
        $companyPartnerId = Arr::get($entityResults, 'company.target_id');
        $companyPartnerId = is_numeric($companyPartnerId) ? (int) $companyPartnerId : $companyPartnerId;
        $partnerInvoiceId = $this->resolveContactPartnerIdByType($quote, 'invoice') ?? $companyPartnerId;
        $partnerShippingId = $this->resolveContactPartnerIdByType($quote, 'delivery') ?? $companyPartnerId;
        $products = [];

        foreach ((array) Arr::get($entityResults, 'products', []) as $product) {
            if (! is_array($product)) {
                continue;
            }

            $fields = Arr::get($product, 'fields', []);
            if (! is_array($fields)) {
                $fields = [];
            }

            $products[] = array_filter([
                'product_template_id' => $this->firstScalar([
                    Arr::get($fields, 'product_template_id'),
                    Arr::get($fields, 'product_tmpl_id'),
                    Arr::get($fields, 'odoo_id'),
                    Arr::get($product, 'target_id'),
                ]),
                'name' => Arr::get($fields, 'name', Arr::get($fields, 'display_name')),
                'quantity' => Arr::get($fields, 'quantity', 1),
                'price_unit' => Arr::get($fields, 'price', Arr::get($fields, 'list_price', Arr::get($fields, 'lst_price'))),
                'discount' => Arr::get($fields, 'hs_discount_percentage', Arr::get($fields, 'discount')),
                'product_uom' => Arr::get($fields, 'product_uom', Arr::get($fields, 'uom_id', Arr::get($fields, 'unidad_de_medida'))),
                'tax_id' => Arr::get($fields, 'tax_id', Arr::get($fields, 'hs_tax_rate')),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $general = array_filter([
            ...$this->buildSaleSubscriptionMappedFields($quote),
            ...[
                'x_studio_quote_id' => Arr::get($quote, 'hubspot_quote_id', Arr::get($quote, 'quote_id')),
            ],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'general' => $general,
            'company' => [
                'partner_id' => $companyPartnerId,
                'partner_invoice_id' => $partnerInvoiceId,
                'partner_shipping_id' => $partnerShippingId,
            ],
            'products' => $products,
            'resolved_associations' => Arr::get($quote, 'resolved_associations', []),
            'source_quote' => [
                'quote_id' => Arr::get($quote, 'quote_id'),
                'hubspot_quote_id' => Arr::get($quote, 'hubspot_quote_id'),
                'deal_id' => $this->resolveHubspotDealIdFromQuote($quote),
            ],
        ];
    }

    private function buildHubspotQuoteWritebackPayload(array $quote, array $result): array
    {
        $operation = (string) Arr::get($result, 'operation', 'created');
        $dealId = $this->resolveHubspotDealIdFromQuote($quote)
            ?? $this->firstScalar([
                Arr::get($result, 'source_quote.deal_id'),
                Arr::get($result, 'deal_id'),
                Arr::get($result, 'hubspot_deal_id'),
            ]);

        return [
            'id' => Arr::get($quote, 'hubspot_quote_id'),
            'hubspot_quote_id' => Arr::get($quote, 'hubspot_quote_id'),
            'quote_id' => Arr::get($quote, 'quote_id'),
            'deal_id' => $dealId,
            'hubspot_deal_id' => $dealId,
            'properties' => [
                'odoo_id' => Arr::get($result, 'id'),
                'sync_status_odoo' => $operation === 'already_exists' ? 'already_exists' : 'success',
                'last_sync_odoo' => now()->toISOString(),
                'last_error_odoo' => '',
            ],
            'destination_response' => [
                'data' => $result,
            ],
            'operation' => $operation,
        ];
    }

    private function buildHubspotQuoteErrorPayload(array $quote, array $result): array
    {
        $dealId = $this->resolveHubspotDealIdFromQuote($quote);

        return [
            'id' => Arr::get($quote, 'hubspot_quote_id'),
            'hubspot_quote_id' => Arr::get($quote, 'hubspot_quote_id'),
            'quote_id' => Arr::get($quote, 'quote_id'),
            'deal_id' => $dealId,
            'hubspot_deal_id' => $dealId,
            'properties' => [
                'sync_status_odoo' => 'error',
                'last_sync_odoo' => now()->toISOString(),
                'last_error_odoo' => $this->summarizeSyncError($result),
            ],
            'destination_response' => [
                'data' => $result['data'] ?? [],
            ],
            'operation' => 'error',
        ];
    }

    private function summarizeSyncError(array $result): string
    {
        $message = $result['message'] ?? Arr::get($result, 'data.error.message') ?? Arr::get($result, 'data.error.faultString') ?? 'Odoo sale subscription sync failed.';

        if (! is_scalar($message)) {
            $message = 'Odoo sale subscription sync failed.';
        }

        return mb_substr(trim((string) $message), 0, 500);
    }

    private function resolveHubspotDealIdFromQuote(array $quote): ?string
    {
        return $this->firstScalar([
            Arr::get($quote, 'deal_id'),
            Arr::get($quote, 'hubspot_deal_id'),
            Arr::get($quote, 'resolved_associations.deal_id'),
            Arr::get($quote, 'resolved_associations.deal.id'),
            Arr::get($quote, 'raw.deal_id'),
            Arr::get($quote, 'raw.hubspot_deal_id'),
            Arr::get($quote, 'raw.deal.id'),
            Arr::get($quote, 'raw.associations.deals.0.id'),
            Arr::get($quote, 'raw.associations.deals.results.0.id'),
            Arr::get($quote, 'raw.associations.deal.0.id'),
            Arr::get($quote, 'associations.deals.0.id'),
            Arr::get($quote, 'associations.deals.results.0.id'),
            Arr::get($quote, 'associations.deal.0.id'),
        ]);
    }

    private function findExistingSaleSubscription(array $quote): ?array
    {
        $model = $this->setting('odoo.models.sale_subscription', 'dp.sale.subscription');
        $searchFields = $this->setting('odoo.sale_subscription.lookup_fields');
        if (! is_array($searchFields) || $searchFields === []) {
            $searchFields = [
                'x_studio_quote_id' => ['x_studio_quote_id'],
                'name' => ['name'],
            ];
        }

        foreach ($this->saleSubscriptionLookupCandidates($quote) as $candidateKey => $value) {
            foreach ((array) ($searchFields[$candidateKey] ?? []) as $field) {
                if (! is_string($field) || trim($field) === '') {
                    continue;
                }

                $response = $this->tryExecuteKw($model, 'search_read', [[[$field, '=', (string) $value]]], [
                    'fields' => ['id', 'name', 'x_studio_quote_id'],
                    'limit' => 1,
                ]);

                if (! ($response['success'] ?? false)) {
                    continue;
                }

                $existing = Arr::get($response, 'data.result.0');
                if (is_array($existing)) {
                    return $existing + [
                        'matched_by' => $field,
                        'matched_value' => (string) $value,
                    ];
                }
            }
        }

        return null;
    }

    private function saleSubscriptionLookupCandidates(array $quote): array
    {
        $candidates = [
            'x_studio_quote_id' => $quote['x_studio_quote_id'] ?? null,
            'name' => $quote['name'] ?? null,
        ];

        return array_filter($candidates, static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '');
    }

    private function buildSaleSubscriptionMappedFields(array $quote): array
    {
        if (! $this->event) {
            return [];
        }

        if (! $this->event->exists && ! $this->event->relationLoaded('propertyRelationships')) {
            return [];
        }

        if (! $this->event->relationLoaded('propertyRelationships')) {
            $this->event->loadMissing(['propertyRelationships.property', 'propertyRelationships.relatedProperty']);
        }

        $mapped = [];

        foreach ($this->event->propertyRelationships as $relationship) {
            if (! $relationship instanceof PropertyRelationship || ! $relationship->active) {
                continue;
            }

            if (! $this->saleSubscriptionRelationshipApplies($relationship)) {
                continue;
            }

            $targetKey = $relationship->relatedProperty?->key ?: $relationship->relatedProperty?->name;
            if (! is_string($targetKey) || trim($targetKey) === '') {
                continue;
            }

            $targetKey = trim($targetKey);
            $value = $this->resolveSaleSubscriptionMappedValue($relationship, $quote);
            if ($value === null || $value === '') {
                continue;
            }

            $meta = is_array($relationship->meta) ? $relationship->meta : [];
            $mode = strtolower((string) ($meta['mode'] ?? $meta['strategy'] ?? 'set'));
            $separator = $this->resolveMappingSeparator($meta['separator'] ?? "\n");
            $existing = data_get($mapped, $targetKey);

            if (in_array($mode, ['append', 'concat'], true) && is_scalar($existing) && trim((string) $existing) !== '') {
                data_set($mapped, $targetKey, trim((string) $existing).$separator.(string) $value);

                continue;
            }

            data_set($mapped, $targetKey, $value);
        }

        return $mapped;
    }

    private function saleSubscriptionRelationshipApplies(PropertyRelationship $relationship): bool
    {
        $meta = is_array($relationship->meta) ? $relationship->meta : [];
        $scope = strtolower(trim((string) ($meta['scope'] ?? $meta['target_scope'] ?? $meta['context'] ?? 'sale_subscription')));

        return in_array($scope, ['sale_subscription', 'subscription', 'general', 'header'], true);
    }

    private function resolveSaleSubscriptionMappedValue(PropertyRelationship $relationship, array $quote): mixed
    {
        $meta = is_array($relationship->meta) ? $relationship->meta : [];

        if (is_string($meta['template'] ?? null) && trim((string) $meta['template']) !== '') {
            return $this->applyMappingTransforms(
                $this->renderSaleSubscriptionTemplate((string) $meta['template'], $quote),
                $meta
            );
        }

        if (isset($meta['sources']) && is_array($meta['sources'])) {
            return $this->buildSaleSubscriptionConcatenatedValue($quote, $meta);
        }

        $sourceKey = $relationship->mapping_key
            ?: ($relationship->property?->key ?: $relationship->property?->name);

        if (! is_string($sourceKey) || trim($sourceKey) === '') {
            return null;
        }

        return $this->applyMappingTransforms(
            $this->normalizeMappedScalar($this->resolveQuoteValueByPath($quote, trim($sourceKey))),
            $meta
        );
    }

    private function buildSaleSubscriptionConcatenatedValue(array $quote, array $meta): ?string
    {
        $separator = $this->resolveMappingSeparator($meta['separator'] ?? "\n");
        $parts = [];

        foreach ($meta['sources'] as $source) {
            $path = null;
            $label = null;
            $template = null;

            if (is_string($source)) {
                $path = $source;
            } elseif (is_array($source)) {
                $path = $source['path'] ?? $source['source'] ?? $source['mapping_key'] ?? null;
                $label = $source['label'] ?? null;
                $template = $source['template'] ?? null;
            }

            if (is_string($template) && trim($template) !== '') {
                $value = $this->renderSaleSubscriptionTemplate($template, $quote);
            } elseif (is_string($path) && trim($path) !== '') {
                $value = $this->normalizeMappedScalar($this->resolveQuoteValueByPath($quote, trim($path)));
            } else {
                continue;
            }

            if (is_array($source)) {
                $value = $this->applyMappingTransforms($value, $source);
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($label) && trim($label) !== '') {
                $parts[] = trim($label).': '.$value;

                continue;
            }

            $parts[] = (string) $value;
        }

        return $parts === [] ? null : $this->applyMappingTransforms(implode($separator, $parts), $meta);
    }

    private function renderSaleSubscriptionTemplate(string $template, array $quote): string
    {
        return trim((string) preg_replace_callback('/\{([^}]+)\}/', function (array $matches) use ($quote): string {
            $value = $this->normalizeMappedScalar($this->resolveQuoteValueByPath($quote, trim((string) $matches[1])));

            return $value === null ? '' : (string) $value;
        }, $template));
    }

    private function resolveQuoteValueByPath(array $quote, string $path): mixed
    {
        $normalizedPath = trim($path);
        $withoutQuotePrefix = str_starts_with($normalizedPath, 'quote.')
            ? substr($normalizedPath, strlen('quote.'))
            : $normalizedPath;

        $candidates = array_values(array_unique(array_filter([
            $normalizedPath,
            $withoutQuotePrefix,
            'raw.'.$normalizedPath,
            'raw.'.$withoutQuotePrefix,
            'raw.properties.'.$normalizedPath,
            'raw.properties.'.$withoutQuotePrefix,
            'properties.'.$normalizedPath,
            'properties.'.$withoutQuotePrefix,
        ])));

        foreach ($candidates as $candidate) {
            $value = data_get($quote, $candidate);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeMappedScalar(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function resolveMappingSeparator(mixed $separator): string
    {
        if (! is_scalar($separator)) {
            return "\n";
        }

        $separator = (string) $separator;

        return $separator === '' ? "\n" : $separator;
    }

    private function applyMappingTransforms(mixed $value, array $meta): mixed
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return $value;
        }

        foreach ($this->mappingTransforms($meta) as $transform) {
            $value = match ($transform) {
                'html_to_text', 'strip_html', 'html_text' => $this->htmlToPlainText((string) $value),
                'squish' => preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value),
                'trim' => trim((string) $value),
                default => $value,
            };
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function mappingTransforms(array $meta): array
    {
        $transforms = [];
        foreach (['transform', 'sanitize', 'format'] as $key) {
            if (is_scalar($meta[$key] ?? null)) {
                $transforms[] = strtolower(trim((string) $meta[$key]));
            }
        }

        if (isset($meta['transforms']) && is_array($meta['transforms'])) {
            foreach ($meta['transforms'] as $transform) {
                if (is_scalar($transform)) {
                    $transforms[] = strtolower(trim((string) $transform));
                }
            }
        }

        foreach (['html_to_text', 'strip_html', 'clean_html'] as $flag) {
            if (($meta[$flag] ?? false) === true) {
                $transforms[] = $flag === 'clean_html' ? 'html_to_text' : $flag;
            }
        }

        return array_values(array_unique(array_filter($transforms)));
    }

    private function htmlToPlainText(string $value): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', "\n", $value) ?? $value;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_map(
            static fn (string $line): string => preg_replace('/[ \t]+/u', ' ', trim($line)) ?? trim($line),
            explode("\n", $text)
        );

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''))) ?? '');
    }

    private function filterSaleSubscriptionPayload(array $quote): array
    {
        $allowed = $this->setting('odoo.sale_subscription.allowed_fields');
        if (! is_array($allowed) || $allowed === []) {
            $allowed = [
                'name',
                'partner_id',
                'partner_invoice_id',
                'partner_shipping_id',
                'order_line_ids',
                'x_studio_quote_id',
                'note',
                'company_id',
            ];
        }

        $allowed = array_values(array_unique([
            ...$allowed,
            ...$this->saleSubscriptionMappedTargetFields(),
        ]));

        $allowed = array_flip(array_values(array_filter($allowed, 'is_string')));

        return array_intersect_key($quote, $allowed);
    }

    /**
     * @return array{payload: array<string, mixed>, unresolved: array<int, array<string, mixed>>}
     */
    private function normalizeSaleSubscriptionHeaderFields(array $quote): array
    {
        $unresolved = [];

        foreach ($quote as $key => $value) {
            if (! is_string($key) || ! $this->isRelationalField($key)) {
                continue;
            }

            $normalized = $this->normalizeOdooFieldValue($key, $value);
            if ($this->isUnresolvedRelationalValue($normalized)) {
                $unresolved[] = [
                    'field' => $key,
                    'value' => $value,
                ];
                unset($quote[$key]);

                continue;
            }

            $quote[$key] = $normalized;
        }

        return [
            'payload' => $quote,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function saleSubscriptionMappedTargetFields(): array
    {
        if (! $this->event) {
            return [];
        }

        if (! $this->event->exists && ! $this->event->relationLoaded('propertyRelationships')) {
            return [];
        }

        if (! $this->event->relationLoaded('propertyRelationships')) {
            $this->event->loadMissing(['propertyRelationships.relatedProperty']);
        }

        $fields = [];
        foreach ($this->event->propertyRelationships as $relationship) {
            if (! $relationship instanceof PropertyRelationship || ! $relationship->active) {
                continue;
            }

            if (! $this->saleSubscriptionRelationshipApplies($relationship)) {
                continue;
            }

            $targetKey = $relationship->relatedProperty?->key ?: $relationship->relatedProperty?->name;
            if (! is_string($targetKey) || trim($targetKey) === '' || str_contains($targetKey, '.')) {
                continue;
            }

            $fields[] = trim($targetKey);
        }

        return array_values(array_unique($fields));
    }

    private function normalizeSaleLine(array $product): array
    {
        foreach (['product_template_id', 'product_uom'] as $field) {
            if (isset($product[$field]) && is_numeric($product[$field])) {
                $product[$field] = (int) $product[$field];
            }
        }

        foreach (['quantity', 'price_unit', 'discount'] as $field) {
            if (isset($product[$field]) && is_numeric($product[$field])) {
                $product[$field] = (float) $product[$field];
            }
        }

        $tax = $product['tax_id'] ?? null;
        if ($tax !== null) {
            $mappedTax = $this->catalogValue('taxes', $tax) ?? $tax;
            $product['tax_id'] = [[6, 0, [(int) $mappedTax]]];
        }

        $uom = $product['product_uom'] ?? null;
        if ($uom !== null) {
            $product['product_uom'] = $this->catalogValue('uom', $uom) ?? $uom;
        }

        return $product;
    }

    private function resolveContactPartnerIdByType(array $quote, string $type): mixed
    {
        foreach ((array) Arr::get($quote, 'entity_results.contacts', []) as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            if ($this->contactResultMatchesType($contact, $type)) {
                $id = Arr::get($contact, 'target_id');

                return is_numeric($id) ? (int) $id : $id;
            }
        }

        $singleContact = Arr::get($quote, 'entity_results.contact');
        if (is_array($singleContact) && $this->contactResultMatchesType($singleContact, $type)) {
            $id = Arr::get($singleContact, 'target_id');

            return is_numeric($id) ? (int) $id : $id;
        }

        return null;
    }

    private function contactResultMatchesType(array $contact, string $type): bool
    {
        $candidates = [
            Arr::get($contact, 'fields.type'),
            Arr::get($contact, 'fields.contact_type'),
            Arr::get($contact, 'raw.properties.contact_type'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $candidate = strtolower(trim((string) $candidate));
            if ($candidate === strtolower($type)) {
                return true;
            }

            if ($type === 'invoice' && str_contains($candidate, 'factur')) {
                return true;
            }

            if ($type === 'delivery' && (str_contains($candidate, 'entrega') || str_contains($candidate, 'shipping'))) {
                return true;
            }
        }

        return false;
    }

    private function catalogValue(string $catalog, mixed $value): mixed
    {
        if (! is_scalar($value)) {
            return null;
        }

        $catalogValues = Arr::get($this->catalogs(), $catalog, []);
        if (! is_array($catalogValues)) {
            return null;
        }

        $keys = [(string) $value];
        if (is_numeric($value)) {
            $keys[] = (string) (int) $value;
            $keys[] = number_format((float) $value, 4, '.', '');
        }

        foreach (array_values(array_unique($keys)) as $key) {
            if (array_key_exists($key, $catalogValues)) {
                return $catalogValues[$key];
            }
        }

        return null;
    }

    private function defaults(): array
    {
        return $this->setting('odoo.defaults', []);
    }

    private function catalogs(): array
    {
        return $this->setting('odoo.catalogs', []);
    }

    private function relationalModels(): array
    {
        return array_replace([
            'user_id' => 'res.users',
            'city_id' => 'res.city',
            'state_id' => 'res.country.state',
            'country_id' => 'res.country',
            'company_id' => 'res.company',
            'currency_id' => 'res.currency',
            'cost_currency_id' => 'res.currency',
            'team_id' => 'crm.team',
            'categ_id' => 'product.category',
            'product_uom' => 'uom.uom',
            'uom_id' => 'uom.uom',
            'uom_po_id' => 'uom.uom',
            'taxes_id' => 'account.tax',
            'supplier_taxes_id' => 'account.tax',
        ], $this->setting('odoo.relational_models', []));
    }

    private function isRelationalField(string $key): bool
    {
        return array_key_exists($key, $this->relationalModels());
    }

    private function catalogRelationalId(string $key, mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $catalogs = $this->catalogs();
        $candidates = [
            Arr::get($catalogs, 'ids.'.$key.'.'.$value),
            Arr::get($catalogs, 'relational.'.$key.'.'.$value),
            Arr::get($catalogs, $key.'.'.$value),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<array{0:string,1:string,2:mixed}>
     */
    private function relationalLookupCandidates(string $key, mixed $value): array
    {
        if (! is_scalar($value)) {
            return [];
        }

        $value = trim((string) $value);
        $aliases = Arr::wrap(Arr::get($this->catalogs(), 'aliases.'.$key.'.'.$value, []));

        $candidates = [];
        foreach (array_filter(array_unique([$value, ...$aliases])) as $candidate) {
            if ($key === 'user_id') {
                $candidates[] = ['login', '=', $candidate];
                $candidates[] = ['name', 'ilike', $candidate];
            } elseif ($key === 'country_id') {
                $candidates[] = ['code', '=', $candidate];
                $candidates[] = ['name', 'ilike', $candidate];
            } elseif ($key === 'currency_id' || $key === 'cost_currency_id') {
                $candidates[] = ['name', '=', $candidate];
                $candidates[] = ['name', 'ilike', $candidate];
            } else {
                $candidates[] = ['name', 'ilike', $candidate];
            }
        }

        return $candidates;
    }

    private function odooRecordExists(string $model, int $id): bool
    {
        $response = $this->tryExecuteKw($model, 'search_read', [[['id', '=', $id]]], [
            'fields' => ['id'],
            'limit' => 1,
        ]);

        return is_numeric(Arr::get($response, 'data.result.0.id'));
    }

    /**
     * @return array{__integrador_unresolved_odoo_relational:true, field:string, value:mixed}
     */
    private function unresolvedRelationalValue(string $field, mixed $value): array
    {
        return [
            '__integrador_unresolved_odoo_relational' => true,
            'field' => $field,
            'value' => $value,
        ];
    }

    private function isUnresolvedRelationalValue(mixed $value): bool
    {
        return is_array($value)
            && ($value['__integrador_unresolved_odoo_relational'] ?? false) === true;
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->platform->settings ?? [], $key, $default);
    }

    private function isTechnicalPayloadKey(string $key): bool
    {
        return in_array($key, [
            'id',
            'hubspot_object_id',
            'objectId',
            'properties',
            'products',
            'company',
            'contact',
            'source',
            'destination_response',
            'partner_id',
            'partner_invoice_id',
            'partner_shipping_id',
            'odoo_id',
            'sync_status_odoo',
            'last_sync_odoo',
            'last_error_odoo',
            'sync_to_odoo',
        ], true);
    }
}

<?php

namespace App\Services\Hubspot;

use App\Models\Event;
use App\Models\Record;
use App\Services\Aspel\AspelService;
use App\Services\EventFlowService;
use App\Services\Generic\AuthStrategyResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class AspelQuotePreparationService
{
    public function prepare(Event $event, array $payload, HubspotApiServiceRefactored $api): array
    {
        $dealId = (string) ($payload['hubspotDealId'] ?? $payload['objectId'] ?? $payload['hubspot_object_id'] ?? '');
        $context = app(HubspotLineItemCustomerContextService::class);
        $quotes = $context->associations($api, 'deals', $dealId, 'quotes');
        if (! $quotes['success']) {
            return $this->blocked($api, $dealId, 'quote_associations_failed', $quotes);
        }
        $candidates = [];
        $attemptedQuoteIds = $this->attemptedQuoteIds($event);
        foreach (array_unique(array_column($quotes['items'], 'toObjectId')) as $id) {
            $result = $api->getObject('quotes', (string) $id, $this->properties($event, 'quote', [
                'sync_status_aspel', 'last_sync_aspel', 'aspel_cve_doc', 'hs_createdate',
            ]));
            if (! ($result['success'] ?? false)) {
                return $this->blocked($api, $dealId, 'quote_fetch_failed', $result);
            }
            $quote = $result['data'];
            if ($this->wasAlreadyProcessed($quote, (string) $id, $attemptedQuoteIds)) {
                continue;
            }
            $candidates[(string) $id] = $quote;
        }
        if (count($candidates) !== 1) {
            return $this->blocked($api, $dealId, 'missing_or_ambiguous_quote', ['candidate_ids' => array_keys($candidates)]);
        }
        $quoteId = (string) array_key_first($candidates);
        $quote = $candidates[$quoteId];
        $customer = $context->resolveForDeal($api, $dealId, $event->meta ?? []);
        if (! $customer['success']) {
            return $this->blocked($api, $dealId, $customer['reason'], $customer, $quoteId);
        }
        $contactId = $customer['context']['hubspot_customer_contact_id'];
        $contact = $api->getObject('contacts', $contactId, $this->properties($event, 'contact', ['firstname', 'lastname']));
        $deal = $api->getObject('deals', $dealId, $this->properties($event, 'deal', []));
        if (! ($contact['success'] ?? false) || ! ($deal['success'] ?? false)) {
            return $this->blocked($api, $dealId, 'customer_or_deal_fetch_failed', ['contact' => $contact, 'deal' => $deal], $quoteId);
        }
        $source = ['contact' => $contact['data'], 'quote' => $quote, 'deal' => $deal['data']];
        $source['contact_name'] = trim(implode(' ', array_filter([
            Arr::get($contact, 'data.properties.firstname'), Arr::get($contact, 'data.properties.lastname'),
        ], static fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')));
        $header = $this->mapped($event, $source, 'header');
        $header['hubspotDealId'] = $dealId;
        $header['hubspotQuoteId'] = $quoteId;
        $warehouseEvent = Event::with('platform')->find($event->meta['warehouse_event_id'] ?? null);
        $destination = $event->to_event;
        if (! $warehouseEvent || ! $destination) {
            return $this->blocked($api, $dealId, 'missing_quote_flow_configuration', [], $quoteId);
        }
        $warehouse = new AspelService($warehouseEvent->platform, app(AuthStrategyResolver::class), $warehouseEvent);
        $lines = $context->associations($api, 'quotes', $quoteId, 'line_items');
        if (! $lines['success']) {
            return $this->blocked($api, $dealId, 'line_item_associations_failed', $lines, $quoteId);
        }
        $header['partidas'] = [];
        foreach (array_unique(array_column($lines['items'], 'toObjectId')) as $lineId) {
            $line = $api->getObject('line_items', (string) $lineId, $this->properties($event, 'line_item', []));
            if (! ($line['success'] ?? false)) {
                return $this->blocked($api, $dealId, 'line_item_fetch_failed', $line, $quoteId);
            }
            $partida = $this->mapped($event, ['line_item' => $line['data']], 'line_item');
            $partida['hubspotLineItemId'] = (string) $lineId;
            if (empty($partida['cveArt']) || ! isset($partida['cveAlmacen']) || ! is_numeric($partida['cveAlmacen'])
                || (float) $partida['cveAlmacen'] <= 0 || floor((float) $partida['cveAlmacen']) !== (float) $partida['cveAlmacen']) {
                return $this->blocked($api, $dealId, 'missing_article_or_warehouse', ['line_item_id' => $lineId, 'mapped' => $partida], $quoteId);
            }
            $pricing = $warehouse->getProductWarehouses(['sku' => $partida['cveArt'], 'query' => [
                'claveCliente' => $header['claveCliente'] ?? '', 'cveAlmacen' => $partida['cveAlmacen'],
            ]]);
            $matches = array_values(array_filter((array) ($pricing['data'] ?? []), static fn ($record): bool =>
                is_array($record) && (string) ($record['cveAlm'] ?? '') === (string) $partida['cveAlmacen']));
            if (! ($pricing['success'] ?? false) || count($matches) !== 1) {
                return $this->blocked($api, $dealId, 'warehouse_pricing_failed', ['line_item_id' => $lineId, 'response' => $pricing], $quoteId);
            }
            $actual = $matches[0];
            if (filter_var($actual['incluyeImpuestos'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return $this->blocked($api, $dealId, 'tax_included_price', ['line_item_id' => $lineId, 'pricing' => $actual], $quoteId);
            }
            $expected = $header['expectedPriceList'] ?? null;
            if (! is_numeric($expected) || (float) $expected <= 0
                || ! is_numeric($actual['listaPrecio'] ?? null) || ! is_numeric($actual['precio'] ?? null)
                || ! is_numeric($partida['listaPrecio'] ?? null) || ! is_numeric($partida['precioUnitario'] ?? null)
                || (float) $expected !== (float) $actual['listaPrecio']
                || (float) $partida['listaPrecio'] !== (float) $actual['listaPrecio']
                || round((float) $partida['precioUnitario'], 6) !== round((float) $actual['precio'], 6)) {
                return $this->blocked($api, $dealId, 'price_or_list_mismatch', [
                    'line_item_id' => $lineId, 'expected_customer_list' => $expected, 'line_item' => $partida, 'sae' => $actual,
                ], $quoteId);
            }
            $partida['listaPrecio'] = $actual['listaPrecio'];
            $partida['precioUnitario'] = $actual['precio'];
            $header['partidas'][] = $partida;
        }
        $validator = new AspelService($destination->platform, app(AuthStrategyResolver::class), $destination);
        $validation = $validator->normalizeQuotePayload($header);
        if (! $validation['valid']) {
            $fields = [];
            foreach ($event->propertyRelationships()->with('relatedProperty')->where('active', true)->get() as $mapping) {
                if (isset($validation['errors'][$mapping->relatedProperty?->key])) {
                    $fields[$mapping->relatedProperty->key] = $mapping->mapping_key;
                }
            }
            return $this->blocked($api, $dealId, 'invalid_quote_data', [
                'contact_id' => $contactId, 'errors' => $validation['errors'], 'source_fields' => $fields,
            ], $quoteId);
        }
        $update = $api->updateObject('quotes', $quoteId, ['sync_status_aspel' => 'processing', 'last_error_aspel' => '']);
        if (! ($update['success'] ?? false)) {
            return $this->blocked($api, $dealId, 'quote_status_write_failed', $update, $quoteId);
        }
        return ['success' => true, 'data' => ['output_payload' => array_merge($validation['payload'], [
            'hubspot_object_id' => $quoteId, 'hubspot_object_type' => 'quotes', 'source_event_id' => $event->id,
        ])]];
    }

    private function properties(Event $event, string $object, array $extra): array
    {
        foreach ($event->propertyRelationships()->where('active', true)->get() as $mapping) {
            $prefix = $object.'.properties.';
            if (str_starts_with((string) $mapping->mapping_key, $prefix)) {
                $extra[] = substr($mapping->mapping_key, strlen($prefix));
            }
        }
        return array_values(array_unique($extra));
    }

    private function mapped(Event $event, array $source, string $scope): array
    {
        $transformed = app(EventFlowService::class)->transformPayloadForEvent($event, $source);
        $result = [];
        foreach ($event->propertyRelationships()->with('relatedProperty')->where('active', true)->get() as $mapping) {
            $key = $mapping->relatedProperty?->key;
            if (($mapping->meta['scope'] ?? 'header') === $scope && $key && Arr::has($transformed, $key)) {
                Arr::set($result, $key, Arr::get($transformed, $key));
            }
        }
        return $result;
    }

    private function blocked(HubspotApiServiceRefactored $api, string $dealId, string $reason, array $details, ?string $quoteId = null): array
    {
        $technical = $this->technicalFailure($details);
        $statusUpdate = null;
        if ($quoteId !== null && ! $technical) {
            $statusUpdate = $api->updateObject('quotes', $quoteId, [
                'sync_status_aspel' => 'error',
                'last_error_aspel' => $reason,
                'last_sync_aspel' => now()->getTimestampMs(),
            ]);
        }
        $note = $api->addNoteToObject('deals', $dealId,
            '[Integrador ASPEL] Cotizacion bloqueada: '.$reason."\n".json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .($technical ? "\nFallo transitorio: reintentar con los mismos IDs." : "\nCorregir los datos y generar una nueva cotizacion."));
        return ['success' => ! $technical, 'status' => 'warning', 'message' => 'ASPEL quote blocked: '.$reason, 'data' => [
            'reason' => $reason, 'retryable' => $technical, 'context' => $details, 'hubspot_note' => $note, 'status_update' => $statusUpdate, 'output_payload' => [],
        ]];
    }

    private function technicalFailure(array $details): bool
    {
        if (($details['retryable'] ?? false) === true) {
            return true;
        }
        if (($details['success'] ?? true) === false && isset($details['status_code'])
            && ((int) $details['status_code'] === 0 || (int) $details['status_code'] === 429 || (int) $details['status_code'] >= 500)) {
            return true;
        }
        foreach ($details as $value) {
            if (is_array($value) && $this->technicalFailure($value)) {
                return true;
            }
        }
        return false;
    }

    private function attemptedQuoteIds(Event $event): array
    {
        $ids = [];
        Record::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['warning', 'error'])
            ->latest('id')
            ->limit(500)
            ->get(['details'])
            ->each(function (Record $record) use (&$ids): void {
                $id = Arr::get($record->details, 'status_update.data.id');
                if (is_scalar($id) && trim((string) $id) !== '') {
                    $ids[(string) $id] = true;
                }
            });

        return $ids;
    }

    private function wasAlreadyProcessed(array $quote, string $quoteId, array $attemptedQuoteIds): bool
    {
        $status = trim((string) Arr::get($quote, 'properties.sync_status_aspel', ''));
        if (in_array($status, ['success', 'already_exists'], true)
            || trim((string) Arr::get($quote, 'properties.aspel_cve_doc', '')) !== '') {
            return true;
        }
        if ($status !== 'error') {
            return false;
        }
        if (isset($attemptedQuoteIds[$quoteId])) {
            return true;
        }

        $createdAt = Arr::get($quote, 'properties.hs_createdate') ?? ($quote['createdAt'] ?? null);
        $lastSync = Arr::get($quote, 'properties.last_sync_aspel');
        if (! is_scalar($createdAt) || trim((string) $createdAt) === '') {
            return true;
        }
        if (! is_scalar($lastSync) || trim((string) $lastSync) === '') {
            // HubSpot copies custom quote properties. An error without a matching local
            // attempt is therefore treated as inherited by a newly-created quote.
            return false;
        }

        try {
            $created = Carbon::parse((string) $createdAt);
            $synced = is_numeric($lastSync)
                ? Carbon::createFromTimestampMs((int) $lastSync)
                : Carbon::parse((string) $lastSync);

            return ! $created->greaterThan($synced);
        } catch (\Throwable) {
            return true;
        }
    }
}

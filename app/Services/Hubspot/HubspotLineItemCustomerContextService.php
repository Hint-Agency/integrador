<?php

namespace App\Services\Hubspot;

use Illuminate\Support\Arr;

class HubspotLineItemCustomerContextService
{
    public function resolve(HubspotApiServiceRefactored $api, string $lineItemId, array $meta): array
    {
        $deals = $this->associations($api, 'line_items', $lineItemId, 'deals');
        if (! $deals['success']) {
            return $deals;
        }
        $dealIds = $this->ids($deals['items']);
        // Quote line items may only be associated with the quote, not directly with its deal.
        if ($dealIds === []) {
            $quotes = $this->associations($api, 'line_items', $lineItemId, 'quotes');
            if (! $quotes['success']) {
                return $quotes;
            }
            foreach ($this->ids($quotes['items']) as $quoteId) {
                $quoteDeals = $this->associations($api, 'quotes', $quoteId, 'deals');
                if (! $quoteDeals['success']) {
                    return $quoteDeals;
                }
                $dealIds = array_values(array_unique(array_merge($dealIds, $this->ids($quoteDeals['items']))));
            }
        }
        if (count($dealIds) !== 1) {
            return $this->failure('missing_or_ambiguous_deal', ['deal_ids' => $dealIds]);
        }
        $dealId = $dealIds[0];
        return $this->resolveForDeal($api, $dealId, $meta);
    }

    public function resolveForDeal(HubspotApiServiceRefactored $api, string $dealId, array $meta): array
    {
        $typeId = $meta['primary_contact_association_type_id'] ?? null;
        $category = $meta['primary_contact_association_category'] ?? ($typeId === null ? 'USER_DEFINED' : null);
        if ($typeId === null) {
            $types = $api->getAssociationTypes('deals', 'contacts');
            if (! ($types['success'] ?? false)) {
                return $this->failure('association_types_request_failed', ['response' => $types], 'error');
            }
            $name = $meta['primary_contact_association_name'] ?? 'main_contact';
            $matches = array_values(array_filter(Arr::get($types, 'data.results', []),
                static fn (array $type): bool => ($type['name'] ?? null) === $name));
            if (count($matches) !== 1 || ! isset($matches[0]['id'])) {
                return $this->failure('primary_contact_association_unresolved', ['association_name' => $name]);
            }
            $typeId = $matches[0]['id'];
        }
        $contacts = $this->associations($api, 'deals', $dealId, 'contacts');
        if (! $contacts['success']) {
            return $contacts;
        }
        $primary = array_filter($contacts['items'], static function (array $contact) use ($typeId, $category): bool {
            foreach ($contact['associationTypes'] ?? [] as $type) {
                if (($category === null || ($type['category'] ?? '') === $category)
                    && (string) ($type['typeId'] ?? '') === (string) $typeId) {
                    return true;
                }
            }
            return false;
        });
        $contactIds = $this->ids($primary);
        if (count($contactIds) !== 1) {
            return $this->failure('missing_or_ambiguous_main_contact', [
                'deal_id' => $dealId, 'contact_ids' => $contactIds, 'association_type_id' => $typeId,
            ]);
        }
        $property = $meta['customer_clave_property'] ?? 'clave';
        $contact = $api->getObject('contacts', $contactIds[0], [$property]);
        if (! ($contact['success'] ?? false)) {
            return $this->failure('customer_contact_request_failed', ['response' => $contact], 'error');
        }
        $clave = Arr::get($contact, 'data.properties.'.$property);
        if (! is_scalar($clave) || trim((string) $clave) === '') {
            return $this->failure('missing_customer_clave', ['contact_id' => $contactIds[0], 'property' => $property]);
        }
        return ['success' => true, 'context' => [
            'hubspot_deal_id' => $dealId,
            'hubspot_customer_contact_id' => $contactIds[0],
            'claveCliente' => trim((string) $clave),
        ], 'association_type_id' => $typeId];
    }

    public function associations(HubspotApiServiceRefactored $api, string $from, string $id, string $to): array
    {
        $items = [];
        $after = null;
        $seen = [];
        do {
            $response = $after === null
                ? $api->getObjectAssociations($from, $id, $to)
                : $api->getObjectAssociations($from, $id, $to, $after);
            if (! ($response['success'] ?? false)) {
                return $this->failure('associations_request_failed', ['from' => $from, 'id' => $id, 'to' => $to, 'response' => $response], 'error');
            }
            $items = array_merge($items, Arr::get($response, 'data.results', []));
            $cursor = Arr::get($response, 'data.paging.next.after');
            $after = $cursor === null ? null : (string) $cursor;
            if ($after !== null && isset($seen[$after])) {
                return $this->failure('repeated_association_cursor', [], 'error');
            }
            if ($after !== null) {
                $seen[$after] = true;
            }
        } while ($after !== null);
        return ['success' => true, 'items' => $items];
    }

    private function ids(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['toObjectId'] ?? '')), $items
        ))));
    }

    private function failure(string $reason, array $context, string $status = 'warning'): array
    {
        return ['success' => false, 'status' => $status, 'reason' => $reason, 'context' => $context];
    }
}

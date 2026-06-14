# Manual integral del Integrador

Proyecto: INTEGRADOR, sistema de integración multiplataforma  
Stack: Laravel 11, Inertia.js, Vue 3, colas Laravel, HubSpot SDK, Odoo XML-RPC/JSON-RPC, Guzzle, Spatie Webhook Client  
Rama documentada: `odoo`  
Fecha de referencia: 2026-06-14

## 1. Propósito y alcance

Este manual documenta cómo operar y mantener el Integrador: alta de plataformas, propiedades, eventos, mappings, triggers, records y flujos especiales como HubSpot, Odoo, NetSuite, plataformas genéricas, Azure SQL y ASPEL.

El sistema conecta plataformas mediante una arquitectura de eventos. Un webhook, un evento programado o una ejecución manual encuentra un `Event`, crea un `Record`, despacha un `Event` de dominio, el `Listener` solo encola un `Job`, y el `Job` realiza el procesamiento pesado. La trazabilidad completa se guarda en `records`.

Reglas críticas:

1. Los `Listeners` solo deben disparar `Jobs`.
2. Las llamadas HTTP/API y transformaciones pesadas viven en `Jobs` o servicios invocados por jobs.
3. Las credenciales se guardan en `platforms.credentials` o variables de entorno, no en `meta`.
4. `hubspot_object_id`, `objectId`, `hs_object_id` y equivalentes son contexto técnico, no propiedades editables de negocio.
5. Las propiedades técnicas de write-back no deben disparar sincronizaciones de negocio salvo que se configure explícitamente `sync_to_{platform} = pending`.

## 2. Modelo mental del flujo

Flujo canónico:

1. Entra un webhook, schedule o ejecución manual.
2. `EventProcessingService` encuentra el evento por plataforma, tipo y `subscription_type`.
3. `EventLoggingService` crea un `Record` en estado `init`.
4. Se despacha el evento de dominio configurado en `EventType`.
5. El `Listener` correspondiente despacha un `Job`.
6. El `Job` invoca el servicio de plataforma, actualiza el `Record` y, si existe `to_event_id`, despacha `ProcessNextEventJob`.
7. `ProcessNextEventJob` prepara el payload del siguiente evento usando mappings, contexto técnico y enriquecimiento cuando aplique.

Estados de ejecución:

| Estado | Significado | Uso típico |
| --- | --- | --- |
| `init` | Registro inicial creado | Inicio de evento o listener |
| `processing` | Trabajo en curso | Job ejecutando API o transformación |
| `success` | Ejecución terminada correctamente | Llamada externa exitosa o flujo completo |
| `warning` | Ejecución no fatal con condición revisable | Método no disponible, idempotencia ya procesada, match múltiple |
| `error` | Fallo que requiere atención | API fallida, payload inválido, detalle ASPEL fallido |

## 3. Alta de plataforma

Ruta UI: `Admin > Platforms`.

Una plataforma representa una integración externa y concentra credenciales, settings no sensibles, validación de webhooks y configuración base de API.

### 3.1 Campos base

| Campo | Obligatorio | Descripción |
| --- | --- | --- |
| `Platform Name` | Sí | Nombre visible para administración. |
| `Platform Type` | Sí | `hubspot`, `odoo`, `netsuite` o `generic`. |
| `Slug` | No | Identificador URL/amigable. Si se omite, se deriva del nombre. |
| `Active platform` | No | Habilita o deshabilita la plataforma. |

### 3.2 HubSpot

Campos:

| Campo | Descripción |
| --- | --- |
| `API Token` | Token privado de HubSpot. Se guarda como `credentials.access_token`. |
| `API URL` | Opcional. Permite override de base URL si existe un entorno especial. |

Uso típico:

```json
{
  "credentials": {
    "access_token": "pat-na1-..."
  },
  "settings": {
    "base_url": "https://api.hubapi.com"
  }
}
```

### 3.3 Odoo

Campos:

| Campo | Descripción |
| --- | --- |
| `Username` | Usuario de Odoo. |
| `Password` | Password/API key de Odoo. |
| `Database` | Base de datos Odoo. |
| `Odoo URL` | URL base de Odoo. |

El servicio soporta adaptadores XML-RPC y JSON-RPC. Los settings pueden incluir catálogos, defaults y configuración de modelos relacionales.

Ejemplo de `settings`:

```json
{
  "url": "https://odoo.example.com",
  "odoo": {
    "defaults": {
      "company_id": 1,
      "currency_id": 33
    },
    "catalogs": {
      "taxes": {
        "IVA": 7,
        "EXENTO": 0
      }
    }
  }
}
```

### 3.4 NetSuite

Campos:

| Campo | Descripción |
| --- | --- |
| `Account ID` | Cuenta NetSuite. |
| `Consumer Key` | Consumer key OAuth. |
| `Consumer Secret` | Consumer secret OAuth. |
| `Token ID` | Token ID. |
| `Token Secret` | Token secret. |
| `Private Key` | Llave privada si la integración la requiere. |

### 3.5 Generic

Tipo usado para plataformas sin SDK o drivers internos.

Drivers disponibles en UI:

| Driver | Uso |
| --- | --- |
| `generic_http` | Llamadas HTTP configurables por evento. |
| `aspel` | Sincronización ASPEL, contactos y productos. |
| `azure_sql` | Lectura de tablas Azure SQL hacia HubSpot. |

Auth modes disponibles:

| Auth mode | Credenciales esperadas |
| --- | --- |
| `bearer_api_key` | `credentials.api_key` o env configurada por `api_key_env`. |
| `basic_auth` | `credentials.username` y `credentials.password`. |
| `oauth2_client_credentials` | `client_id`, `client_secret`, `token_url` y opcional `scopes`. |

Ejemplo `generic_http`:

```json
{
  "credentials": {
    "api_key": "secret"
  },
  "settings": {
    "service_driver": "generic_http",
    "auth_mode": "bearer_api_key",
    "base_url": "https://api.example.com"
  }
}
```

Ejemplo ASPEL:

```json
{
  "credentials": {
    "api_key": "secret"
  },
  "settings": {
    "service_driver": "aspel",
    "auth_mode": "bearer_api_key",
    "base_url": "https://aspel.example.com"
  }
}
```

Ejemplo Azure SQL:

```json
{
  "credentials": {
    "service_driver": "azure_sql",
    "username": "sqladmin",
    "password": "secret"
  },
  "settings": {
    "service_driver": "azure_sql",
    "host": "sql-crm-maco.database.windows.net",
    "port": "1433",
    "database": "DB-CRM",
    "encrypt": true,
    "trust_server_certificate": false,
    "login_timeout": 30
  }
}
```

### 3.6 Seguridad de webhooks

Campos:

| Campo | Descripción |
| --- | --- |
| `Webhook Signature` | Header donde llega firma o token. En Odoo suele ser `x-odoo-signature`. |
| `Validation Mode` | `hmac_sha256` o `shared_token`. |
| `Secret Key` / `Shared Token` | Secreto usado para validar. |
| `Allow shared token in URL/body` | Solo para plataformas que no pueden enviar headers. |

Recomendación: usar `hmac_sha256` cuando la plataforma soporte firma HMAC. Usar `shared_token` solo cuando sea necesario.

## 4. Alta de propiedades

Ruta UI: `Admin > Properties`.

Una propiedad representa un campo origen o destino que puede participar en mappings.

Campos:

| Campo | Obligatorio | Descripción |
| --- | --- | --- |
| `Platform` | Sí | Plataforma propietaria del campo. |
| `Name` | Sí | Nombre humano. |
| `Key` | Sí | Clave técnica o path esperado. |
| `Type` | Sí | `string`, `integer`, `float`, `boolean`, `datetime`, `file`. |
| `Required` | No | Si el evento la requiere, validación falla cuando falta. |
| `Active` | No | Controla disponibilidad en UI/mapping. |
| `Meta JSON` | No | Metadata auxiliar. |

Ejemplos de propiedades:

| Plataforma | Name | Key | Type |
| --- | --- | --- | --- |
| HubSpot | Contact ID | `hubspot_object_id` | `string` |
| HubSpot | Sync to Odoo | `sync_to_odoo` | `string` |
| Odoo | Partner Name | `name` | `string` |
| ASPEL | Clave | `clave` | `string` |
| Azure SQL | Item ID | `itemid` | `string` |

Ejemplo de `meta`:

```json
{
  "source": "hubspot",
  "description": "Propiedad técnica usada solo como contexto del flujo"
}
```

## 5. Alta de eventos

Ruta UI: `Admin > Events`.

Un evento define cuándo y cómo se ejecuta una integración.

### 5.1 Campos principales

| Campo | Descripción |
| --- | --- |
| `Platform` | Plataforma que ejecuta o recibe el evento. |
| `Next Event` | Evento siguiente para encadenamiento. |
| `Name` | Nombre operativo del evento. |
| `Suggested Event Type` | Selector basado en `EventType`. |
| `Execution Type` | `webhook` o `schedule`. |
| `Event Type ID` | Tipo persistido. Puede ser canónico o legacy. |
| `Subscription Type` | Clave recibida en webhook. En HubSpot usar `contact.propertyChange`, `company.propertyChange`, `deal.propertyChange` u `object.propertyChange`. |
| `Method Name` | Método público del servicio que ejecutará la lógica. |
| `Endpoint API` | Endpoint operativo o metadata del endpoint. |
| `Payload Mapping JSON` | Mapping directo de source path a target path para genéricos. |
| `Meta JSON` | Metadata auxiliar del evento. |
| `Active` | Habilita ejecución. |

### 5.2 Tipos de evento canónicos

| Grupo | Event Type ID |
| --- | --- |
| Core | `company.created`, `company.updated`, `product.created`, `product.updated`, `invoice.created`, `invoice.recurring.created`, `sale_order.created`, `quotes.sending_data`, `response.send`, `object.updated` |
| HubSpot | `contact.propertyChange`, `company.propertyChange`, `deal.propertyChange`, `object.propertyChange` |
| Legacy | `hubspot.property.changed` |
| Odoo Sync | `odoo.get_list_prices`, `odoo.get_store_products` |
| Azure SQL Sync | `azure_sql.products.sync`, `azure_sql.accounts.sync`, `azure_sql.contacts.sync` |
| Flow Control | `next.event` |
| Generic HTTP | `generic.external.call` |

### 5.3 Métodos configurables por servicio

HubSpot:

| Método | Uso |
| --- | --- |
| `companyCreatedWebhook` | Procesar creación de empresa desde HubSpot. |
| `contactCreatedWebhook` | Procesar creación de contacto desde HubSpot. |
| `dealPropertyChange` | Cambio de propiedad en deal. |
| `contactPropertyChange` | Cambio de propiedad en contacto. |
| `companyPropertyChange` | Cambio de propiedad en empresa. |
| `objectPropertyChange` | Cambio de propiedad en objeto genérico. |
| `invoicePropertyChange` | Cambio de propiedad en factura. |
| `createProducts` | Crear productos. |
| `updateProducts` | Actualizar productos. |
| `getSignedQuotes` | Buscar cotizaciones firmadas. |
| `getArchivedQuotes` | Buscar cotizaciones archivadas. |
| `createInvoice` | Crear factura. |
| `createObject` | Crear objeto HubSpot. |
| `updateObject` | Actualizar objeto HubSpot. |
| `updateQuoteObject` | Actualizar objeto de cotización. |
| `writeBackArchivedQuoteCancellation` | Write-back de cancelación de cotización archivada. |
| `createOrUpdateInvoiceObject` | Crear o actualizar objeto de factura. |
| `updateCompany` | Actualizar empresa. |
| `syncContactExecutionResponse` | Escribir respuesta de ejecución en contacto HubSpot. |
| `syncAspelContactToHubspot` | Sincronizar contacto ASPEL a HubSpot. |
| `updateAspelContactInHubspot` | Actualizar contacto HubSpot desde ASPEL. |
| `createAspelContactInHubspot` | Crear contacto HubSpot desde ASPEL. |
| `updateAspelProductInHubspot` | Actualizar producto HubSpot desde ASPEL. |
| `createAspelProductInHubspot` | Crear producto HubSpot desde ASPEL. |

Odoo:

| Método | Uso |
| --- | --- |
| `resPartnerCreateCompany` | Crear empresa/contacto Odoo desde payload. |
| `resPartnerCreateOrUpdateContact` | Crear o actualizar contacto Odoo. |
| `createUpdatePartner` | Upsert de partner. |
| `syncCreateProducts` | Sincronizar productos nuevos. |
| `syncUpdateProducts` | Sincronizar productos existentes. |
| `createSaleOrder` | Crear orden de venta. |
| `createSaleSubscription` | Crear suscripción/factura recurrente. |
| `saleOrderCanceled` | Procesar cancelación de venta. |
| `saleSubscriptionCanceled` | Procesar cancelación de suscripción. |
| `accountMoveCreatedUpdated` | Procesar factura creada/actualizada. |
| `resPartnerUpdate` | Actualizar partner. |
| `getListPricesByProduct` | Consultar listas de precio por producto. |

Generic:

| Método | Uso |
| --- | --- |
| `executeEndpointCall` | Ejecutar request HTTP genérico. |
| `resolveEndpoint` | Resolver URL final. |
| `resolveMethod` | Resolver método HTTP. |
| `resolveHeaders` | Resolver headers y auth. |
| `resolveQueryParams` | Resolver query params. |
| `resolveBody` | Construir body desde payload mapping. |

ASPEL:

| Método | Uso |
| --- | --- |
| `createContact` | Crear contacto. |
| `updateContact` | Actualizar contacto por `clave`. |
| `findContact` | Buscar contacto por `clave`, RFC, teléfono o email. |
| `updateContactWithLookup` | Buscar y actualizar, con fallback de creación si aplica. |
| `getUpdatedContacts` | Polling de cambios de contactos. |
| `getUpdatedProducts` | Polling de cambios de productos. |
| `getContactDetailByClave` | Obtener detalle de contacto por `clave`. |
| `getProductDetailByClave` | Obtener detalle de producto por `clave`. |

Azure SQL:

| Método | Uso |
| --- | --- |
| `syncProducts` | Leer `inventtable` y actualizar productos HubSpot. |
| `syncAccounts` | Leer `custtable` y actualizar companies HubSpot. |
| `syncContacts` | Leer `contactos_cl` y reconciliar contactos HubSpot. |

### 5.4 Eventos schedule

Campos visibles cuando `Execution Type = schedule`:

| Campo | Descripción |
| --- | --- |
| `Schedule Expression` | Expresión cron, por ejemplo `0 * * * *`. |
| `Command SQL` | Consulta SQL para eventos que leen Azure SQL. |
| `enable_update_hubdb` | Activa actualización de HubDB si el flujo la usa. |
| `hubdb_table_id` | ID de tabla HubDB cuando aplica. |

La ejecución manual de schedules usa `ExecuteEventJob` en cola `events`.

## 6. Configuración HTTP genérica

Disponible cuando la plataforma es `generic`.

Campos:

| Campo | Descripción |
| --- | --- |
| `HTTP Method` | `GET`, `POST`, `PUT`, `PATCH`, `DELETE`. |
| `Base URL` | URL base. |
| `Path` | Path del endpoint. Si es URL absoluta, se usa como endpoint final. |
| `Override platform auth for this event` | Permite auth distinta para el evento. |
| `Auth Mode` | `bearer_api_key`, `basic_auth`, `oauth2_client_credentials`. |
| `Timeout Seconds` | Entre 1 y 120. |
| `HTTP active` | Si está inactivo, se ignora la configuración HTTP y se cae a `meta`/`endpoint_api`. |
| `Headers JSON` | Headers no sensibles. |
| `Query JSON` | Query params fijos. |
| `Auth config JSON` | Override fino de auth. |
| `Retry policy JSON` | Overrides de reintento. |
| `Idempotency JSON` | Política de idempotencia. |
| `Allowlist Domains JSON` | Dominios permitidos para este evento. |

Ejemplo completo:

```json
{
  "method": "POST",
  "base_url": "https://api.external-platform.com",
  "path": "/v1/orders/sync",
  "headers_json": {
    "x-tenant-id": "tenant_123"
  },
  "query_json": {
    "source": "integrador"
  },
  "auth_mode": "bearer_api_key",
  "auth_config_json": {
    "api_key_env": "EXTERNAL_API_KEY"
  },
  "timeout_seconds": 30,
  "retry_policy_json": {
    "max_attempts": 3,
    "backoff_seconds": 5,
    "jitter": true
  },
  "idempotency_config_json": {
    "enabled": true,
    "ttl_hours": 24,
    "key_template": "{event_id}:{record_id}:{method}:{path}"
  },
  "allowlist_domains_json": [
    "api.external-platform.com"
  ],
  "active": true
}
```

Respuesta normalizada esperada:

```json
{
  "success": true,
  "status_code": 200,
  "retryable": false,
  "request_id": "req_123",
  "external_id": "ext_456",
  "latency_ms": 184,
  "attempt": 1,
  "endpoint": "https://api.external-platform.com/v1/orders/sync",
  "method": "POST",
  "data": {},
  "error": {
    "code": null,
    "message": null,
    "details": null
  }
}
```

## 7. Payload mapping del evento

`payload_mapping` se usa especialmente en `GenericPlatformService::resolveBody`.

Formato:

```json
{
  "source.path": "target.path",
  "properties.dealname": "name",
  "properties.amount": "total"
}
```

Reglas:

1. Se lee el valor con `Arr::get`.
2. Si el valor es `null`, no se escribe.
3. Se escribe en el body con `Arr::set`.
4. Si no hay mapping, se envía el payload completo.

Demo HubSpot a API externa:

```json
{
  "hubspot_object_id": "12345",
  "dealname": "Contrato ACME",
  "amount": "15000",
  "properties.pipeline": "default"
}
```

Mapping:

```json
{
  "dealname": "customer_reference.name",
  "amount": "total_amount",
  "properties.pipeline": "metadata.pipeline"
}
```

Body resultante:

```json
{
  "customer_reference": {
    "name": "Contrato ACME"
  },
  "total_amount": "15000",
  "metadata": {
    "pipeline": "default"
  }
}
```

## 8. Mapping por Property Relationships

Ruta UI: `Admin > Events > Relationships`.

Este mapping se usa para transformar payloads entre eventos encadenados y para enriquecer datos antes del siguiente evento.

Campos:

| Campo | Descripción |
| --- | --- |
| `Incoming Payload Property` | Propiedad fuente. |
| `Mapping Target Property` | Propiedad destino. |
| `Mapping Key` | Path fuente opcional. Si se omite, usa `property.key`. |
| `Meta JSON` | Transformaciones, catálogos, scope o plantillas. |
| `Active mapping` | Habilita/deshabilita el mapping. |

Paths comunes:

| Path | Uso |
| --- | --- |
| `hs_terms` | Propiedad plana. |
| `properties.hs_terms` | Propiedad dentro de payload HubSpot. |
| `raw.properties.hs_terms` | Propiedad dentro de objeto crudo. |
| `raw.associations.deals.0.owner.email` | Dato anidado enriquecido. |
| `entity_results.company.target_id` | Resultado de validación o creación previa. |
| `destination_response.data.external_id` | Respuesta de integración genérica. |

### 8.1 Cast por tipo destino

El destino se castea según `related_property.type`:

| Tipo | Resultado |
| --- | --- |
| `boolean` / `bool` | Booleano interpretado con `FILTER_VALIDATE_BOOL`. |
| `integer` / `int` | Entero si es numérico, si no `0`. |
| `float` / `decimal` | Float si es numérico, si no `0.0`. |
| `array` | Array o array con el valor. |
| `string` | String si es escalar, JSON si es objeto/array. |
| `file` | Descarga URL y convierte a payload con `filename`, `content_type`, `content`, `source_url`. |

### 8.2 Catálogos

`meta.catalog` permite traducir valores usando `platform.settings`.

Ejemplo:

```json
{
  "catalog": {
    "path": "odoo.catalogs.taxes",
    "platform": "target",
    "match": "key",
    "output": "value"
  }
}
```

Si el catálogo no existe o no encuentra match, el valor no se escribe.

### 8.3 Templates, sources y transformaciones

Para Odoo sale subscription y campos compuestos, el `meta` soporta:

| Clave | Uso |
| --- | --- |
| `template` | Renderiza texto con placeholders `{path}`. |
| `sources` | Lista de paths o bloques `{path,label,template}` para concatenar. |
| `separator` | Separador entre piezas. Default salto de línea. |
| `mode` / `strategy` | `set`, `append` o `concat`. |
| `scope` / `target_scope` / `context` | Aplica a `sale_subscription`, `subscription`, `general`, `header`. |
| `transform`, `sanitize`, `format` | Transformación única. |
| `transforms` | Lista de transformaciones. |
| `html_to_text`, `strip_html`, `clean_html` | Flags para limpiar HTML. |

Transformaciones disponibles:

| Transform | Resultado |
| --- | --- |
| `html_to_text` | Convierte HTML a texto. |
| `strip_html` | Alias práctico de limpieza HTML. |
| `html_text` | Alias de `html_to_text`. |
| `squish` | Reduce espacios múltiples a uno y hace trim. |
| `trim` | Elimina espacios al inicio/final. |

Ejemplo template:

```json
{
  "template": "Contrato {dealname} por {amount}",
  "transforms": ["trim", "squish"]
}
```

Ejemplo sources:

```json
{
  "sources": [
    {"label": "Cliente", "path": "company.name"},
    {"label": "RFC", "path": "company.rfc"},
    {"label": "Notas", "path": "raw.properties.notes", "transforms": ["html_to_text", "trim"]}
  ],
  "separator": "\n",
  "mode": "append",
  "scope": "sale_subscription"
}
```

## 9. Meta JSON de eventos

`meta` guarda metadata auxiliar del evento. No debe reemplazar credenciales ni secrets.

Claves detectadas:

| Clave | Uso |
| --- | --- |
| `object_type` | Resolver tipo de objeto HubSpot cuando `subscription_type = object.propertyChange`. |
| `endpoint` | Endpoint fallback para plataforma genérica si no hay `http_config`. |
| `http_method` / `method` | Método HTTP fallback. |
| `headers` | Headers adicionales del evento. |
| `query` | Query params adicionales. |
| `auth_mode` | Auth mode fallback o legacy. |
| `timeout` | Timeout fallback. |
| `retry` | Retry policy fallback. |
| `idempotent` | Activa idempotencia simple. |
| `idempotency` | Política avanzada de idempotencia. |
| `mapping_context` | Override de labels y plataformas del editor de mappings. |
| `take` | Tamaño de batch para polling ASPEL. |
| `detail_endpoint` | Endpoint detalle ASPEL con `{clave}` opcional. |
| `search_endpoint` | Endpoint búsqueda ASPEL. |
| `operation` | Método operativo ASPEL si no se usa `method_name`. |

Ejemplo HubSpot object property change:

```json
{
  "object_type": "contacts",
  "notes": "Dispara cuando sync_to_odoo pasa a pending"
}
```

Ejemplo ASPEL polling contactos:

```json
{
  "take": 200,
  "detail_endpoint": "/api/contacts/{clave}",
  "search_endpoint": "/api/contacts/search"
}
```

Ejemplo mapping context:

```json
{
  "mapping_context": {
    "source_label": "HubSpot Contact",
    "target_label": "ASPEL Contact Payload",
    "next_label": "HubSpot Write-back"
  }
}
```

## 10. Triggers

Ruta UI: `Admin > Events > Triggers`.

Los triggers permiten condicionar si un evento debe ejecutarse. Se agrupan por grupos y operadores. Para sincronizaciones controladas por plataforma, se recomienda usar una propiedad técnica:

```json
{
  "property": "sync_to_odoo",
  "operator": "equals",
  "value": "pending"
}
```

Patrón recomendado:

| Plataforma destino | Trigger | Write-back técnico |
| --- | --- | --- |
| Odoo | `sync_to_odoo = pending` | `sync_status_odoo`, `last_sync_odoo`, `odoo_id`, `last_error_odoo` |
| ASPEL | `sync_to_aspel = pending` | `sync_status_aspel`, `last_sync_aspel`, `aspel_id`, `last_error_aspel` |
| NetSuite | `sync_to_netsuite = pending` | `sync_status_netsuite`, `last_sync_netsuite`, `netsuite_id`, `last_error_netsuite` |

No usar cambios de propiedades de negocio como disparador individual cuando el flujo debe ser manual/controlado.

## 11. Records

Ruta UI: `Admin > Records`.

Cada record guarda una ejecución.

Campos:

| Campo | Descripción |
| --- | --- |
| `id` | ID de record. |
| `event_id` | Evento asociado. |
| `record_id` | Parent record. Permite jerarquía. |
| `event_type` | Tipo de evento ejecutado. |
| `status` | `init`, `processing`, `success`, `warning`, `error`. |
| `payload` | Payload recibido o transformado. |
| `message` | Mensaje operativo. |
| `details` | Detalles técnicos, respuesta, request, warnings. |
| `children_count` | Cantidad de records hijos en UI. |

Detalles importantes:

| `details` path | Uso |
| --- | --- |
| `output_payload` | Payload generado para el siguiente evento. |
| `hubspot_enrichment` | Resultado de fetch de objeto HubSpot para property changes. |
| `hubspot_note` | Resultado de intento de nota de fallo en HubSpot. |
| `response` | Respuesta sanitizada de endpoint genérico. |
| `request` | Endpoint, method, headers sanitizados y query. |
| `service_output` | Salida estructurada de servicios como ASPEL. |
| `cursor_state` | Cursor persistente de polling. |
| `polling_metrics` | Métricas de páginas/items procesados. |
| `aspel_lookup` | Resultado de búsqueda ASPEL. |

## 12. Demos de configuración

### 12.1 Demo: HubSpot contacto a Odoo con control manual

Objetivo: cuando un operador marque `sync_to_odoo = pending`, enviar el contacto completo a Odoo y escribir estado técnico de vuelta.

Plataformas:

1. HubSpot activa con token.
2. Odoo activa con URL, base, usuario y password.

Evento raíz:

| Campo | Valor |
| --- | --- |
| Platform | HubSpot |
| Event Type ID | `contact.propertyChange` |
| Subscription Type | `contact.propertyChange` |
| Method Name | `contactPropertyChange` |
| Next Event | Evento Odoo create/update |
| Meta | `{ "object_type": "contacts" }` |

Trigger:

```json
{
  "property": "sync_to_odoo",
  "operator": "equals",
  "value": "pending"
}
```

Mappings raíz a evento Odoo:

| Source | Mapping Key | Target |
| --- | --- | --- |
| Email | `email` | `email` |
| First Name | `firstname` | `name` |
| Phone | `phone` | `phone` |
| Company | `company` | `parent_name` |

Evento Odoo:

| Campo | Valor |
| --- | --- |
| Platform | Odoo |
| Event Type ID | `company.updated` o legacy Odoo partner |
| Method Name | `resPartnerUpdate` o `resPartnerCreateOrUpdateContact` |
| Next Event | Write-back HubSpot |

Write-back HubSpot:

| Campo | Valor |
| --- | --- |
| Platform | HubSpot |
| Event Type ID | `object.updated` |
| Method Name | `updateObject` |
| Payload base | `destination_response.data` más contexto técnico |

### 12.2 Demo: Generic HTTP con idempotencia y write-back

Evento:

| Campo | Valor |
| --- | --- |
| Platform | Generic HTTP |
| Event Type ID | `generic.external.call` |
| Method Name | `executeEndpointCall` |
| Next Event | HubSpot write-back |

HTTP config:

```json
{
  "method": "POST",
  "base_url": "https://api.partner.com",
  "path": "/v1/sync/contact",
  "headers_json": {
    "x-source": "integrador"
  },
  "auth_mode": "bearer_api_key",
  "auth_config_json": {
    "api_key_env": "PARTNER_API_KEY"
  },
  "idempotency_config_json": {
    "enabled": true,
    "ttl_hours": 24,
    "key_template": "{event_id}:{record_id}:{method}:{path}"
  },
  "active": true
}
```

Payload mapping:

```json
{
  "email": "contact.email",
  "firstname": "contact.first_name",
  "lastname": "contact.last_name",
  "phone": "contact.phone",
  "hubspot_object_id": "metadata.hubspot_object_id"
}
```

### 12.3 Demo: ASPEL contactos a HubSpot por polling

Plataforma:

```json
{
  "settings": {
    "service_driver": "aspel",
    "auth_mode": "bearer_api_key",
    "base_url": "https://aspel.example.com"
  }
}
```

Evento schedule:

| Campo | Valor |
| --- | --- |
| Platform | ASPEL |
| Event Type ID | `generic.external.call` |
| Type | `schedule` |
| Method Name | `getUpdatedContacts` |
| Schedule Expression | `*/15 * * * *` |
| Endpoint API | `/api/contacts/changes` |
| Meta | Ver ejemplo |

Meta:

```json
{
  "take": 200,
  "detail_endpoint": "/api/contacts/{clave}"
}
```

Reglas:

1. Usa cursor persistente en `configs`: `sinceTs` y `sinceClave`.
2. Procesa idempotencia por `clave + versionSinc`.
3. Obtiene detalle con `GET /api/contacts/{clave}`.
4. Hace matching HubSpot en orden `clave`, `rfc`, `phone`, `email`.
5. Actualiza si hay match, crea si no existe.
6. No avanza cursor si falla un item o el detalle.

### 12.4 Demo: ASPEL productos a HubSpot por polling

Evento schedule:

| Campo | Valor |
| --- | --- |
| Platform | ASPEL |
| Method Name | `getUpdatedProducts` |
| Endpoint API | `/api/products/changes` |
| Meta | `{ "take": 200, "detail_endpoint": "/api/products/{clave}" }` |

Reglas:

1. Cursor persistente por productos.
2. Idempotencia por `clave + versionSinc`.
3. Fetch detalle con `GET /api/products/{clave}`.
4. Matching HubSpot por `clave`.
5. Update si existe, create si no existe.

### 12.5 Demo: Azure SQL productos hacia HubSpot

Plataforma generic con driver `azure_sql`.

Evento:

| Campo | Valor |
| --- | --- |
| Event Type ID | `azure_sql.products.sync` |
| Type | `schedule` |
| Method Name | `syncProducts` |
| Command SQL | `SELECT * FROM [dbo].[inventtable]` |

El servicio toma filas de `inventtable`, prepara payload HubSpot y busca por `identificador_db` o `sku`.

### 12.6 Demo: Odoo sale subscription con notas compuestas

Mapping meta para concatenar datos de cotización:

```json
{
  "scope": "sale_subscription",
  "sources": [
    {"label": "Cotización", "path": "quote.hs_title"},
    {"label": "Condiciones", "path": "raw.properties.hs_terms", "transforms": ["html_to_text", "trim"]},
    {"label": "Total", "template": "{amount} {currency}"}
  ],
  "separator": "\n",
  "mode": "append"
}
```

## 13. Operación y troubleshooting

Checklist para una integración nueva:

1. Crear plataforma y validar `Test connection`.
2. Crear propiedades origen y destino.
3. Crear evento raíz con `subscription_type` correcto.
4. Crear evento destino y configurar `to_event_id`.
5. Crear mappings por evento.
6. Crear triggers si la sincronización es controlada.
7. Ejecutar evento de prueba o webhook real.
8. Revisar `records`: payload, details, output payload y children.
9. Verificar write-back técnico en HubSpot si aplica.

Errores comunes:

| Síntoma | Causa probable | Revisión |
| --- | --- | --- |
| Evento no se ejecuta | `subscription_type` no coincide | Revisar payload webhook y `Event Type ID`. |
| Record en warning `method_not_available` | `method_name` no existe en servicio | Comparar con catálogo de métodos. |
| Record en warning `service_class_not_found` | Tipo de plataforma sin servicio | Revisar `Platform Type` y driver. |
| Error de JSON en UI | `meta`, `payload_mapping` o HTTP config inválidos | Validar JSON. |
| Generic HTTP no llama | Config HTTP inactiva o endpoint faltante | Revisar `active`, `base_url`, `path`, `endpoint_api`. |
| 401/403 externo | Auth mal configurada | Revisar `auth_mode`, credentials y env vars. |
| No avanza cursor ASPEL | Falló un item o detalle | Revisar `polling_metrics`, `service_output` y `last_error`. |
| HubSpot no recibe nota de fallo | No hay contexto de contacto | Revisar `hubspot_object_id`, `objectId`, `source_event_id`. |
| Mapping no escribe valor | Path fuente no existe o catálogo no encontró match | Revisar `mapping_key` y `meta.catalog`. |

## 14. API y rutas operativas

API protegida por `auth.basic` y permisos:

| Ruta | Uso |
| --- | --- |
| `GET /api/status` | Health simple. |
| `GET /api/events/statistics` | Estadísticas de eventos. |
| `GET /api/events/{event}/flow` | Ver flujo. |
| `GET /api/events/{event}/triggers` | Ver triggers. |
| `POST /api/events/{event}/test` | Probar evento con payload. |
| `POST /api/events/{event}/execute-flow` | Ejecutar flujo completo. |
| `POST /api/events/{event}/execute-now` | Encolar schedule manualmente. |
| `PUT /api/events/{event}/triggers` | Actualizar triggers. |
| `GET /api/job-status/check` | Estado de jobs/records. |
| `GET /api/job-status/related-records` | Records relacionados. |
| `POST /api/platforms/{platform}/test-connection` | Test de conexión. |

Webhooks:

| Ruta | Uso |
| --- | --- |
| `POST /webhooks/{platform}` | Entrada de webhook por plataforma. |
| `POST /webhook/{platform}` | Alias compatible. |

## 15. Comandos útiles

| Comando | Uso |
| --- | --- |
| `php artisan events:search-schedule` | Buscar y ejecutar eventos schedule según expresión. |
| `php artisan events:clear-cache` | Limpiar caché de eventos. |
| `php artisan events:clear-records` | Limpiar records. |
| `php artisan events:clear-all` | Limpieza amplia del sistema de eventos. |
| `php artisan hubspot:regenerate-cache` | Regenerar caché HubSpot. |
| `php artisan products:cache` | Mantener caché de productos. |
| `php artisan system:preflight` | Validaciones de entorno y configuración. |

## 16. Gobierno de cambios

Antes de modificar un flujo productivo:

1. Duplicar o documentar configuración actual.
2. Revisar `to_event_id` para evitar ciclos.
3. Confirmar que listeners no procesen lógica pesada.
4. Confirmar que las propiedades técnicas no estén en mappings editables de negocio.
5. Probar con payload mínimo.
6. Revisar records y logs.
7. Activar el evento solo cuando el flujo esté verificado.

Formato recomendado de reporte:

```text
Alta de plataforma ASPEL -- STATUS: VERIFIED
Evento polling contactos -- STATUS: VERIFIED
Mappings HubSpot contacto -- STATUS: IN_PROGRESS
Write-back HubSpot -- STATUS: PENDING
```


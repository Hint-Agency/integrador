# spec.md

Sistema: INTEGRADOR - Sistema de Integracion Multiplataforma
Version: 1.1.4
Estado: LOCKED
Tipo: Especificacion Oficial del Sistema

---

# Parte I -- Contexto Estrategico

## Proposito

Proveer un sistema de integracion multiplataforma que sincronice datos entre HubSpot, Odoo y NetSuite mediante webhooks, eventos, listeners y jobs asincronos, manteniendo trazabilidad completa de todas las operaciones.

## Problema que Resuelve

- Sincronizacion manual y lenta entre plataformas.
- Inconsistencias de datos entre sistemas.
- Falta de trazabilidad en ejecuciones y errores.
- Dificultad para escalar integraciones y flujos.

## Autoridad de Decision

La logica de integracion vive en la configuracion de eventos, mapeos de propiedades y triggers. El sistema orquesta, valida y ejecuta los flujos definidos.

---

# Parte II -- Especificacion Formal

# 1. Alcance

## 1.1 Incluido

- Integracion con HubSpot, Odoo y NetSuite.
- Recepcion de webhooks de plataformas externas.
- Sistema de eventos, listeners y jobs para procesamiento asincrono.
- Mapeo de propiedades entre plataformas.
- Trazabilidad completa mediante Records.
- Integraciones genericas por endpoint (sin SDK).
- Frontend administrativo con Inertia.js + Vue 3.
- Procesamiento de cotizaciones firmadas, productos, empresas, contactos y facturas.

## 1.2 Excluido

- Nuevas integraciones fuera de HubSpot, Odoo y NetSuite sin aprobacion.
- Migraciones de infraestructura o despliegue.
- Hardcode de credenciales o secretos en codigo.
- Procesamiento sincrono cuando pueda ejecutarse por cola.

---

# 2. Funcion

El sistema DEBE:

1. Recibir webhooks de plataformas externas.
2. Validar y normalizar el payload.
3. Resolver plataforma y evento configurado.
4. Crear Records de trazabilidad en estado `init`.
5. Emitir Events (solo datos) y disparar Listeners.
6. Despachar Jobs asincronos para todo procesamiento pesado.
7. Ejecutar integraciones con HubSpot, Odoo, NetSuite o endpoints genericos.
8. Actualizar Records a `success`, `error` o `warning` segun resultado.
9. Encadenar `NextEvent` cuando exista `to_event_id` o reglas de flujo.

## Fallo Critico

No procesar un evento valido (activo y con plataforma configurada) o no registrar su trazabilidad en Records.

---

# 3. Dominio

## Entidades

- Event
- Record
- Platform
- Property
- PropertyRelationship
- EventTrigger
- EventTriggerGroup

---

# 4. Arquitectura

## Stack

- Laravel 11
- Inertia.js + Vue 3
- HubSpot SDK `hubspot/api-client`
- Ripcord (Odoo XML-RPC)
- Guzzle HTTP Client
- Laravel Queue System
- Spatie Laravel Webhook Client

## Patron

Hexagonal (Ports & Adapters).

## 4.1 Configuracion y Secretos

- Todos los secretos y credenciales DEBEN venir de variables de entorno.
- PROHIBIDO hardcodear tokens o credenciales en codigo.
- La configuracion de integraciones se centraliza en:
  - `config/hubspot.php`
  - `config/generic-platforms.php`
  - `config/queue-custom.php`
- Las claves exactas de `.env` se definen en esos archivos y servicios, y deben documentarse alli.

## 4.1.1 Contrato de `.env` (V1)

Variables requeridas:

- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `HUBSPOT_ACCESS_TOKEN`
- `ODOO_URL`
- `ODOO_DATABASE`
- `ODOO_USERNAME`
- `ODOO_PASSWORD`
- `NETSUITE_ACCOUNT`
- `NETSUITE_CONSUMER_KEY`
- `NETSUITE_CONSUMER_SECRET`
- `NETSUITE_TOKEN_ID`
- `NETSUITE_TOKEN_SECRET`
- `NETSUITE_PRIVATE_KEY`

Variables opcionales (base para integraciones genericas sin SDK):

- `GENERIC_API_KEY`
- `GENERIC_BASIC_USER`
- `GENERIC_BASIC_PASSWORD`
- `GENERIC_OAUTH_TOKEN_URL`
- `GENERIC_OAUTH_CLIENT_ID`
- `GENERIC_OAUTH_CLIENT_SECRET`
- `GENERIC_OAUTH_SCOPES`
- `GENERIC_ALLOWED_DOMAINS` (CSV)
- `GENERIC_SENSITIVE_HEADERS` (CSV)
- `GENERIC_TIMEOUT_SECONDS`
- `GENERIC_RETRY_MAX_ATTEMPTS`
- `GENERIC_RETRY_BACKOFF_SECONDS`
- `GENERIC_RETRY_JITTER`
- `GENERIC_OAUTH_TOKEN_CACHE_SECONDS`

Reglas:

- Ninguna credencial puede estar hardcodeada.
- Si faltan variables requeridas, el sistema debe fallar en arranque.
- El fail-fast puede controlarse con `APP_VALIDATE_REQUIRED_ENV` (default `false` en desarrollo; recomendado `true` en entornos productivos).
- La base de datos debe existir antes de ejecutar migraciones (recomendado: `integration_v2` en desarrollo local).

## 4.2 Trazabilidad

- Cada operacion genera un Record.
- Los Records soportan jerarquia via `record_id`.
- Estados canonicos: `init`, `processing`, `success`, `error`, `warning`.

## 4.3 Flujo de Eventos

Webhook
  -> WebhookController
  -> WebhookCustomProcessJob
  -> EventProcessingService
  -> Event (solo datos)
  -> Listener (solo dispatch)
  -> Job (procesamiento pesado)

## 4.4 Regla Critica de Listeners

- Los Listeners SOLO deben disparar Jobs (`Job::dispatch()`), sin procesamiento pesado ni llamadas externas.

---

# 5. Interfaces (Contratos Clave)

## 5.1 EventProcessingService

- `processEvent(string $subscriptionType, array $payload, Platform $platform, ?Record $parentRecord = null): array`
- `executeEvent(Event $event, array $payload, Platform $platform, ?Record $parentRecord = null): array`
- `getServiceClass(Platform $platform): ?string`
- `dispatchEvent(Event $event, Record $record, array $data): void`

## 5.2 EventFlowService

- `executeEventFlow(Event $rootEvent, array $initialData, ?Record $parentRecord = null): array`
- `getEventFlow(Event $event): array`

## 5.3 EventLoggingService

- `createEventRecord(string $eventType, string $status, array $payload, string $message, ?int $parentRecordId = null, ?int $eventId = null): Record`
- `logEventSuccess(Record $record, string $message): void`
- `logEventError(Record $record, \Exception $exception): void`

## 5.4 BaseService

- `loadEvent(int $event_id): Event`
- `execute(string $subscriptionType, $class = null, $payload = null, bool $sendData = true)`
- `createRecord(string $type, string $status, $data, ?int $parent_record_id = null, string $message = 'Processing data', $created_at = null, $updated_at = null): Record`

## 5.5 HubspotService

- `companyCreatedWebhook(array $payload): array`
- `contactCreatedWebhook(array $payload): array`
- `dealPropertyChange(string $subscriptionType, array $payload, Record $record): void`
- `objectPropertyChange(string $subscriptionType, array $payload, Record $record): void`
- `createProducts(array $products): array`
- `updateProducts(array $updateProducts): array`
- `getSignedQuotes(): array`
- `getArchivedQuotes(): array`
- `createInvoice(): mixed`
- `createObject(): mixed`
- `updateObject(): void`
- `updateCompany(array $payload): array`
- `syncContactExecutionResponse(array $payload): array`

## 5.6 OdooService

- `resPartnerCreateCompany(array $payload): array`
- `resPartnerCreateContact(): void`
- `createUpdateContact(array $companyData, array $relatedPropertiesMap = [], string $contactType = 'company', ?int $parentId = null): int`
- `syncCreateProducts(): array`
- `syncUpdateProducts(): array`
- `createSaleOrder(array $data): array`
- `createSaleSubscription(array $data): array`
- `saleOrderCanceled(array $data): array`
- `accountMovePosted(string $subscriptionType, array $payload, Record $record): void`
- `resPartnerUpdate(string $subscriptionType, array $payload, Record $record): void`
- `getListPricesByProduct(array $variant, Record $listPriceRecord): array`

## 5.7 GenericPlatformPort (Sin SDK)

- `resolveEndpoint(Event $event): string`
- `resolveMethod(Event $event): string`
- `resolveHeaders(Event $event, Platform $platform): array`
- `resolveQueryParams(Event $event, array $payload): array`
- `resolveBody(Event $event, array $payload): array`
- `resolveTimeout(Event $event): int`
- `resolveRetryPolicy(Event $event): array`

## 5.8 AuthStrategyResolver

- `resolveAuthMode(Platform $platform, Event $event): string`
- `buildBearerAuthHeaders(Platform $platform): array`
- `buildBasicAuthHeaders(Platform $platform): array`
- `getOAuth2AccessToken(Platform $platform): string`
- `buildOAuth2Headers(Platform $platform): array`

## 5.9 Notas de Error en HubSpot

- Cuando falle una sincronizacion de contacto cuyo flujo involucre HubSpot, el sistema DEBE intentar registrar una nota en el objeto HubSpot correspondiente.
- La nota DEBE crearse desde un Job o Service; NUNCA desde un Listener.
- El identificador tecnico del objeto (`hubspot_object_id` o equivalente) DEBE resolverse como contexto tecnico del flujo y NO forma parte del mapping de propiedades.
- Las propiedades de negocio a escribir en HubSpot DEBEN salir exclusivamente del mapping configurado y/o de `destination_response.data`.
- La nota DEBE incluir, cuando exista:
  - tipo de operacion (`create`, `update`, `write-back`, `generic.external.call`)
  - mensaje resumido del fallo
  - propiedad involucrada si la plataforma la identifica
  - codigo o categoria del error (`READ_ONLY_VALUE`, `VALIDATION_ERROR`, `404`, etc.)
  - `record_id` y `event_id`
  - timestamp
- La nota NO DEBE incluir secretos, tokens, headers sensibles ni payloads completos.
- El resultado del intento de crear la nota DEBE almacenarse en `Record.details.hubspot_note`.
- Si la nota falla, el flujo principal DEBE conservar el error original y registrar por separado el resultado del intento de nota.

## 5.10 Propiedades Tecnicas de Control por Plataforma

- Para flujos de sincronizacion manual/controlada desde HubSpot, el sistema DEBE soportar propiedades tecnicas por plataforma con el patron `sync_to_{platform}`.
- `sync_to_{platform}` actua como propiedad disparadora del flujo y NO representa un campo de negocio de la entidad.
- El cambio de `sync_to_{platform}` DEBE disparar el envio del grupo completo de propiedades mapeadas para esa plataforma, no un delta parcial por cada propiedad de negocio modificada.
- Valores recomendados para `sync_to_{platform}`:
  - `pending`
  - `processing`
  - `synced`
  - `error`
- Para cada plataforma integrada se DEBEN considerar al menos estas propiedades tecnicas en HubSpot:
  - `{platform}_id`
  - `last_sync_{platform}`
  - `sync_status_{platform}`
  - `last_error_{platform}`
- Las propiedades tecnicas NO deben disparar nuevos envios hacia la misma plataforma salvo la propiedad de control `sync_to_{platform}` cuando cambie a `pending`.

---

# 6. Contrato de Respuesta Normalizada (Integraciones HTTP)

Todas las integraciones sin SDK deben devolver una estructura uniforme:

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

Reglas:

- `success` depende del mapeo de codigo HTTP y reglas de negocio.
- `retryable = true` para timeout/429/5xx o errores transitorios.
- `error.details` debe ir sanitizado (sin secretos ni headers sensibles).
- `request_id` debe propagarse en logs y records.

---

# 7. Colas, Timeouts y Reintentos

Colas configuradas:

- `webhooks`
- `events`
- `creation`
- `update`
- `sync`
- `signed-quotes`
- `validation`
- `processing`

Timeouts y reintentos por tipo de Job:

- Creacion de entidades: `timeout = 300s`, `tries = 3`, `backoff = 60s`
- Actualizacion de entidades: `timeout = 180s`, `tries = 3`, `backoff = 30s`
- Sincronizacion masiva: `timeout = 900s`, `tries = 2`, `backoff = 300s`
- Procesamiento de cotizaciones: `timeout = 900s`, `tries = 1`, `backoff = 300s`
- Obtencion de listas de precios: `timeout = 300s`, `tries = 2`, `backoff = 60s`

---

# 8. Seguridad

- Secretos solo en `.env`.
- No loguear secretos ni headers sensibles.
- Prohibido hardcodear credenciales.
- Las llamadas externas deben sanitizar logs y errores.

---

# 9. Funcionalidades Heredadas del Proyecto Base (OBLIGATORIO)

Las siguientes capacidades existen en `/Users/hint/laravel-sites/integrador` y DEBEN preservarse en esta refactorizacion, aunque no aparezcan en el alcance inicial:

## 9.1 Webhooks con Firma por Plataforma

- El webhook debe validar firma por plataforma usando:
  - `Platform.secret_key` como secreto.
  - `Platform.signature` como nombre del header.
- Si falta `secret_key` o `signature`, el webhook debe rechazar la solicitud.
- Se debe usar Spatie Webhook Client con un `WebhookCustomSignatureValidator`, `WebhookCustomProfile` y `WebhookCustomResponse`.
- Los webhooks se deben almacenar en `webhook_calls` (Spatie).

## 9.2 Procesamiento de Webhooks con Multiples Payloads

- Si el payload es un array de eventos, se debe procesar cada item.
- Se deben reconocer keys comunes de colecciones: `data`, `items`, `results`, `objects`, `records`, `entities`.
- Cada payload se procesa de forma independiente para tolerar fallos parciales.

## 9.3 Eventos Programados (Schedule)

- Los eventos pueden ser `type = schedule` con `schedule_expression` (cron).
- Campos relacionados:
  - `last_executed_at`
  - `command_sql` (opcional)
  - `enable_update_hubdb` + `hubdb_table_id` (opcional)
- Debe existir comando `events:search-schedule` que evalua cron y despacha `ExecuteEventJob`.
- Debe existir ejecucion manual (`execute-now`) para schedule events.

## 9.4 Testing de Eventos y Flujos

- Se debe exponer un endpoint de testing de eventos que:
  - Genere un payload de ejemplo si no se provee.
  - Valide estructura del payload.
  - Ejecute el flujo real con `EventProcessingService`.
  - Retorne resultado y metadata (tiempo, payload usado, eventos procesados).
- Debe existir endpoint para ejecutar un flujo completo desde el evento raiz.
- Debe existir endpoint para estadisticas basicas de eventos.
- Debe existir ejecucion manual desde UI/API para eventos `schedule` (`execute-now`).

## 9.5 Triggers Condicionales de Eventos

- Los eventos pueden tener grupos de triggers (AND entre grupos).
- Cada grupo tiene condiciones con operador y logica OR/AND interna.
- Operadores soportados:
  - `equals`, `not_equals`, `contains`, `not_contains`
  - `greater_than`, `less_than`, `greater_than_or_equal`, `less_than_or_equal`
  - `is_null`, `is_not_null`, `starts_with`, `ends_with`
- Si no hay triggers activos, el evento siempre se ejecuta.

## 9.6 Procesamiento de Cotizaciones Firmadas (HubSpot)

- Debe existir flujo asincrono para cotizaciones firmadas:
  1. `getSignedQuotes`
  2. `ProcessSignedQuotesJob`
  3. `ValidateEntitiesJob`
  4. `CreateOrUpdateEntityJob`
  5. `UpdateHubSpotJob`
  6. `CreateQuoteJob`
- El flujo debe actualizar Records y permitir reintentos por job.

## 9.6.1 Notas Operativas de Fallo en Contactos

- En fallos de sincronizacion de contactos, el sistema DEBE intentar agregar una nota visible en el contacto HubSpot afectado.
- Casos minimos que deben intentar nota:
  - error al crear o actualizar el contacto en plataforma destino
  - error al hacer write-back de respuesta hacia HubSpot
  - error de validacion de propiedades
  - error por propiedad de solo lectura
  - error por contexto tecnico faltante (`hubspot_object_id` o equivalente)
  - error HTTP relevante de integracion (`4xx`, `5xx`, timeout)
- La nota DEBE ser breve y entendible por el usuario final; el detalle tecnico completo debe permanecer en `Record.details`.
- El sistema DEBE preferir mensajes accionables, por ejemplo indicando la propiedad que fallo cuando la plataforma lo reporte.

## 9.6.2 Sincronizacion Controlada por Propiedad Tecnica

- Para contactos y futuras entidades sincronizadas desde HubSpot hacia plataformas externas, el sistema DEBE permitir un patron de disparo por propiedad tecnica de control por plataforma.
- Ejemplo canónico para ASPEL:
  - `sync_to_aspel = pending` dispara el flujo HubSpot -> ASPEL
  - el payload enviado hacia ASPEL debe contener el grupo completo de propiedades mapeadas del contacto
  - el write-back a HubSpot debe actualizar el estado tecnico del flujo (`aspel_id`, `last_sync_aspel`, `sync_status_aspel`, `last_error_aspel`, `sync_to_aspel`)
- El disparo NO debe depender de cada propiedad de negocio individual (`phone`, `email`, `rfc`, etc.).
- El flujo DEBE evitar ciclos de integracion con estas reglas minimas:
  - solo `sync_to_{platform} = pending` puede iniciar el envio manual/controlado
  - las propiedades tecnicas de write-back NO deben reactivar el flujo
  - el integrador NO debe volver a colocar `sync_to_{platform} = pending` automaticamente
- El patron debe ser extensible a nuevas plataformas con el esquema:
  - `sync_to_odoo`, `sync_status_odoo`, `last_sync_odoo`, `odoo_id`, `last_error_odoo`
  - `sync_to_netsuite`, `sync_status_netsuite`, `last_sync_netsuite`, `netsuite_id`, `last_error_netsuite`
- Para plataformas implementadas temporalmente mediante `generic.external.call`, el patron de propiedad de control sigue siendo valido y debe coexistir con el contrato HTTP normalizado.

## 9.6.3 Polling de Contactos ASPEL -> HubSpot por `VERSION_SINC`

- El sistema DEBE soportar sincronizacion programada de contactos modificados en ASPEL SAE mediante polling por cursor persistente.
- El contrato minimo esperado de ASPEL es:
  - `GET /api/contacts/changes?sinceTs={isoDateTime}&sinceClave={clave}&take={n}`
  - `GET /api/products/changes?sinceTs={isoDateTime}&sinceClave={clave}&take={n}`
  - `GET /api/contacts/{clave}`
- El endpoint `changes` DEBE tratarse como fuente oficial de:
  - cursor (`nextSinceTs`, `nextSinceClave`)
  - idempotencia (`clave + versionSinc`)
  - paginacion (`hasMore`)
- El flujo canonico DEBE ser:
  1. evento `schedule` en ASPEL con `method_name = getUpdatedContacts`
  2. carga de cursor persistente desde `configs`
  3. consumo paginado de `changes`
  4. fetch de detalle completo por `clave`
  5. evento siguiente en HubSpot con `method_name = syncAspelContactToHubspot`
  6. update o create del contacto HubSpot segun matching
  7. persistencia del cursor solo cuando el batch completo termina con exito
- El cursor persistente DEBE usar estas claves:
  - `aspel.contacts.cursor.<event_id>.since_ts`
  - `aspel.contacts.cursor.<event_id>.since_clave`
  - `aspel.contacts.cursor.<event_id>.last_run_started_at`
  - `aspel.contacts.cursor.<event_id>.last_run_finished_at`
  - `aspel.contacts.cursor.<event_id>.last_run_status`
  - `aspel.contacts.cursor.<event_id>.last_error`
- Defaults del primer arranque:
  - `sinceTs = now - 24h`
  - `sinceClave = ""`
  - productos ASPEL siguen el mismo patrón de cursor persistente que contactos
- Regla critica:
  - si falla cualquier item del batch, el cursor NO debe avanzar
  - si `hasMore = true`, el mismo run debe seguir procesando inmediatamente
- Matching hacia HubSpot DEBE seguir este orden:
  1. `clave`
  2. `rfc`
  3. `phone`
  4. `email`
- Si no encuentra contacto en HubSpot, el sistema DEBE crearlo.
- Si encuentra multiples coincidencias para una misma llave, el sistema DEBE detener la operacion de ese item, registrar el contexto en `Record.details` y NO actualizar automaticamente.
- El flujo ASPEL -> HubSpot NO debe reutilizar `sync_to_aspel` ni disparar el trigger manual HubSpot -> ASPEL.
- El update/create exitoso en HubSpot DEBE escribir propiedades tecnicas minimas:
  - `clave`
  - `last_sync_aspel`
  - `sync_status_aspel = success`
  - `last_error_aspel = ''`
  - propiedad de auditoria para `versionSinc`, si existe en HubSpot
- Mejora objetivo recomendada para la API de ASPEL:
  - incluir `versionSinc` tambien en `GET /api/contacts/{clave}`
  - normalizar timestamps de `changes` a UTC explicito

## 9.7 Validacion y Actualizacion Inteligente de Entidades

- Se debe detectar si una entidad ya existe en la plataforma destino usando `{platform}_id`.
- Si existe, comparar campos importantes y decidir:
  - `create`
  - `update`
  - `no_change`
- Deben registrarse metadatos de sincronizacion en HubSpot:
  - `last_sync_{platform}`
  - `sync_operation_{platform}`
  - `updated_fields_{platform}`

## 9.8 Validacion y Cache de Productos (HubSpot)

- Validacion hibrida: cache local + consulta selectiva en HubSpot.
- Cache indexado por propiedades unicas de origen (`default_code`, `name`, `display_name`) y `{platform}_id`.
- TTL recomendado: 2 horas.
- Rate limiting: 100ms entre consultas individuales.
- Se deben exponer comandos artisan:
  - `products:cache clear|preload|stats|validate`
  - `events:clear-cache`, `events:clear-records`, `events:clear-all`, `hubspot:regenerate-cache`

## 9.9 Endpoints Operativos

- Endpoint para consultar estado de un Record (job status).
- Endpoint para consultar Records hijos relacionados.
- Endpoint de prueba de conexion de plataforma (HubSpot/Odoo/NetSuite).
- CRUD API para `events` y `platforms` protegido por autenticacion/permisos.
- Comando de preflight operativo: `system:preflight` (con opcion `--strict` para release gate).

## 9.10 Guzzle Error Handling sin Truncate

- Usar `CustomBodySummarizer` o `LargeBodySummarizer` para evitar truncado.
- Incluir helper para obtener mensajes completos de excepciones Guzzle/HubSpot.

## 9.11 Propiedades de Tipo Archivo

- Si una propiedad es `file`, se debe descargar desde HubSpot y adjuntar al payload de salida.

## 9.12 Admin y Seguridad de UI

- El frontend administrativo debe estar protegido por autenticacion.
- Se deben mantener roles y permisos para acciones CRUD (usuarios, eventos, plataformas, propiedades, categorias).
- Para eventos de integracion generica (`generic.external.call`), toda la configuracion tecnica HTTP/auth/retry/idempotencia debe mostrarse encapsulada en un bloque colapsable controlado por UI (`check` o `toggle`), evitando exponer esos campos cuando no aplica.
- En todos los recursos administrativos (usuarios, roles, categorias, configs, eventos, plataformas, propiedades), la vista de listado (grid/tabla) y la vista de formulario (crear/editar) deben estar separadas en pantallas/rutas distintas.
- La UI debe priorizar claridad operativa: acciones primarias visibles, navegacion consistente entre listado/formulario, y menor densidad visual por pantalla.
- El formulario de plataformas debe mostrar configuracion especifica por tipo (`hubspot`, `odoo`, `netsuite`, `generic`) en bloques dedicados y no como JSON crudo editable para uso normal.
- El formulario de plataformas debe incluir accion de `Test connection` en cabecera (modo edicion), junto con `Save platform`.
- Jerarquia de roles obligatoria:
  - `superadmin` (nivel mas alto, bypass total de permisos).
  - `admin` (alto privilegio, acotado a permisos asignados).
  - roles operativos (sin bypass).
- Debe existir seeder dedicado para `superadmin` con bootstrap inicial del sistema:
  - `username`: `charly91rubio`
  - `first_name`: `Carlos`
  - `last_name`: `Rubio`
  - `email`: `carlos91rubio@gmail.com`
  - `password`: `ch_rubio2026` (almacenada con hash seguro).
- El modelo `users` debe soportar campos: `username` (unique), `first_name`, `last_name`.

---

# 10. Politica de Rate Limiting por Plataforma (V1.1)

El sistema DEBE aplicar controles de rate limit de forma explicita para evitar bloqueos por API externas.

## 10.1 Reglas Generales

- El rate limiting se aplica por plataforma y por endpoint.
- Las politicas deben ser configurables por `.env` o config.
- En `429` o `5xx`, se aplica backoff exponencial con jitter.
- Las reintentos deben respetar `retry-after` si existe.
- Se deben registrar logs estructurados con:
  - `platform`, `endpoint`, `status_code`, `retry_after`, `attempt`, `backoff_ms`.

## 10.2 Configuracion (sugerida)

- `HUBSPOT_RATE_LIMIT_RPS`
- `ODOO_RATE_LIMIT_RPS`
- `NETSUITE_RATE_LIMIT_RPS`
- `RATE_LIMIT_BACKOFF_BASE_MS`
- `RATE_LIMIT_BACKOFF_MAX_MS`
- `RATE_LIMIT_JITTER`
- Config centralizada en `config/rate-limits.php`.

## 10.3 Scope de Aplicacion

- HubSpot: queries de propiedades, batch de productos, cotizaciones.
- Odoo: XML-RPC listados y actualizaciones.
- NetSuite: llamadas REST o SDK.
- Endpoints genericos: usar `config/generic-platforms.php`.

---

Fin del Documento.

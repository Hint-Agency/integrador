# agents.md

Proyecto: INTEGRADOR - Sistema de Integracion Multiplataforma (Laravel 11 + Inertia)
Basado en: spec.md v1.1.4
Version: 1.5
Estado: ACTIVE

---

# INTEGRADOR - Sistema de Integración Multiplataforma (Laravel 11 + Inertia)

## 1) Propósito
Este archivo define el plan, arquitectura, convenciones y límites para implementar el proyecto correctamente y sin alucinar. TODO lo descrito aquí es obligatorio y se debe seguir tal cual. Si falta información para ejecutar una tarea, Codex debe detenerse y pedirla en lugar de inventar.

## 2) Definición del agente (estructura + prompt)
### 2.1 Rol
Eres un desarrollador senior con experiencia en Laravel 11, Inertia.js, arquitectura de software y desarrollo de integraciones empresariales, responsable de implementar este proyecto siguiendo arquitectura hexagonal y las convenciones definidas en este documento.

### 2.2 Objetivo
Construir un sistema de integración multiplataforma que sincronice datos entre HubSpot, Odoo y NetSuite mediante webhooks, eventos, listeners y jobs asíncronos, manteniendo trazabilidad completa de todas las operaciones.

### 2.3 Responsabilidades
1. Seguir fielmente el contenido de `agents.md`.
2. Mantener la separación de responsabilidades: Services, Events, Listeners, Jobs.
3. **CRÍTICO**: Los Listeners SOLO deben disparar Jobs. NO deben hacer procesamiento pesado ni llamar a `helpers()->forwarding()`.
4. Implementar procesamiento asíncrono mediante Jobs para operaciones pesadas.
5. Mantener trazabilidad completa mediante el sistema de Records.
6. Evitar hardcodear credenciales y exigir variables de entorno.
7. Convertir eventos síncronos que puedan ser asíncronos en Jobs cuando sea apropiado.
8. Alinear implementación con `spec.md` (incluye funcionalidades heredadas del proyecto base).
9. Respetar jerarquia de roles `superadmin > admin > operator`.
10. Mantener y ejecutar seeder obligatorio de superadmin bootstrap.
11. En errores de sincronización de contactos relacionados con HubSpot, intentar registrar una nota visible en el objeto HubSpot afectado y guardar el resultado en `Record.details.hubspot_note`.
12. Modelar sincronizaciones manuales/controladas por plataforma usando propiedades técnicas `sync_to_{platform}` y write-back de estado técnico en HubSpot.
13. Soportar polling programado de contactos ASPEL -> HubSpot con cursor persistente, idempotencia por `clave + versionSinc` y matching por `clave`, `rfc`, `phone`, `email`.
14. Soportar polling programado de productos ASPEL -> HubSpot con cursor persistente, idempotencia por `clave + versionSinc`, detalle por `clave` y matching de producto por `clave`.

### 2.4 Límites
1. No inventar endpoints, credenciales o comportamientos no descritos.
2. No introducir dependencias o frameworks fuera del stack definido.
3. No modificar alcances sin aprobación del usuario.
4. Detenerse si faltan datos necesarios y pedirlos.
5. No procesar eventos síncronamente cuando puedan ser Jobs asíncronos.
6. No usar el id técnico de HubSpot como propiedad mapeada editable; debe viajar como contexto técnico del flujo.
7. No disparar sincronizaciones de negocio por cambios en propiedades técnicas de write-back (`{platform}_id`, `last_sync_{platform}`, `sync_status_{platform}`, `last_error_{platform}`).
8. No avanzar cursores persistentes de polling si falla cualquier item del batch o el detalle de ASPEL.

### 2.5 Herramientas permitidas
1. Laravel 11 Framework.
2. Inertia.js para frontend.
3. Vue 3 para componentes frontend.
4. HubSpot SDK `hubspot/api-client`.
5. Ripcord para Odoo XML-RPC.
6. Guzzle HTTP Client para APIs REST.
7. Laravel Queue System para procesamiento asíncrono.
8. Spatie Laravel Webhook Client para webhooks.

### 2.6 Prompt del agente (instrucción operativa)
Eres un desarrollador senior con experiencia en Laravel 11 e Inertia.js. Debes implementar el proyecto siguiendo estrictamente `agents.md`. No inventes endpoints, credenciales ni modelos. Usa arquitectura hexagonal con Services `*Service`, Events `*Event`, Listeners `*Listener` y Jobs `*Job`. 

**REGLA CRÍTICA**: Los Listeners SOLO deben disparar Jobs usando `Job::dispatch()`. NO deben llamar a `helpers()->forwarding()` ni hacer procesamiento pesado. Todo el procesamiento debe estar en Jobs.

**REGLA DE CONTEXTO HUBSPOT**: `hubspot_object_id` y equivalentes se usan solo para identificar el objeto a actualizar. Las propiedades a escribir deben salir exclusivamente del mapping configurado o de `destination_response.data`, nunca del contexto técnico.

**REGLA DE CONTROL POR PLATAFORMA**: Para flujos manuales/controlados, el trigger debe vivir en una propiedad técnica `sync_to_{platform}`. Esa propiedad dispara el envío del grupo completo de datos mapeados para la plataforma; no deben dispararse sincronizaciones por cada cambio de propiedad de negocio.

Si falta información, detente y pide aclaración. Convierte eventos que puedan ser asíncronos en Jobs.

## 3) Alcance
- Desarrollar el proyecto con Laravel 11 + Inertia.js + Vue 3.
- Integración con HubSpot, Odoo y NetSuite.
- Sistema de webhooks para recibir eventos de plataformas externas.
- Sistema de eventos/listeners/jobs para procesamiento asíncrono.
- Mapeo de propiedades entre plataformas.
- Trazabilidad completa mediante Records.
- Frontend administrativo para gestión de eventos, plataformas y propiedades.
- Procesamiento de cotizaciones firmadas, productos, empresas, contactos, facturas.

## 4) Fuera de alcance
- No crear nuevas integraciones fuera de HubSpot, Odoo y NetSuite sin aprobación.
- No migrar infraestructura ni despliegues.
- No inventar endpoints externos, credenciales, o funcionalidades no descritas.
- No hardcodear tokens o secretos en código.
- No procesar eventos síncronamente cuando puedan ser Jobs.

---

## 4.1 Funcionalidades heredadas obligatorias (spec.md §9)
- Webhooks con firma por plataforma (Spatie Webhook Client).
- Procesamiento de webhooks con múltiples payloads.
- Eventos programados (`schedule_expression`) y comando `events:search-schedule`.
- Testing de eventos y ejecución de flujos completos desde el evento raíz.
- Triggers condicionales por grupo y operadores.
- Pipeline de cotizaciones firmadas con jobs encadenados.
- Validación inteligente de entidades (create/update/no_change).
- Caché de productos y comandos artisan de mantenimiento.
- Endpoints operativos (job status, test connection).
- Manejo de errores Guzzle sin truncate.
- Soporte de propiedades tipo archivo (descarga desde HubSpot).
- Admin UI protegido con roles/permisos.
- Seeder bootstrap de `superadmin` obligatorio.
- Notas operativas de fallo en contactos HubSpot con resultado almacenado en `Record.details.hubspot_note`.
- Polling programado de contactos ASPEL con cursor persistente en `configs`, detalle por `clave` y sincronización create/update hacia HubSpot.
- Polling programado de productos ASPEL con cursor persistente en `configs`, detalle por `clave` y sincronización update/create hacia HubSpot.

---

## Formato de Control de Progreso

Usar el siguiente formato en reportes y checklist:

Formato: <Item> -- STATUS: PENDING | IN_PROGRESS | VERIFIED | BLOCKED

## 5) Convenciones de nombres (OBLIGATORIO)
Domain
- Event, Record, Platform, Property, PropertyRelationship
- EventType (Enum)
- EventTrigger, EventTriggerGroup

Application (Services)
- EventProcessingService
- EventFlowService
- EventTriggerService
- EventLoggingService
- HubspotService, OdooService, NetSuiteService
- GenericPlatformService
- BaseService

Events
- BaseEvent
- Company/CreateCompanyEvent, Company/UpdateCompanyEvent
- Product/CreateProductEvent, Product/UpdateProductEvent
- Invoice/CreateInvoiceEvent, Invoice/CreateRecurringInvoiceEvent
- SaleOrder/CreateSaleOrderEvent
- Quotes/SendingQuotesDataEvent
- Response/SendResponseEvent
- Object/UpdateObjectEvent
- Odoo/GetListPricesEvent, Odoo/GetStoreProductsEvent
- NextEvent

Listeners
- BaseListener
- Company/CreateCompanyListener, Company/UpdateCompanyListener
- Product/CreateProductListener, Product/UpdateProductListener
- Invoice/CreateInvoiceListener, Invoice/CreateRecurringInvoiceListener
- SaleOrder/CreateSaleOrderListener
- Quotes/SendingQuotesDataListener
- Response/SendResponseListener
- Object/UpdateObjectListener
- Odoo/GetListPricesListener, Odoo/GetStoreProductsListener
- NextEventListener

Jobs
- ExecuteEventJob
- WebhookCustomProcessJob
- HubSpot/HubSpotQuoteProcessingJob
- HubSpot/HubSpotCompanyPropertiesSyncJob
- HubSpot/HubSpotAddNoteJob
- Odoo/OdooGetListPricesJob
- EndpointExecutionJob
- ProcessSignedQuotesJob
- ValidateEntitiesJob
- CreateOrUpdateEntityJob
- UpdateHubSpotJob
- CreateQuoteJob
- ProcessNextEventJob

Console Commands
- SearchEventSchedule (signature `events:search-schedule`)
- EventsClearCacheCommand (signature `events:clear-cache`)
- EventsClearRecordsCommand (signature `events:clear-records`)
- EventsClearAllCommand (signature `events:clear-all`)
- HubspotRegenerateCacheCommand (signature `hubspot:regenerate-cache`)
- ProductsCacheCommand (signature `products:cache`)

Generic Integrations (Sin SDK)
- GenericPlatformPort
- GenericHttpAdapter
- AuthStrategyResolver

Controllers
- EventController
- PlatformController
- PropertyController
- WebhookController
- DashboardController
- JobStatusController
- ConfigController
- CategoryController
- RoleController
- UserController

Models
- Event, Record, Platform, Property, PropertyRelationship
- User, Role, Category, Config

### 5.1 Tabla canónica de nombres (OBLIGATORIO)
- Usar `Quotes` (plural) como carpeta oficial para eventos y listeners: `app/Events/Quotes/...` y `app/Listeners/Quotes/...`.
- Usar `ListPrices` (sin `s` adicional) en todos los artefactos: `GetListPricesEvent`, `GetListPricesListener`, `OdooGetListPricesJob`, `ProcessListPricesJob`.
- Mantener `NextEvent` / `NextEventListener` / `ProcessNextEventJob` como convención única para encadenamiento.
- Ante conflicto de naming histórico, prevalece esta tabla canónica para nuevas implementaciones y refactorizaciones.

## 6) Arquitectura Hexagonal (Mapa)
### 6.1 Domain
- Entidades: Event, Record, Platform, Property, PropertyRelationship
- Value Objects: EventType (Enum)
- Policies: EventTrigger, EventTriggerGroup

### 6.2 Application (Services)
- EventProcessingService: Procesa eventos desde webhooks
- EventFlowService: Ejecuta flujos completos de eventos encadenados
- EventTriggerService: Gestiona triggers de eventos
- EventLoggingService: Servicio de logging y trazabilidad
- HubspotService: Lógica de negocio para HubSpot
- OdooService: Lógica de negocio para Odoo
- NetSuiteService: Lógica de negocio para NetSuite
- BaseService: Clase base para todos los servicios

### 6.3 Ports (Interfaces)
- Los servicios actúan como puertos de aplicación
- Los eventos actúan como contratos de dominio

### 6.4 Adapters (Implementaciones)
#### Drivers (Entrada)
- WebhookController: Recibe webhooks de plataformas externas
- EventController: API REST para gestión de eventos
- PlatformController: API REST para gestión de plataformas

#### Driven (Salida)
- HubspotApiServiceRefactored: Adaptador para API de HubSpot
- OdooApiService: Adaptador para API XML-RPC de Odoo
- NetSuiteApiService: Adaptador para API de NetSuite
- GenericHttpAdapter: Adaptador HTTP para plataformas sin SDK

### 6.5 Integraciones genéricas por endpoint (Sin SDK)
- Objetivo: Permitir integrar plataformas externas que no tienen SDK oficial, configurando endpoint por evento.
- Patrón obligatorio:
  - `GenericPlatformService`: prepara payload, resuelve auth, define endpoint/método por evento.
  - `GenericHttpAdapter`: ejecuta request HTTP (`GET/POST/PUT/PATCH/DELETE`).
  - `EndpointExecutionJob`: único ejecutor de llamadas externas (siempre asíncrono).
- Secuencia canónica de ejecución (sin ambigüedad):
  1. `EventProcessingService` emite el Event de integración genérica (DTO).
  2. El Listener correspondiente recibe el Event, crea `Record` inicial (`init`) y despacha `EndpointExecutionJob`.
  3. `EndpointExecutionJob` resuelve endpoint/método/auth con `GenericPlatformService` y ejecuta la llamada vía `GenericHttpAdapter`.
  4. `EndpointExecutionJob` actualiza `Record` (`processing` -> `success`/`error`/`warning`) con respuesta normalizada.
  5. Si existe `to_event_id` (o condición de encadenamiento), el Job despacha `ProcessNextEventJob`.
- Regla de ejecución:
  - Prohibido ejecutar requests HTTP directos en Controllers/Listeners.
  - Los Listeners solo crean `Record` y despachan `EndpointExecutionJob`.
  - Toda llamada saliente a plataformas sin SDK se ejecuta por cola.

## 7) Objetivo por archivo (OBLIGATORIO)
- `app/Models/Event.php`: Modelo de eventos con relaciones y métodos de negocio.
- `app/Models/Record.php`: Modelo de registros de ejecución con jerarquía.
- `app/Models/Platform.php`: Modelo de plataformas integradas.
- `app/Models/Property.php`: Modelo de propiedades mapeadas.
- `app/Models/PropertyRelationship.php`: Modelo de relaciones entre propiedades.
- `app/Enums/EventType.php`: Enum con todos los tipos de eventos soportados.
- `app/Services/EventProcessingService.php`: Procesa eventos desde webhooks.
- `app/Services/EventFlowService.php`: Ejecuta flujos completos de eventos.
- `app/Services/EventTriggerService.php`: Gestiona triggers de eventos.
- `app/Services/EventLoggingService.php`: Servicio de logging estructurado.
- `app/Services/Base/BaseService.php`: Clase base para servicios de plataforma.
- `app/Services/Hubspot/HubspotService.php`: Servicio principal de HubSpot.
- `app/Services/Odoo/OdooService.php`: Servicio principal de Odoo.
- `app/Services/NetSuite/NetSuiteService.php`: Servicio principal de NetSuite.
- `app/Services/Generic/GenericPlatformService.php`: Servicio para plataformas sin SDK basado en endpoint por evento.
- `app/Services/Generic/AuthStrategyResolver.php`: Resolución de estrategias de autenticación (`bearer_api_key`, `basic_auth`, `oauth2_client_credentials`).
- `app/Events/BaseEvent.php`: Clase base para todos los eventos.
- `app/Events/Company/CreateCompanyEvent.php`: Evento de creación de empresa.
- `app/Events/Company/UpdateCompanyEvent.php`: Evento de actualización de empresa.
- `app/Events/Product/CreateProductEvent.php`: Evento de creación de producto.
- `app/Events/Product/UpdateProductEvent.php`: Evento de actualización de producto.
- `app/Events/Invoice/CreateInvoiceEvent.php`: Evento de creación de factura.
- `app/Events/Invoice/CreateRecurringInvoiceEvent.php`: Evento de factura recurrente.
- `app/Events/SaleOrder/CreateSaleOrderEvent.php`: Evento de creación de orden de venta.
- `app/Events/Quotes/SendingQuotesDataEvent.php`: Evento de envío de cotización.
- `app/Events/Response/SendResponseEvent.php`: Evento de envío de respuesta.
- `app/Events/Object/UpdateObjectEvent.php`: Evento de actualización de objeto.
- `app/Events/Odoo/GetListPricesEvent.php`: Evento de obtención de listas de precios.
- `app/Events/Odoo/GetStoreProductsEvent.php`: Evento de obtención de productos de tienda.
- `app/Events/NextEvent.php`: Evento para encadenar eventos siguientes.
- `app/Listeners/BaseListener.php`: Clase base para todos los listeners (ShouldQueue).
- `app/Listeners/Company/CreateCompanyListener.php`: Listener para creación de empresa.
- `app/Listeners/Company/UpdateCompanyListener.php`: Listener para actualización de empresa.
- `app/Listeners/Product/CreateProductListener.php`: Listener para creación de producto.
- `app/Listeners/Product/UpdateProductListener.php`: Listener para actualización de producto.
- `app/Listeners/Invoice/CreateInvoiceListener.php`: Listener para creación de factura.
- `app/Listeners/Invoice/CreateRecurringInvoiceListener.php`: Listener para factura recurrente.
- `app/Listeners/SaleOrder/CreateSaleOrderListener.php`: Listener para creación de orden de venta.
- `app/Listeners/Quotes/SendingQuotesDataListener.php`: Listener para envío de cotización.
- `app/Listeners/Response/SendResponseListener.php`: Listener para envío de respuesta.
- `app/Listeners/Object/UpdateObjectListener.php`: Listener para actualización de objeto.
- `app/Listeners/Odoo/GetListPricesListener.php`: Listener para listas de precios.
- `app/Listeners/Odoo/GetStoreProductsListener.php`: Listener para productos de tienda.
- `app/Listeners/NextEventListener.php`: Listener para eventos siguientes.
- `app/Jobs/ExecuteEventJob.php`: Job para ejecutar eventos programados.
- `app/Jobs/WebhookCustomProcessJob.php`: Job para procesar webhooks.
- `app/Jobs/HubSpot/HubSpotQuoteProcessingJob.php`: Job para procesar cotizaciones de HubSpot.
- `app/Jobs/HubSpot/HubSpotCompanyPropertiesSyncJob.php`: Job para sincronizar propiedades de empresas.
- `app/Jobs/HubSpot/HubSpotAddNoteJob.php`: Job para agregar notas en HubSpot.
- `app/Jobs/Odoo/OdooGetListPricesJob.php`: Job para obtener listas de precios de Odoo.
- `app/Jobs/Generic/EndpointExecutionJob.php`: Job para ejecución HTTP asíncrona en plataformas sin SDK.
- `app/Jobs/ProcessSignedQuotesJob.php`: Orquestador de cotizaciones firmadas.
- `app/Jobs/ValidateEntitiesJob.php`: Valida entidades en plataforma destino.
- `app/Jobs/CreateOrUpdateEntityJob.php`: Crea/actualiza entidades destino.
- `app/Jobs/UpdateHubSpotJob.php`: Sincroniza IDs/metadatos en HubSpot.
- `app/Jobs/CreateQuoteJob.php`: Crea cotizacion en plataforma destino.
- `app/Jobs/ProcessNextEventJob.php`: Encadena eventos siguientes.
- `app/Http/Controllers/WebhookController.php`: Controlador para recibir webhooks.
- `app/Http/Controllers/EventController.php`: Controlador para gestión de eventos.
- `app/Http/Controllers/PlatformController.php`: Controlador para gestión de plataformas.
- `app/Http/Controllers/PropertyController.php`: Controlador para gestión de propiedades.
- `app/Http/Controllers/JobStatusController.php`: Controlador para estado de jobs/records.
- `app/Http/Controllers/ConfigController.php`: Controlador para configuraciones (logo/token).
- `app/WebhookClient/WebhookCustomSignatureValidator.php`: Validador de firmas de webhooks.
- `app/WebhookClient/WebhookCustomProfile.php`: Perfil personalizado de webhooks.
- `app/WebhookClient/WebhookCustomResponse.php`: Respuesta personalizada de webhooks.
- `app/WebhookClient/WebhookProcessor.php`: Procesador de webhooks.
- `routes/webhooks.php`: Rutas de webhooks.
- `routes/api.php`: Rutas de API REST.
- `routes/web.php`: Rutas web con Inertia.
- `config/hubspot.php`: Configuración de HubSpot.
- `config/queue.php`: Configuración de colas.
- `config/queue-custom.php`: Configuración personalizada de colas.
- `config/generic-platforms.php`: Configuración base de auth, dominios permitidos, headers sensibles y políticas de retry/timeout para plataformas sin SDK.
- `config/webhook-client.php`: Configuración de Spatie Webhook Client.
- `app/Services/CustomBodySummarizer.php`: Evita truncado en errores Guzzle.
- `app/Services/LargeBodySummarizer.php`: Alternativa de truncado extendido.
- `app/Helpers/GuzzleHelper.php`: Helper para mensajes completos de errores.

## 7.1) Criterios de aceptación por archivo (OBLIGATORIO)
- `app/Models/Event.php`: Expone relaciones, métodos de negocio y validaciones; no contiene lógica de infraestructura.
- `app/Models/Record.php`: Soporta jerarquía mediante `record_id`; no contiene lógica de procesamiento.
- `app/Models/Platform.php`: Oculta credenciales sensibles; no contiene lógica de API.
- `app/Models/Property.php`: Soporta relaciones con eventos y propiedades relacionadas; no contiene transformaciones.
- `app/Enums/EventType.php`: Define todos los tipos de eventos con métodos helper; no contiene lógica de negocio.
- `app/Enums/EventType.php`: Actúa como registro canónico de tipos soportados para UI y dispatch (`label`, `description`, `group`, `eventClass`, opciones agrupadas), sin cambiar los `event_type_id` persistidos existentes.
- `app/Enums/EventType.php`: En HubSpot, los cambios de propiedad deben definirse por `subscriptionType` real (`contact.propertyChange`, `company.propertyChange`, `deal.propertyChange`, `object.propertyChange`). `hubspot.property.changed` solo se tolera como compatibilidad legacy y no debe ofrecerse como opción principal en UI.
- `app/Services/EventProcessingService.php`: Procesa eventos desde webhooks y delega a servicios; maneja errores esperados.
- `app/Services/EventFlowService.php`: Ejecuta flujos completos de eventos encadenados; transforma datos entre eventos.
- `app/Services/EventLoggingService.php`: Crea y actualiza Records con estados apropiados; no contiene lógica de negocio.
- `app/Services/Base/BaseService.php`: Proporciona funcionalidad base para servicios; no contiene lógica específica de plataforma.
- `app/Services/Hubspot/HubspotService.php`: Implementa métodos específicos de HubSpot; delega llamadas API al adaptador.
- `app/Services/Odoo/OdooService.php`: Implementa métodos específicos de Odoo; delega llamadas API al adaptador.
- `app/Services/NetSuite/NetSuiteService.php`: Implementa métodos específicos de NetSuite; delega llamadas API al adaptador.
- `app/Services/Generic/GenericPlatformService.php`: Resuelve endpoint/método/payload por evento y delega ejecución al Job.
- `app/Services/Generic/AuthStrategyResolver.php`: Resuelve auth por plataforma/evento y protege secretos.
- `app/Events/*`: Contienen SOLO datos del evento; NO contienen lógica de procesamiento; son DTOs serializables. Se permiten únicamente getters de metadatos estáticos (ej. tipo/descripción), sin lógica de negocio.
- `app/Listeners/*`: Implementan ShouldQueue; SOLO disparan Jobs; NO llaman a `helpers()->forwarding()`; NO procesan datos pesados; NO hacen llamadas API directamente.
- `app/Jobs/*`: Contienen TODO el procesamiento pesado; hacen llamadas API; transforman datos; procesan múltiples registros; manejan errores y reintentos apropiados; actualizan Records.
- `app/Jobs/Generic/EndpointExecutionJob.php`: Ejecuta HTTP asíncrono con timeout/retries, guarda trazabilidad y sanitiza logs.
- `app/Http/Controllers/WebhookController.php`: Valida webhooks y delega a Jobs; no procesa síncronamente.
- `app/Http/Controllers/EventController.php`: Expone API REST para gestión; valida permisos y datos.
- Regla de compatibilidad: el admin debe sugerir tipos desde `EventType`, pero debe seguir tolerando valores manuales/legado en `event_type_id` cuando ya existan en datos heredados.
- UX de eventos: cuando `event_type_id = generic.external.call`, el bloque de configuración HTTP genérica debe habilitarse y poder abrirse desde el botón `Show config`; los campos `payload_mapping` y `meta` deben mostrar placeholders orientativos, no solo llaves vacías.
- UX de formularios admin: todos los campos deben exponer `label` visible; para JSON opcionales vacíos (`payload_mapping`, `meta` y similares) se debe preferir ayuda textual de formato antes que dejar contenido por defecto como `{}` o `[]`.
- UX de mapeo: la administración de `property_relationships` debe existir en una pantalla dedicada por evento, separando claramente propiedades origen y destino, con edición explícita de `mapping_key`, estado activo y `meta`.
- Observabilidad de cola: cuando un Job marque `warning`, el `Record.details` debe incluir causa técnica (`reason`, `service_class`, `method_name`, etc.) en lugar de quedar vacío.
- Notas de fallo en HubSpot: en fallos de sincronización de contacto sobre flujos HubSpot (create/update), el sistema debe intentar registrar una nota en el contacto de HubSpot y guardar el resultado de ese intento en `Record.details.hubspot_note`.
- Notas de fallo en HubSpot: en fallos de sincronización de contacto sobre flujos HubSpot (create/update/write-back/generic.external.call), el sistema debe intentar registrar una nota en el contacto de HubSpot y guardar el resultado de ese intento en `Record.details.hubspot_note`.
- Contexto técnico HubSpot: `hubspot_object_id` y equivalentes deben resolverse como contexto del flujo; NO deben depender de `property_relationships` ni escribirse como propiedad editable.
- Write-back de respuesta: cuando un flujo genérico exitoso encadene de regreso a HubSpot, el siguiente evento debe tomar como base `destination_response.data` más contexto técnico mínimo (`hubspot_object_id`, `source_event_id`, etc.), evitando reutilizar el payload completo del evento anterior.
- Propiedades técnicas por plataforma: para HubSpot -> plataforma externa, se debe favorecer el patrón `sync_to_{platform}`, `sync_status_{platform}`, `last_sync_{platform}`, `{platform}_id`, `last_error_{platform}`.
- Triggers por plataforma: el disparo de sincronización controlada debe configurarse sobre `sync_to_{platform} = pending`, no sobre cada propiedad de negocio individual.
- Polling ASPEL -> HubSpot: el flujo programado debe usar `sinceTs` y `sinceClave` persistidos en `configs`, idempotencia por `clave + versionSinc`, fetch de detalle por `GET /api/contacts/{clave}`, matching HubSpot en orden `clave -> rfc -> phone -> email`, y create cuando no exista coincidencia.
- Polling ASPEL productos -> HubSpot: el flujo programado debe usar `sinceTs` y `sinceClave` persistidos en `configs`, idempotencia por `clave + versionSinc`, fetch de detalle por `GET /api/products/{clave}`, matching HubSpot por `clave`, update si existe y create si no existe coincidencia.

## 8) Contratos clave (Interfaces)
### 8.1 EventProcessingService
- `processEvent(string $subscriptionType, array $payload, Platform $platform, ?Record $parentRecord = null): array`
- `executeEvent(Event $event, array $payload, Platform $platform, ?Record $parentRecord = null): array`
- `getServiceClass(Platform $platform): ?string`
- `dispatchEvent(Event $event, Record $record, array $data): void`

### 8.2 EventFlowService
- `executeEventFlow(Event $rootEvent, array $initialData, ?Record $parentRecord = null): array`
- `getEventFlow(Event $event): array`

### 8.3 EventLoggingService
- `createEventRecord(string $eventType, string $status, array $payload, string $message, ?int $parentRecordId = null, ?int $eventId = null): Record`
- `logEventSuccess(Record $record, string $message): void`
- `logEventError(Record $record, \Exception $exception): void`

### 8.4 BaseService
- `loadEvent(int $event_id): Event`
- `execute(string $subscriptionType, $class = null, $payload = null, bool $sendData = true)`
- `createRecord(string $type, string $status, $data, ?int $parent_record_id = null, string $message = 'Processing data', $created_at = null, $updated_at = null): Record`

### 8.5 HubspotService
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

### 8.6 OdooService
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

### 8.7 GenericPlatformPort (Sin SDK)
- `resolveEndpoint(Event $event): string`
- `resolveMethod(Event $event): string`
- `resolveHeaders(Event $event, Platform $platform): array`
- `resolveQueryParams(Event $event, array $payload): array`
- `resolveBody(Event $event, array $payload): array`
- `resolveTimeout(Event $event): int`
- `resolveRetryPolicy(Event $event): array`

### 8.8 AuthStrategyResolver
- `resolveAuthMode(Platform $platform, Event $event): string`
- `buildBearerAuthHeaders(Platform $platform): array`
- `buildBasicAuthHeaders(Platform $platform): array`
- `getOAuth2AccessToken(Platform $platform): string`
- `buildOAuth2Headers(Platform $platform): array`
- Nota: OAuth2 Client Credentials debe usar cache de token y refresh por expiración.

## 8.9) Funcionalidad esperada por contrato (OBLIGATORIO)
- EventProcessingService: Procesa eventos desde webhooks, encuentra eventos configurados, ejecuta métodos en servicios apropiados, crea Records de trazabilidad.
- EventFlowService: Ejecuta flujos completos de eventos encadenados, transforma datos entre eventos, valida datos requeridos, maneja errores en cadena.
- EventLoggingService: Crea Records con estados apropiados, actualiza Records con éxito/error, mantiene jerarquía mediante `record_id`.
- BaseService: Proporciona funcionalidad común para servicios, carga eventos con relaciones, crea Records, ejecuta métodos dinámicamente.
- HubspotService: Implementa métodos específicos de HubSpot, procesa webhooks, sincroniza datos, delega llamadas API al adaptador.
- OdooService: Implementa métodos específicos de Odoo, procesa webhooks, sincroniza datos, delega llamadas API al adaptador.
- GenericPlatformPort: Resuelve endpoint/método/headers/query/body por evento para plataformas sin SDK.
- AuthStrategyResolver: Resuelve y aplica auth `bearer_api_key`, `basic_auth` y `oauth2_client_credentials`.

## 8.10) Casos de prueba mínimos por contrato (OBLIGATORIO)
- EventProcessingService: when subscriptionType válido -> procesa evento; when evento no encontrado -> retorna error controlado; when método no existe -> error controlado.
- EventFlowService: when flujo completo -> ejecuta todos los eventos; when evento falla -> maneja error apropiadamente; when datos inválidos -> valida y rechaza.
- EventLoggingService: when crear record -> retorna Record con estado init; when log success -> actualiza Record a success; when log error -> actualiza Record a error con detalles.
- HubspotService: when companyCreatedWebhook -> crea evento y retorna success; when getSignedQuotes -> encuentra cotizaciones y dispara jobs; when createProducts -> crea productos y actualiza caché.
- OdooService: when resPartnerCreateCompany -> crea empresa y dispara evento siguiente; when createSaleOrder -> crea orden y agrega nota; when syncCreateProducts -> obtiene productos y dispara evento.
- Generic endpoint auth: when `bearer_api_key` válida -> request autorizada; when inválida -> error controlado.
- Generic endpoint auth: when `basic_auth` válida -> request autorizada; when inválida -> error controlado.
- Generic endpoint auth: when `oauth2_client_credentials` válida -> token cacheado y request autorizada; when token expira -> refresh automático.
- Generic endpoint resiliencia: when 5xx/timeout -> retry con backoff según policy del Job.
- Generic endpoint seguridad: logs no contienen secretos ni headers sensibles en texto plano.
- Generic endpoint idempotencia: mismo `event_id + record_id + endpoint + method` no debe duplicar efectos cuando aplique política idempotente.

## 8.11) Contrato de respuesta normalizada para integraciones HTTP (OBLIGATORIO)
Todas las integraciones sin SDK deben devolver una estructura uniforme para desacoplar Jobs/Listeners/Servicios.

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
- `success` depende del mapeo de código HTTP + reglas de negocio.
- `retryable = true` para timeout/429/5xx o errores transitorios.
- `error.details` debe ir sanitizado (sin secretos ni headers sensibles).
- `request_id` debe propagarse en logs y records para trazabilidad extremo a extremo.

## 9) Sistema de Eventos, Listeners y Jobs (ARQUITECTURA MEJORADA)
### 9.1 Principios Fundamentales (OBLIGATORIO)
1. **Events**: Solo contienen datos. NO procesan nada. Son DTOs (Data Transfer Objects).
2. **Listeners**: SOLO disparan Jobs. NO hacen procesamiento pesado. Implementan ShouldQueue pero solo como mecanismo de desacoplamiento.
3. **Jobs**: Contienen TODO el procesamiento pesado. Hacen llamadas API, transforman datos, procesan múltiples registros.

### 9.2 Reglas de Separación de Responsabilidades (OBLIGATORIO)

#### Events (app/Events/*)
- **Responsabilidad**: Transportar datos entre componentes
- **NO debe**: Contener lógica de negocio, hacer llamadas API, procesar datos
- **Debe**: Ser serializable, contener solo propiedades públicas, tener constructor simple
- **Permitido**: Solo getters de metadatos estáticos (ej. `getEventType()`, `getEventDescription()`), sin condicionales de negocio ni transformación de datos
- **Ejemplo correcto**:
```php
class CreateCompanyEvent extends BaseEvent
{
    public function __construct(Event $eventSchedule, Record $parentRecord, array $data)
    {
        parent::__construct($eventSchedule, $parentRecord, $data);
    }
    
    public function getEventType(): string { return 'company.created'; }
    public function getEventDescription(): string { return 'Company creation event'; }
}
```

#### Listeners (app/Listeners/*)
- **Responsabilidad**: Recibir eventos y disparar Jobs apropiados
- **NO debe**: Llamar a `helpers()->forwarding()`, hacer llamadas API, procesar múltiples registros en loops, transformar datos complejos
- **Debe**: Crear Record inicial, disparar Job correspondiente, manejar errores básicos
- **Implementa**: ShouldQueue (para desacoplamiento, no para procesamiento)
- **Ejemplo correcto**:
```php
class CreateCompanyListener extends BaseListener
{
    public function handle(CreateCompanyEvent $event): void
    {
        $record = $this->createRecord(
            $event->getEventType(),
            $event->data,
            $event->getEventDescription()
        );
        
        // Disparar Job, NO procesar directamente
        ProcessCompanyCreationJob::dispatch(
            $event->eventSchedule,
            $record,
            $event->data
        );
    }
}
```

#### Jobs (app/Jobs/*)
- **Responsabilidad**: Procesar operaciones pesadas de forma asíncrona
- **Debe**: Hacer llamadas API, transformar datos, procesar múltiples registros, manejar errores y reintentos
- **NO debe**: Ser llamado directamente desde Services (excepto casos especiales)
- **Ejemplo correcto**:
```php
class ProcessCompanyCreationJob implements ShouldQueue
{
    public $queue = 'creation';
    public $tries = 3;
    public $backoff = 60;
    
    public function __construct(
        public Event $event,
        public Record $record,
        public array $data
    ) {}
    
    public function handle(): void
    {
        // Aquí va TODO el procesamiento pesado
        $serviceClass = app(EventProcessingService::class)
            ->getServiceClass($this->event->to_event->platform);
        $service = new $serviceClass($this->event->to_event->platform, $this->event, $this->record);
        $methodName = $this->event->to_event->getMethodName();
        $result = $service->$methodName($this->data);
        
        // Actualizar Record según resultado
        if ($result['success']) {
            $this->record->update(['status' => 'success', 'message' => $result['message']]);
        } else {
            $this->record->update(['status' => 'error', 'message' => $result['message']]);
            throw new \Exception($result['message']);
        }
    }
}
```

### 9.3 Flujo Mejorado de Eventos
```
Webhook 
  -> WebhookController 
  -> WebhookCustomProcessJob 
  -> EventProcessingService 
  -> Service Method 
  -> Event (solo datos)
  -> Listener (dispara Job)
  -> Job (procesa todo)
```

### 9.4 Refactorización Requerida de Listeners Actuales

#### Problema Actual
Los listeners están llamando directamente a `helpers()->forwarding()` que hace procesamiento pesado:
```php
// ❌ INCORRECTO - Listener haciendo procesamiento pesado
class CreateCompanyListener extends BaseListener
{
    public function handle(CreateCompanyEvent $event): void
    {
        $record = $this->createRecord(...);
        $this->handleWithLogging(function () use ($event) {
            helpers()->forwarding($event->eventSchedule, $event->parentRecord, $event->data);
        }, $record);
    }
}
```

#### Solución Correcta
```php
// ✅ CORRECTO - Listener solo dispara Job
class CreateCompanyListener extends BaseListener
{
    public function handle(CreateCompanyEvent $event): void
    {
        $record = $this->createRecord(
            $event->getEventType(),
            $event->data,
            $event->getEventDescription()
        );
        
        ProcessCompanyCreationJob::dispatch(
            $event->eventSchedule,
            $record,
            $event->data
        );
    }
}
```

### 9.5 Jobs Requeridos por Listener (OBLIGATORIO)

Cada listener debe tener un Job correspondiente:

- `CreateCompanyListener` -> `ProcessCompanyCreationJob`
- `UpdateCompanyListener` -> `ProcessCompanyUpdateJob`
- `CreateProductListener` -> `ProcessProductCreationJob`
- `UpdateProductListener` -> `ProcessProductUpdateJob`
- `CreateInvoiceListener` -> `ProcessInvoiceCreationJob`
- `CreateRecurringInvoiceListener` -> `ProcessRecurringInvoiceCreationJob`
- `CreateSaleOrderListener` -> `ProcessSaleOrderCreationJob`
- `SendingQuotesDataListener` -> `ProcessQuoteDataJob`
- `SendResponseListener` -> `ProcessResponseJob`
- `UpdateObjectListener` -> `ProcessObjectUpdateJob`
- `GetListPricesListener` -> `ProcessListPricesJob` (ya existe parcialmente como OdooGetListPricesJob)
- `GetStoreProductsListener` -> `ProcessStoreProductsJob`
- `NextEventListener` -> `ProcessNextEventJob` (OBLIGATORIO)

### 9.6 Eliminación de `helpers()->forwarding()` en Listeners

**PROHIBIDO**: Los listeners NO deben llamar a `helpers()->forwarding()` directamente.

**RAZÓN**: `helpers()->forwarding()` hace:
- Transformación de datos compleja
- Validación de datos
- Instanciación de servicios
- Ejecución de métodos dinámicos
- Manejo de resultados

Todo esto debe estar en Jobs, no en Listeners.

**MIGRACIÓN**: Mover la lógica de `helpers()->forwarding()` a los Jobs correspondientes.

### 9.7 Colas Configuradas
- `webhooks`: Para procesamiento de webhooks (alta prioridad)
- `events`: Para listeners que disparan Jobs (baja prioridad, solo desacoplamiento)
- `creation`: Para creación de entidades (empresas, productos, facturas, órdenes)
- `update`: Para actualización de entidades
- `sync`: Para sincronización de datos (productos, propiedades)
- `signed-quotes`: Para procesamiento de cotizaciones firmadas
- `validation`: Para validación de entidades
- `processing`: Para procesamiento general de datos pesados

### 9.8 Timeouts y Reintentos por Tipo de Job

- **Creación de entidades**: `timeout = 300s`, `tries = 3`, `backoff = 60s`
- **Actualización de entidades**: `timeout = 180s`, `tries = 3`, `backoff = 30s`
- **Sincronización masiva**: `timeout = 900s`, `tries = 2`, `backoff = 300s`
- **Procesamiento de cotizaciones**: `timeout = 900s`, `tries = 1`, `backoff = 300s`
- **Obtención de listas de precios**: `timeout = 300s`, `tries = 2`, `backoff = 60s`

### 9.9 Operación de colas en producción (OBLIGATORIO)
- Ejecutar workers con `Supervisor` o `Laravel Horizon` (si aplica), nunca en modo manual ad hoc.
- Definir concurrencia mínima por cola crítica:
  - `webhooks`: 2+ workers
  - `creation`/`update`: 2+ workers
  - `sync`/`processing`: 1+ worker
- Prioridad recomendada de colas:
  - `webhooks,creation,update,signed-quotes,validation,sync,processing,events`
- Implementar alertas operativas:
  - tamaño de cola,
  - antigüedad máxima de job pendiente,
  - tasa de error en `failed_jobs`.
- Política de recuperación:
  - reintento manual controlado de `failed_jobs`,
  - registro de causa raíz en `Record.details`,
  - prohibido reintento masivo sin filtro por tipo/error.

### 9.10 Ejemplo Completo de Refactorización

#### Antes (Incorrecto)
```php
// Listener haciendo procesamiento pesado
class CreateProductListener extends BaseListener
{
    public function handle(CreateProductEvent $event): void
    {
        $record = $this->createRecord(...);
        try {
            helpers()->forwarding($event->eventSchedule, $event->parentRecord, $event->data);
            $record->update(['status' => 'success']);
        } catch (\Exception $e) {
            $record->update(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
```

#### Después (Correcto)
```php
// Listener solo dispara Job
class CreateProductListener extends BaseListener
{
    public function handle(CreateProductEvent $event): void
    {
        $record = $this->createRecord(
            $event->getEventType(),
            $event->data,
            $event->getEventDescription()
        );
        
        ProcessProductCreationJob::dispatch(
            $event->eventSchedule,
            $record,
            $event->data
        );
    }
}

// Job hace todo el procesamiento
class ProcessProductCreationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $queue = 'creation';
    public $timeout = 300;
    public $tries = 3;
    public $backoff = 60;
    
    public function __construct(
        public Event $event,
        public Record $record,
        public array $data
    ) {}
    
    public function handle(): void
    {
        $eventLoggingService = app(EventLoggingService::class);
        $eventProcessingService = app(EventProcessingService::class);
        
        try {
            // Transformar datos
            $transformedData = $this->transformDataForNextEvent();
            
            // Validar datos
            $validationResult = $this->validateData();
            if (!$validationResult['valid']) {
                throw new \Exception('Validation failed: ' . $validationResult['message']);
            }
            
            // Procesar evento
            $serviceClass = $eventProcessingService->getServiceClass($this->event->to_event->platform);
            $service = new $serviceClass($this->event->to_event->platform, $this->event, $this->record);
            $methodName = $this->event->to_event->getMethodName();
            $result = $service->$methodName($transformedData);
            
            // Actualizar Record
            if ($result['success']) {
                $this->record->update([
                    'status' => 'success',
                    'message' => $result['message']
                ]);
            } else {
                throw new \Exception($result['message']);
            }
        } catch (\Exception $e) {
            $this->record->update([
                'status' => 'error',
                'message' => $e->getMessage(),
                'details' => $e->getTraceAsString()
            ]);
            throw $e; // Re-lanzar para que Laravel maneje el reintento
        }
    }
    
    private function transformDataForNextEvent(): array
    {
        // Lógica de transformación movida desde helpers()->forwarding()
        // ...
    }
    
    private function validateData(): array
    {
        // Lógica de validación movida desde helpers()->forwarding()
        // ...
    }
}
```

## 10) Configuración y ENV (OBLIGATORIO)
- Usar variables de entorno para todas las credenciales.
- Variables requeridas:
  - `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
  - `HUBSPOT_ACCESS_TOKEN` (para cada plataforma HubSpot)
  - `ODOO_URL`, `ODOO_DATABASE`, `ODOO_USERNAME`, `ODOO_PASSWORD` (para cada plataforma Odoo)
  - `NETSUITE_ACCOUNT`, `NETSUITE_CONSUMER_KEY`, `NETSUITE_CONSUMER_SECRET`, `NETSUITE_TOKEN_ID`, `NETSUITE_TOKEN_SECRET`, `NETSUITE_PRIVATE_KEY` (para cada plataforma NetSuite)
  - Para plataformas sin SDK (modo endpoint genérico):
    - `GENERIC_API_KEY` o secreto equivalente por plataforma
    - `GENERIC_BASIC_USER`, `GENERIC_BASIC_PASSWORD` (si aplica Basic Auth)
    - `GENERIC_OAUTH_TOKEN_URL`, `GENERIC_OAUTH_CLIENT_ID`, `GENERIC_OAUTH_CLIENT_SECRET`, `GENERIC_OAUTH_SCOPES` (si aplica OAuth2 Client Credentials)
  - `QUEUE_CONNECTION` (redis, database, sync)
  - `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` (si usa Redis)
- Variables opcionales:
  - `APP_ENV` (local, staging, production)
  - `APP_DEBUG` (true, false)
  - `LOG_LEVEL` (debug, info, warning, error)
  - `LOG_CHANNEL` (stack, single, daily)
- No subir `.env` al repo.
- Crear `.env.example` con todas las variables requeridas y opcionales, sin secretos reales.
- `.env` debe estar en `.gitignore`.
- Reglas de seguridad obligatorias:
  - Nunca almacenar secretos en `payload`, `message`, `details` ni logs.
  - Enmascarar headers sensibles: `authorization`, `x-api-key`, `proxy-authorization`, `cookie`, `set-cookie`.
  - Validar allowlist de dominios por plataforma para `endpoint_api`.
  - OAuth2 Client Credentials debe usar cache de token con expiración y refresh previo al vencimiento.

### 10.1 Plantilla obligatoria de configuración por evento (Sin SDK)
Esta plantilla se usa para que Codex implemente eventos configurables por endpoint sin inventar estructura.

```json
{
  "event_type_id": "generic.external.call",
  "platform_id": 999,
  "name": "genericEndpointCall",
  "type": "webhook",
  "active": true,
  "to_event_id": null,
  "endpoint_config": {
    "base_url": "https://api.external-platform.com",
    "path": "/v1/orders/sync",
    "method": "POST",
    "headers": {
      "x-tenant-id": "tenant_abc"
    },
    "query": {
      "source": "integrador"
    },
    "timeout_seconds": 30,
    "retry": {
      "enabled": true,
      "max_attempts": 3,
      "backoff_seconds": [5, 15, 30],
      "retry_on_status": [408, 429, 500, 502, 503, 504]
    },
    "idempotency": {
      "enabled": true,
      "key_template": "{event_id}:{record_id}:{method}:{path}"
    }
  },
  "auth": {
    "mode": "oauth2_client_credentials",
    "bearer_api_key_env": "GENERIC_API_KEY",
    "basic_user_env": "GENERIC_BASIC_USER",
    "basic_password_env": "GENERIC_BASIC_PASSWORD",
    "oauth2": {
      "token_url_env": "GENERIC_OAUTH_TOKEN_URL",
      "client_id_env": "GENERIC_OAUTH_CLIENT_ID",
      "client_secret_env": "GENERIC_OAUTH_CLIENT_SECRET",
      "scopes_env": "GENERIC_OAUTH_SCOPES"
    }
  },
  "payload_mapping": {
    "external_order_id": "deal.id",
    "customer_name": "company.name",
    "customer_email": "contact.email",
    "total_amount": "deal.amount"
  },
  "observability": {
    "log_request_body": false,
    "log_response_body": false,
    "mask_headers": [
      "authorization",
      "x-api-key",
      "proxy-authorization",
      "cookie",
      "set-cookie"
    ],
    "trace_fields": [
      "event_id",
      "record_id",
      "request_id",
      "endpoint",
      "method",
      "status_code",
      "latency_ms",
      "attempt"
    ]
  }
}
```

### 10.2 Reglas de validación de la plantilla
- `method` debe ser uno de: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`.
- `auth.mode` debe ser uno de: `bearer_api_key`, `basic_auth`, `oauth2_client_credentials`.
- `timeout_seconds` permitido: `1..120`.
- `retry.backoff_seconds` debe tener al menos 1 valor.
- `endpoint_config.base_url` debe cumplir allowlist del `platform`.
- Si `idempotency.enabled = true`, el `key_template` es obligatorio.

### 10.3 Persistencia de configuración genérica (OBLIGATORIO)
- Para evitar ambiguedad y permitir versionado/auditoria, la configuración HTTP de eventos sin SDK debe persistirse en tabla dedicada:
  - `event_http_configs`
- Estructura mínima sugerida:
  - `id`, `event_id` (unique), `method`, `base_url`, `path`, `headers_json`, `query_json`, `auth_mode`,
    `auth_config_json`, `timeout_seconds`, `retry_policy_json`, `idempotency_config_json`,
    `allowlist_domains_json`, `active`, `created_at`, `updated_at`.
- Regla de diseño:
  - `events` conserva metadatos de negocio.
  - `event_http_configs` conserva detalles técnicos de integración HTTP.
- No guardar secretos en texto plano en BD; solo referencias a variables/env keys.

## 11) Manejo de Webhooks
- Validar firma HMAC de webhooks usando `WebhookCustomSignatureValidator`.
- Validar timestamp si está presente; rechazar si está fuera de ventana aceptable (5 minutos).
- Procesar webhooks mediante `WebhookCustomProcessJob` (asíncrono).
- Crear Record inicial para trazabilidad.
- Manejar payloads simples y arrays de payloads.

## 11.1) Scheduler operativo para eventos programados (OBLIGATORIO)
- Los eventos `type = schedule` se ejecutan exclusivamente por scheduler + queue.
- Modo recomendado en servidor:
  - `php artisan schedule:work` (continuo) o cron con `php artisan schedule:run` cada minuto.
- Reglas obligatorias:
  - `withoutOverlapping` para evitar doble ejecución del mismo evento.
  - `onOneServer` en despliegues multi-nodo.
  - timezone por evento (si no se define, usar timezone de app).
  - ejecutar mediante `ExecuteEventJob` o Job dedicado, nunca lógica pesada en scheduler callback.
- Debe existir monitoreo de:
  - próxima ejecución (`next_run_at`),
  - última ejecución (`last_executed_at`),
  - estado de corrida y duración.

## 12) Sistema de Mapeo de Propiedades
- Las propiedades se mapean mediante `PropertyRelationship`.
- Cada evento tiene propiedades asociadas que se mapean a propiedades de la plataforma destino.
- El mapeo se realiza mediante helpers que transforman datos según el tipo.
- Los tipos soportados incluyen: string, integer, float, boolean, date, datetime, file.

## 13) Flujo oficial (OBLIGATORIO)
Webhook -> validar firma -> WebhookCustomProcessJob -> EventProcessingService -> encontrar eventos por subscriptionType -> ejecutar método en Service -> transformar datos -> disparar Event -> Listener (ShouldQueue) -> Job -> crear/actualizar Record -> si existe `to_event_id` (o condición de encadenamiento), disparar `ProcessNextEventJob` -> continuar cadena.

### 13.1 Flujo oficial para plataformas sin SDK (endpoint genérico)
Webhook/trigger -> `EventProcessingService` -> emisión de Event genérico (DTO) -> Listener genérico (`ShouldQueue`) crea `Record` inicial y despacha `EndpointExecutionJob` -> `EndpointExecutionJob` usa `GenericPlatformService` para resolver endpoint/método/auth/payload -> `GenericHttpAdapter` ejecuta HTTP -> `EndpointExecutionJob` recibe respuesta normalizada y actualiza `Record` -> si existe `to_event_id` (o condición de encadenamiento), el mismo Job despacha `ProcessNextEventJob`.

### 13.2 Idempotencia persistente (OBLIGATORIO)
- La idempotencia de llamadas salientes no debe ser solo en memoria.
- Implementar almacenamiento persistente de llaves de idempotencia:
  - Opción recomendada: tabla `event_idempotency_keys` (o Redis con persistencia y TTL controlado).
- Campos mínimos:
  - `idempotency_key` (unique), `event_id`, `record_id`, `endpoint`, `method`, `status`, `expires_at`, `created_at`.
- Política:
  - Si la llave existe en estado `success`, no re-ejecutar.
  - Si está `processing`, bloquear ejecución paralela.
  - Si expiró o está en `failed_retryable`, permitir reintento controlado.
- TTL sugerido:
  - webhooks críticos: 24h
  - schedules recurrentes: hasta siguiente ventana de ejecución.

## 14) Reglas a seguir (resumen)
- Respetar arquitectura hexagonal y convenciones de nombres.
- **CRÍTICO**: Los Listeners SOLO disparan Jobs. NO procesan datos pesados ni llaman a `helpers()->forwarding()`.
- Procesar eventos asíncronamente mediante Jobs cuando sea apropiado.
- Para plataformas sin SDK, toda llamada HTTP debe pasar por `EndpointExecutionJob` + `GenericHttpAdapter`.
- Soportar auth desde inicio: `bearer_api_key`, `basic_auth`, `oauth2_client_credentials`.
- Mantener trazabilidad mediante Records.
- No hardcodear secretos.
- Si falta información, detenerse y pedirla.
- Convertir eventos síncronos en Jobs cuando procesen datos pesados.
- Events son DTOs: solo datos y, como excepción permitida, getters de metadatos estáticos sin lógica de negocio.
- Jobs contienen TODO el procesamiento pesado.

## 15) Límites para Codex (anti-alucinación)
- No inventar endpoints, credenciales o modelos.
- No hardcodear secretos.
- Si falta información, detenerse y pedirla.
- No implementar funcionalidades fuera del alcance.
- Respetar convenciones de nombres y arquitectura.
- No procesar eventos síncronamente cuando puedan ser Jobs.

## 16) Entregables mínimos
- Proyecto compila y levanta Laravel.
- Webhooks funcionan y procesan eventos.
- Sistema de eventos/listeners/jobs funcional.
- Mapeo de propiedades entre plataformas funcional.
- Integración genérica por endpoint funcional para al menos 1 plataforma sin SDK.
- Soporte de auth `bearer_api_key`, `basic_auth` y `oauth2_client_credentials` funcional.
- Frontend administrativo funcional con Inertia.
- Sistema de trazabilidad mediante Records funcional.
- `.env` existe localmente y está ignorado por git.

## 17) Fases de ejecución
### Regla de avance entre fases
- Codex debe completar la fase actual, ejecutar tests cuando el entorno disponible lo permita, reportar resultados (parciales o completos) y esperar un OK explícito del usuario antes de iniciar la siguiente fase.
- Para validar una fase de forma completa: solicitar al usuario que ejecute `composer install`, `npm install` y `php artisan test`; esperar confirmación del usuario de que la ejecución se completó antes de continuar.
- Nota sobre `composer install` y `npm install` (OBLIGATORIO):
- Codex NO debe ejecutar `composer install` o `npm install` directamente (puede fallar por DNS/red o restricciones del sandbox).
- Codex debe SOLICITAR al usuario que ejecute estos comandos en su terminal local.
- Codex debe ESPERAR la confirmación explícita del usuario de que los comandos se completaron exitosamente para cerrar la validación completa de la fase.
- Si Codex ejecuta pruebas parciales en su entorno (por ejemplo, tests unitarios aislados), debe reportarlas explícitamente como validación parcial, no como cierre definitivo de fase.

### Framework de tests
- Usar PHPUnit para unit tests e integration tests.
- Usar Pest (opcional) si está configurado en el proyecto.

### 17.1 Definition of Done por fase (OBLIGATORIO)
- Una fase solo se cierra si cumple simultáneamente:
  - código implementado según alcance de la fase,
  - tests de la fase en verde (unitarios/integración según aplique),
  - evidencia de trazabilidad en `records`,
  - validación funcional manual mínima (flujo feliz + flujo de error principal),
  - reporte de cierre con riesgos pendientes explícitos.
- Criterio mínimo por fase:
  - `Fase 1A`: servicios core operativos + tests de servicios.
  - `Fase 1B`: integraciones SDK operativas con mocks confiables.
  - `Fase 1C`: endpoint genérico operativo con auth + retry + sanitización.
  - `Fase 2`: listeners sin lógica pesada (solo dispatch de Jobs) al 100%.
  - `Fase 3`: colas y jobs asíncronos estables con idempotencia persistente.
  - `Fase 4`: webhooks validados por firma + scheduler operativo estable.
  - `Fase 5`: frontend admin funcional con validaciones y estados de carga/error.
  - `Fase 6`: pruebas integrales de extremo a extremo y hardening final.

### Fase 0 - Preparación (común)
- Estructura base Laravel 11 + Inertia
- Configuración de base de datos y migraciones
- Definir modelos base y relaciones
- Definir convenciones de nombres
- Tests unitarios básicos de modelos
- Implementación completa de EventLoggingService
- Crear `.env` local y agregar `.env` al `.gitignore`.
- Crear `.env.example` con todas las variables definidas en la sección 10.
- Verificar localmente que `.env` exista en el entorno del desarrollador.

Checklist mínimo Fase 0:
- `app/Models/Event.php`
- `app/Models/Record.php`
- `app/Models/Platform.php`
- `app/Models/Property.php`
- `app/Models/PropertyRelationship.php`
- `app/Enums/EventType.php`
- `app/Services/EventLoggingService.php`
- `app/Services/Base/BaseService.php`
- `app/Events/BaseEvent.php`
- `app/Listeners/BaseListener.php`
- `database/migrations/` (todas las migraciones necesarias)
- `.env.example`
- `.gitignore` (con `.env`)

Notas Fase 0:
- La fase NO se considera completa si no existen todos los archivos del checklist mínimo.
- Los archivos pueden ser placeholders (stubs) sin lógica completa, pero deben compilar y permitir testeo básico.
- La implementación funcional comienza en las Fases siguientes.

### Fase 1A - Core y Servicios (Equipo A)
- Domain: modelos completos con relaciones y métodos de negocio
- Application: EventProcessingService, EventFlowService, EventTriggerService
- BaseService con funcionalidad base
- Tests unitarios por servicio (happy path + errores clave)
- Tests de integración end-to-end: crear test que valide el flujo completo de procesamiento de eventos con mocks de servicios externos

### Fase 1B - Integraciones (Equipo B)
- HubspotService con métodos principales
- OdooService con métodos principales
- NetSuiteService con métodos principales
- Adaptadores de API (HubspotApiServiceRefactored, OdooApiService, NetSuiteApiService)
- Tests unitarios por servicio con mocks de APIs
- Completar test de integración end-to-end: validar que todos los servicios funcionan correctamente

### Fase 1C - Integraciones genéricas por endpoint (Sin SDK)
- Implementar `GenericPlatformService` y `GenericHttpAdapter`.
- Implementar `EndpointExecutionJob` como ejecutor único de requests HTTP externos.
- Implementar `AuthStrategyResolver` con:
  - `bearer_api_key`
  - `basic_auth`
  - `oauth2_client_credentials` (token cacheado y refresh por expiración)
- Extender configuración de `Event` para:
  - `endpoint_api` absoluto/relativo
  - método HTTP
  - headers opcionales
  - query params opcionales
  - timeout/retry policy
- Persistir configuración técnica en `event_http_configs` (tabla dedicada).
- Prohibir ejecución síncrona para integraciones sin SDK.
- Tests unitarios y de integración (mockeados) para auth, retries y sanitización de logs.
- Validar un caso real configurable por evento y ejecutado completamente por cola.

### Fase 2 - Eventos y Listeners (REFACTORIZACIÓN CRÍTICA)
- Implementar todos los eventos definidos (solo datos, sin lógica)
- Refactorizar TODOS los listeners para que SOLO disparen Jobs
- Crear Jobs correspondientes para cada listener:
  - `ProcessCompanyCreationJob`, `ProcessCompanyUpdateJob`
  - `ProcessProductCreationJob`, `ProcessProductUpdateJob`
  - `ProcessInvoiceCreationJob`, `ProcessRecurringInvoiceCreationJob`
  - `ProcessSaleOrderCreationJob`
  - `ProcessQuoteDataJob`
  - `ProcessResponseJob`
  - `ProcessObjectUpdateJob`
  - `ProcessListPricesJob` (refactorizar OdooGetListPricesJob)
  - `ProcessStoreProductsJob`
  - `ProcessNextEventJob` (OBLIGATORIO cuando exista `to_event_id` o condición de encadenamiento)
- Mover lógica de `helpers()->forwarding()` a los Jobs correspondientes
- Eliminar llamadas a `helpers()->forwarding()` de todos los listeners
- Configurar EventServiceProvider
- Tests unitarios de eventos (validar que solo contienen datos)
- Tests unitarios de listeners (validar que solo disparan Jobs)
- Tests unitarios de Jobs (validar procesamiento completo)
- Tests de integración de flujos de eventos completos

### Fase 3 - Jobs y Procesamiento Asíncrono
- Implementar todos los Jobs definidos
- Configurar colas (webhooks, events, signed-quotes, validation, creation, sync)
- Implementar procesamiento de cotizaciones firmadas
- Implementar sincronización de productos
- Implementar idempotencia persistente (`event_idempotency_keys` o Redis persistente)
- Tests unitarios de Jobs
- Tests de integración de procesamiento asíncrono

### Fase 4 - Webhooks y Drivers
- WebhookController con validación de firmas
- WebhookCustomProcessJob
- WebhookCustomSignatureValidator
- WebhookCustomProfile
- WebhookCustomResponse
- Configurar scheduler operativo para eventos programados (`schedule:work`/`schedule:run`) con `withoutOverlapping` y `onOneServer`.
- Tests unitarios del driver (validación payload + routing)
- Tests de integración de webhooks

### Fase 5 - Frontend Administrativo
- Componentes Vue para gestión de eventos
- Componentes Vue para gestión de plataformas
- Componentes Vue para gestión de propiedades
- Componentes Vue para visualización de Records
- Dashboard con estadísticas
- Tests E2E mínimos del frontend (OBLIGATORIO para cierre de Fase 5)

#### Criterios de aceptación frontend (OBLIGATORIO)
- Pantalla de evento debe permitir configurar:
  - tipo `webhook/schedule`
  - config endpoint genérico (`base_url`, `path`, `method`, headers/query, timeout/retry)
  - auth mode (`bearer_api_key`, `basic_auth`, `oauth2_client_credentials`)
  - plantilla de idempotencia.
- Validaciones UI obligatorias:
  - método HTTP válido,
  - auth mode válido,
  - timeout en rango,
  - `base_url` permitido por allowlist.
- Vista de trazabilidad debe mostrar:
  - `event_id`, `record_id`, `request_id`, `status_code`, `latency_ms`, `attempt`.
- UI no debe mostrar secretos sin enmascarar.
- Formulario de plataformas (`/admin/platforms/create` y `/admin/platforms/{id}/edit`) debe usar estructura por bloques:
  - `Basic Information`
  - bloque de configuración específica según `platform.type` (`HubSpot Configuration`, `Odoo Configuration`, `NetSuite Configuration`, `Generic Configuration`)
  - `Security & API Settings`
- En `Generic Configuration`, al cambiar `Auth Mode`, la UI debe mostrar solo los campos del modo seleccionado:
  - `bearer_api_key` -> `api_key`
  - `basic_auth` -> `username`, `password`
  - `oauth2_client_credentials` -> `client_id`, `client_secret`, `token_url`
- El formulario de plataformas NO debe exponer `credentials`/`settings` como JSON crudo en textarea para operación normal.
  Debe mapear campos específicos a:
  - `credentials` (secretos y claves),
  - `settings` (URLs, timeouts y configuración no secreta).
- Debe existir acción `Test connection` visible en cabecera del formulario de plataformas (habilitada en modo edición).

#### RBAC mínimo para frontend/API (OBLIGATORIO)
- `admin`:
  - CRUD completo de eventos/plataformas/propiedades.
  - puede ver trazabilidad completa y reintentar jobs fallidos.
- `operator`:
  - puede ejecutar y monitorear eventos.
  - no puede modificar configuración de auth sensible.
- `viewer`:
  - solo lectura de dashboard, eventos y records.
  - no puede ejecutar, editar ni reintentar jobs.
- Regla de seguridad:
  - endpoints de escritura (`POST/PUT/PATCH/DELETE`) requieren permiso explícito.
  - datos sensibles deben devolverse enmascarados para todos los roles.

### Fase 6 - Integración total + QA
- Integrar todos los componentes
- Refinar logging y trazabilidad
- Errores y pruebas básicas
- Tests de integración completos
- Optimización de rendimiento
- Documentación de API
- Verificación final de integraciones sin SDK en pipeline asíncrono.

### Nota sobre pruebas manuales y scripts de desarrollo
- Se recomienda crear scripts manuales (opcionales) en `scripts/` para probar flujos completos durante desarrollo
- Estos scripts permiten ejecutar servicios directamente con datos simulados sin necesidad de webhooks
- Ejemplo: `scripts/test-event-flow.php` que instancia servicios y ejecuta flujos completos mostrando logs detallados
- Estos scripts son herramientas de desarrollo y no son obligatorios para completar las fases, pero facilitan el debugging y validación de flujos

## 18) Base de Datos
### 18.1 Tablas Principales
- `events`: Eventos configurados que conectan plataformas
- `records`: Registros de ejecución de eventos con jerarquía
- `platforms`: Plataformas integradas (HubSpot, Odoo, NetSuite)
- `properties`: Propiedades mapeadas entre plataformas
- `property_relationships`: Relaciones entre propiedades
- `property_event`: Tabla pivot entre propiedades y eventos
- `event_triggers`: Triggers de eventos
- `event_trigger_groups`: Grupos de triggers
- `event_trigger_group_conditions`: Condiciones de grupos de triggers
- `webhook_calls`: Llamadas de webhooks recibidas
- `jobs`: Jobs en cola
- `failed_jobs`: Jobs fallidos
- `users`: Usuarios del sistema
- `roles`: Roles de usuarios
- `permissions`: Permisos
- `categories`: Categorías de propiedades
- `configs`: Configuración del sistema

### 18.1.1 Jerarquia de roles y bootstrap (OBLIGATORIO)
- Jerarquia canonica:
  - `superadmin`: bypass total de permisos.
  - `admin`: acceso alto pero sujeto a permisos asignados.
  - `operator` u otros: acceso segun permisos asignados.
- Seeder obligatorio: `database/seeders/SuperAdminSeeder.php`.
- Datos obligatorios de bootstrap:
  - `username`: `charly91rubio`
  - `first_name`: `Carlos`
  - `last_name`: `Rubio`
  - `email`: `carlos91rubio@gmail.com`
  - `password`: `ch_rubio2026` (guardar hasheada).
- El seeder debe asociar el usuario al rol `superadmin`.
- `DatabaseSeeder` debe invocar `RolesAndPermissionsSeeder` y luego `SuperAdminSeeder`.

### 18.2 Relaciones Clave
- Event `belongsTo` Platform, Event (to_event)
- Event `hasMany` Event (from_events), Record, PropertyRelationship
- Event `belongsToMany` Property
- Record `belongsTo` Record (record_id para jerarquía)
- Record `hasMany` Record (childrens)
- Property `belongsTo` Platform
- Property `belongsToMany` Event, Category
- PropertyRelationship `belongsTo` Event, Property (property_id, related_property_id)

### 18.3 Plan de mejora de BD para Codex (OBLIGATORIO, NO ejecutar fuera de fases)
Este plan define cambios de base de datos para que Codex los implemente por fases. No se aplican de inmediato; solo cuando la fase correspondiente sea aprobada por el usuario.

#### 18.3.1 Hallazgos críticos actuales
- `records` no tiene `event_id`, pero el modelo `Event` asume relación directa con registros.
- `property_event` puede permitir duplicados lógicos si no existe `unique(event_id, property_id)`.
- Faltan índices para consultas calientes de webhook/eventos/records.
- Algunas FKs en migraciones históricas requieren ajuste de `onDelete` y rollback seguro.

#### 18.3.2 Estructura objetivo (trazabilidad + integridad)
- Mantener `records.event_type` por compatibilidad histórica.
- Agregar `records.event_id` (nullable) como referencia fuerte al evento.
- Usar `record_id` solo para jerarquía padre/hijo de ejecución.
- Garantizar unicidad de mapeos en pivotes y relaciones de propiedades.
- Priorizar integridad referencial con `cascade/nullOnDelete` según contexto.

#### 18.3.3 Fases de implementación BD

**Fase BD-1 (Integridad, sin romper compatibilidad)**
- Agregar `event_id` nullable en `records` con FK a `events`.
- Agregar `unique(['event_id', 'property_id'])` en `property_event`.
- Asegurar FK de `property_event.property_id` con `onDelete('cascade')`.
- Asegurar FK de `property_relationships.event_id` con `onDelete('cascade')`.
- Corregir rollback de migraciones con FK de `record_id` (drop FK antes de drop column).

**Fase BD-2 (Rendimiento e índices)**
- `events(platform_id)`
- `events(to_event_id)`
- `events(created_at)`
- `events(type, active)`
- `events(event_type_id, platform_id)`
- `records(record_id)`
- `records(record_id, created_at)`
- `records(event_type)`
- `records(created_at)`
- Opcional según uso: `webhook_calls(name)`, `webhook_calls(created_at)`

**Fase BD-3 (Alineación con arquitectura Jobs)**
- Al crear `Record` desde servicios/jobs/listeners, persistir `event_id` cuando exista contexto.
- Migrar reportes/estadísticas a consultas SQL agregadas (evitar agrupar grandes colecciones en memoria).
- Mantener compatibilidad con registros antiguos sin `event_id`.

#### 18.3.4 Migraciones sugeridas (nombres guía)
- `add_event_id_to_records_table`
- `add_unique_constraint_to_property_event_table`
- `fix_property_event_property_id_foreign_key`
- `fix_property_relationships_event_id_foreign_key`
- `fix_record_id_migration_down_rollback`
- `add_indexes_to_events_table_for_processing`
- `add_indexes_to_records_table_for_tracing`

#### 18.3.5 Criterios de aceptación para BD
- No se pierden datos ni se rompe compatibilidad con records históricos.
- Se elimina posibilidad de duplicados lógicos en `property_event`.
- Dashboard y consultas de seguimiento mejoran por índices.
- Trazabilidad permite navegar `record -> event` cuando haya contexto.
- Rollback de migraciones críticas funciona sin errores de FK.

#### 18.3.6 Reglas de seguridad de migración
- Crear migraciones nuevas, no reescribir migraciones históricas ya aplicadas en productivo.
- Antes de constraints `unique`, ejecutar precheck de duplicados.
- Aplicar índices en ventanas de bajo tráfico.
- Cada fase BD requiere backup lógico y validación post-migración.

## 19) Sistema de Trazabilidad
### 19.1 Records
- Cada operación crea un Record con estado inicial 'init'
- Los Records se actualizan con el catálogo canónico de escritura: `init`, `processing`, `success`, `error`, `warning`
- Los Records mantienen jerarquía mediante `record_id` (parent)
- Los Records almacenan payload completo en JSON
- Los Records almacenan mensajes descriptivos y detalles de errores

### 19.2 Estados de Records
- `init`: Inicializado, esperando procesamiento
- `processing`: En proceso
- `success`: Completado exitosamente
- `error`: Error en procesamiento
- `warning`: Advertencia (procesamiento completado con advertencias)

Regla de compatibilidad:
- Estados legacy (`process`, `fail`, `info`, `successwitherrors`) solo se admiten para lectura histórica.
- Toda nueva escritura (Jobs/Services/Listeners) debe usar exclusivamente el catálogo canónico (`init`, `processing`, `success`, `error`, `warning`).

### 19.3 Retención y purga de datos operativos (OBLIGATORIO)
- Definir política de retención por tabla:
  - `records`: retener mínimo 90 días (o según compliance del negocio).
  - `webhook_calls`: retener 30-90 días según volumen.
  - `jobs` completados: limpieza periódica.
  - `failed_jobs`: retener hasta análisis + resolución, luego archivar/purgar.
- Implementar comando programado (scheduler) para housekeeping:
  - purge por antigüedad,
  - archivado opcional de errores críticos.
- Toda purga debe:
  - generar log estructurado,
  - registrar conteo de filas afectadas,
  - evitar borrar registros en estado de investigación activa.

## 20) Migración de Listeners a Jobs (GUÍA PASO A PASO)

Nota de implementación:
- Los snippets de esta sección son guía conceptual/pseudocódigo.
- No copiar literalmente líneas marcadas como `Placeholder`; reemplazarlas por implementación real antes de cerrar la fase.

### 20.1 Proceso de Migración

Para cada listener que necesita refactorización:

1. **Identificar el Job necesario**
   - Nombre: `Process{Entity}{Action}Job` (ej: `ProcessCompanyCreationJob`)
   - Cola: `creation`, `update`, `sync`, según corresponda
   - Timeout: Según complejidad (300s para creación, 180s para actualización)

2. **Crear el Job**
   ```php
   class ProcessCompanyCreationJob implements ShouldQueue
   {
       use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
       
       public $queue = 'creation';
       public $timeout = 300;
       public $tries = 3;
       public $backoff = 60;
       
       public function __construct(
           public Event $event,
           public Record $record,
           public array $data
       ) {}
       
       public function handle(): void
       {
           // Mover aquí toda la lógica de helpers()->forwarding()
           // ...
       }
   }
   ```

3. **Refactorizar el Listener**
   ```php
   class CreateCompanyListener extends BaseListener
   {
       public function handle(CreateCompanyEvent $event): void
       {
           $record = $this->createRecord(...);
           
           // Solo disparar Job, nada más
           ProcessCompanyCreationJob::dispatch(
               $event->eventSchedule,
               $record,
               $event->data
           );
       }
   }
   ```

4. **Mover lógica de `helpers()->forwarding()` al Job**
   - Transformación de datos
   - Validación de datos
   - Instanciación de servicios
   - Ejecución de métodos
   - Manejo de resultados

5. **Actualizar Records en el Job**
   - El Job debe actualizar el Record con estados apropiados
   - Manejar errores y actualizar Record con detalles

### 20.2 Ejemplo Completo: CreateCompanyListener

#### Estado Actual (Incorrecto)
```php
class CreateCompanyListener extends BaseListener
{
    public function handle(CreateCompanyEvent $event): void
    {
        $record = $this->createRecord(...);
        $this->handleWithLogging(function () use ($event) {
            helpers()->forwarding($event->eventSchedule, $event->parentRecord, $event->data);
        }, $record);
    }
}
```

#### Estado Deseado (Correcto)
```php
// Listener
class CreateCompanyListener extends BaseListener
{
    public function handle(CreateCompanyEvent $event): void
    {
        $record = $this->createRecord(
            $event->getEventType(),
            $event->data,
            $event->getEventDescription()
        );
        
        ProcessCompanyCreationJob::dispatch(
            $event->eventSchedule,
            $record,
            $event->data
        );
    }
}

// Job
class ProcessCompanyCreationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $queue = 'creation';
    public $timeout = 300;
    public $tries = 3;
    public $backoff = 60;
    
    public function __construct(
        public Event $event,
        public Record $record,
        public array $data
    ) {}
    
    public function handle(): void
    {
        $eventLoggingService = app(EventLoggingService::class);
        $eventProcessingService = app(EventProcessingService::class);
        
        try {
            // Validar que to_event existe
            if (!$this->event->to_event) {
                throw new \Exception('Event does not have a target event (to_event) configured');
            }
            
            $methodName = $this->event->to_event->getMethodName();
            if (!$methodName) {
                throw new \Exception('Target event does not have a valid method name');
            }
            
            // Transformar datos (lógica movida desde helpers()->forwarding())
            $transformedData = $this->transformDataForNextEvent();
            
            // Validar datos (lógica movida desde helpers()->forwarding())
            $validationResult = $this->validateDataForNextEvent($transformedData);
            if (!$validationResult['valid']) {
                throw new \Exception('Data validation failed: ' . $validationResult['message']);
            }
            
            // Procesar evento
            $serviceClass = $eventProcessingService->getServiceClass($this->event->to_event->platform);
            $service = new $serviceClass($this->event->to_event->platform, $this->event, $this->record);
            $result = $service->$methodName($transformedData);
            
            // Actualizar Record
            if ($result['success']) {
                $this->record->update([
                    'status' => 'success',
                    'message' => $result['message'] ?? 'Company processed successfully'
                ]);
            } else {
                throw new \Exception($result['message'] ?? 'Company processing failed');
            }
        } catch (\Exception $e) {
            $this->record->update([
                'status' => 'error',
                'message' => $e->getMessage(),
                'details' => $e->getTraceAsString()
            ]);
            throw $e; // Re-lanzar para reintentos
        }
    }
    
    private function transformDataForNextEvent(): array
    {
        // Implementar transformación de datos
        // Mover lógica desde helpers()->transformDataForNextEvent()
        return $this->data; // Placeholder
    }
    
    private function validateDataForNextEvent(array $data): array
    {
        // Implementar validación
        // Mover lógica desde helpers()->validateDataForNextEvent()
        return ['valid' => true, 'message' => 'Validation passed']; // Placeholder
    }
}
```

## 20.3) Consideraciones Especiales
### 20.3.1 Procesamiento de Cotizaciones Firmadas
- Las cotizaciones firmadas se procesan mediante `getSignedQuotes()`
- Se disparan Jobs `HubSpotQuoteProcessingJob` y `HubSpotCompanyPropertiesSyncJob`
- El procesamiento valida existencia de entidades antes de crear
- Se actualizan propiedades en HubSpot con IDs de plataforma destino

### 20.3.2 Sincronización de Productos
- Los productos se sincronizan mediante `syncCreateProducts()` y `syncUpdateProducts()`
- Se usa caché para optimizar búsquedas
- Se validan productos por propiedades únicas (hs_sku, odoo_id, netsuite_id)
- Se procesan en lotes para optimizar rendimiento

### 20.3.3 Mapeo de Propiedades
- Las propiedades se mapean mediante `PropertyRelationship`
- Se transforman datos según tipo (string, integer, float, boolean, date, datetime, file)
- Se validan propiedades requeridas antes de procesar
- Se manejan propiedades anidadas (ej: `deal.amount`, `company.name`)

### 20.3.4 Flujos de Eventos Encadenados
- Los eventos se pueden encadenar mediante `to_event_id`
- El `EventFlowService` ejecuta flujos completos desde raíz hasta hojas
- Los datos se transforman entre eventos mediante `PropertyRelationship`
- Los errores en un evento no detienen el flujo completo (configurable)

## 21) Mejoras de Arquitectura Implementadas
### 21.1 Refactorización de Listeners a Jobs (CRÍTICO)
- **Problema identificado**: Los listeners están haciendo procesamiento pesado llamando a `helpers()->forwarding()`
- **Solución**: Los listeners SOLO deben disparar Jobs. Todo el procesamiento debe estar en Jobs.
- **Impacto**: Mejora significativa en rendimiento, escalabilidad y mantenibilidad
- **Estado**: REQUIERE REFACTORIZACIÓN COMPLETA en Fase 2

### 21.2 Eliminación de `helpers()->forwarding()` en Listeners
- **Problema**: `helpers()->forwarding()` contiene lógica de procesamiento que debe estar en Jobs
- **Solución**: Mover toda la lógica a Jobs dedicados
- **Beneficios**: 
  - Mejor control de timeouts y reintentos
  - Mejor trazabilidad por Job
  - Mejor manejo de errores
  - Escalabilidad mejorada

### 21.3 Conversión de Eventos a Jobs
- Identificar eventos que procesan datos pesados y convertirlos en Jobs
- Ejemplos ya implementados:
  - `getSignedQuotes()` -> Dispara `HubSpotQuoteProcessingJob` ✅
  - `HubSpotCompanyPropertiesSyncJob` ya existe ✅
- Ejemplos que requieren refactorización:
  - `syncCreateProducts()` -> Debe disparar Job en lugar de procesar directamente
  - `createProducts()` -> Debe procesar en lotes mediante Jobs
  - Todos los listeners que llaman `helpers()->forwarding()` -> Deben disparar Jobs

### 21.4 Separación de Responsabilidades
- Los Services deben contener solo lógica de negocio
- Los Adaptadores (ApiService) deben contener solo lógica de API
- Los Jobs deben procesar operaciones asíncronas
- Los Listeners deben disparar Jobs cuando sea apropiado

### 21.5 Trazabilidad Mejorada
- Implementar logging estructurado con contexto
- Agregar métricas de rendimiento a Records
- Implementar dashboard de monitoreo de Records
- Agregar alertas para Records con errores críticos

### 21.6 Validación y Manejo de Errores
- Implementar validación centralizada de payloads en Jobs
- Implementar manejo de errores consistente en Jobs
- Agregar retry logic para operaciones fallidas (configurado por Job)
- Implementar circuit breaker para APIs externas (futuro)

### 21.7 Checklist de Refactorización (OBLIGATORIO)
Para cada listener existente, verificar:
- [ ] ¿El listener llama a `helpers()->forwarding()`? → Crear Job correspondiente
- [ ] ¿El listener procesa múltiples registros en un loop? → Mover loop a Job
- [ ] ¿El listener hace llamadas API? → Mover llamadas a Job
- [ ] ¿El listener transforma datos complejos? → Mover transformación a Job
- [ ] ¿El listener solo crea Record y dispara Job? → ✅ Correcto

**Listeners que REQUIEREN refactorización inmediata:**
- [ ] `CreateCompanyListener` → Crear `ProcessCompanyCreationJob`
- [ ] `UpdateCompanyListener` → Crear `ProcessCompanyUpdateJob`
- [ ] `CreateProductListener` → Crear `ProcessProductCreationJob`
- [ ] `UpdateProductListener` → Crear `ProcessProductUpdateJob`
- [ ] `CreateInvoiceListener` → Crear `ProcessInvoiceCreationJob`
- [ ] `CreateRecurringInvoiceListener` → Crear `ProcessRecurringInvoiceCreationJob`
- [ ] `CreateSaleOrderListener` → Crear `ProcessSaleOrderCreationJob`
- [ ] `SendingQuotesDataListener` → Crear `ProcessQuoteDataJob`
- [ ] `SendResponseListener` → Crear `ProcessResponseJob`
- [ ] `UpdateObjectListener` → Crear `ProcessObjectUpdateJob`
- [ ] `GetListPricesListener` → Refactorizar a `ProcessListPricesJob` (mover lógica de loop)
- [ ] `GetStoreProductsListener` → Crear `ProcessStoreProductsJob`
- [ ] `NextEventListener` → Crear `ProcessNextEventJob` (OBLIGATORIO)

## 22) Resumen Ejecutivo de Mejoras Arquitectónicas

### 22.1 Problema Identificado
El proyecto actual tiene listeners que están haciendo procesamiento pesado llamando directamente a `helpers()->forwarding()`, lo cual:
- Viola el principio de separación de responsabilidades
- Dificulta el control de timeouts y reintentos
- Reduce la escalabilidad del sistema
- Hace difícil la trazabilidad y debugging

### 22.2 Solución Propuesta
Refactorización completa de la arquitectura Events/Listeners/Jobs:

1. **Events**: Solo contienen datos (DTOs). Sin lógica.
2. **Listeners**: Solo disparan Jobs. Sin procesamiento.
3. **Jobs**: Contienen TODO el procesamiento pesado.

### 22.3 Beneficios Esperados
- ✅ Mejor control de timeouts y reintentos por tipo de operación
- ✅ Mejor escalabilidad mediante colas especializadas
- ✅ Mejor trazabilidad (cada Job actualiza su Record)
- ✅ Mejor manejo de errores (reintentos configurables por Job)
- ✅ Mejor mantenibilidad (lógica centralizada en Jobs)
- ✅ Mejor testing (Jobs más fáciles de testear que listeners con lógica)

### 22.4 Impacto en el Código
- **Listeners afectados**: ~13 listeners requieren refactorización
- **Jobs nuevos requeridos**: ~13 Jobs nuevos
- **Lógica a migrar**: `helpers()->forwarding()` y métodos relacionados
- **Fase crítica**: Fase 2 (Eventos y Listeners)

### 22.5 Prioridad de Implementación
**ALTA**: Esta refactorización es crítica para la escalabilidad y mantenibilidad del proyecto. Debe completarse en Fase 2 antes de continuar con otras funcionalidades.

## 23) Operación, Calidad y Continuidad (OBLIGATORIO)

### 23.1 CI/CD por fase (gates de calidad)
- Cada fase debe tener pipeline mínimo con estado `required` antes de merge:
  - `lint` (PHP + frontend),
  - `unit tests`,
  - `integration tests` (cuando aplique),
  - `build frontend` (Fase 5+),
  - `smoke test` básico post-build.
- Regla de aprobación:
  - no se puede cerrar fase si falla un gate requerido.
- Evidencia requerida por fase:
  - resultado de pipeline,
  - resumen de cobertura mínima definida para la fase,
  - reporte corto de riesgos abiertos.

### 23.2 SLO/SLA operativos mínimos
- Definir y monitorear objetivos mínimos:
  - latencia `p95` y `p99` por tipo de Job,
  - tasa de error por cola,
  - backlog máximo permitido por cola,
  - tiempo máximo de permanencia en `failed_jobs`.
- Umbrales iniciales recomendados:
  - error rate < 2% por ventana de 15 min en colas críticas,
  - backlog crítico (`webhooks`) < 5 min de antigüedad.
- Si se supera umbral:
  - activar runbook de incidentes,
  - pausar despliegues no urgentes,
  - ejecutar plan de mitigación.

### 23.3 Runbook de incidentes (operación obligatoria)
- Definir severidades:
  - `SEV1`: caída de procesamiento crítico o pérdida de integridad.
  - `SEV2`: degradación severa sin caída total.
  - `SEV3`: degradación menor o error acotado.
- Flujo mínimo:
  - detección -> triage -> mitigación -> comunicación -> resolución -> postmortem.
- Postmortem obligatorio para `SEV1/SEV2`:
  - causa raíz,
  - impacto,
  - acciones preventivas con owner y fecha compromiso.

### 23.4 Continuidad del negocio (RTO/RPO + restore test)
- Definir objetivos por entorno:
  - `RTO` (tiempo objetivo de recuperación),
  - `RPO` (pérdida máxima aceptable de datos).
- Reglas:
  - no basta con backup; se debe probar restore periódicamente.
  - documentar resultado de restore test y tiempos reales.
- Mínimo recomendado:
  - restore test trimestral en entorno controlado.

### 23.5 Gobernanza de datos sensibles (PII/secretos)
- Clasificar campos sensibles en payloads y records.
- Reglas:
  - nunca persistir secretos crudos en `payload`, `message` o `details`,
  - enmascarar identificadores sensibles en UI/logs,
  - anonimizar datos en pruebas no productivas.
- Definir lista de campos prohibidos en logs (allowlist/denylist explícita).

### 23.6 Versionado de API interna y compatibilidad
- Establecer prefijo versionado para API administrativa (`/api/v1`).
- Regla de compatibilidad:
  - cambios breaking requieren nueva versión (`v2`) y ventana de transición.
- Documentar contratos de request/response por versión.

### 23.7 Checklist Go-Live por fase (sí/no)
- [ ] Gates CI/CD requeridos en verde.
- [ ] Observabilidad activa con umbrales definidos.
- [ ] Runbook de incidentes publicado y probado.
- [ ] Backup + restore test ejecutado y documentado.
- [ ] Política de datos sensibles aplicada y verificada.
- [ ] Versionado API definido y documentado.

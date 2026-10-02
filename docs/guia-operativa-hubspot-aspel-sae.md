# Guía operativa de integración HubSpot - ASPEL SAE

## 1. Objetivo

Este documento explica cómo fluye la información entre HubSpot y ASPEL SAE, cuáles son los detonantes de cada proceso y qué debe validar la persona usuaria en cada etapa.

El flujo operativo comprende:

1. Alta y actualización de contactos.
2. Sincronización automática de productos.
3. Preparación del negocio y asignación del contacto principal.
4. Configuración de los elementos de pedido.
5. Consulta de almacén, existencias y precio.
6. Creación y envío de la cotización a ASPEL SAE.
7. Confirmación del resultado y atención de errores.

La integración trabaja en segundo plano. Algunos cambios pueden tardar unos minutos en reflejarse en HubSpot.

## 2. Vista general del flujo

```text
Contacto HubSpot
    |
    | Alta por etapa del ciclo de vida o actualización manual
    v
Contacto ASPEL SAE
    |
    | Clave de cliente devuelta por SAE
    v
Negocio HubSpot + contacto principal
    |
    | Productos sincronizados desde SAE
    v
Elementos de pedido
    |
    | Selección de almacén
    v
Consulta de existencias, lista y precio en SAE
    |
    v
Cotización publicada en HubSpot
    |
    | Cambio del negocio a una etapa autorizada
    v
Cotización creada en ASPEL SAE
```

## 3. Contactos

### 3.1 Alta inicial de un contacto en ASPEL SAE

El alta se detona cuando la propiedad **Etapa del ciclo de vida** (`lifecyclestage`) del contacto cambia a **Oportunidad** (`opportunity`).

Antes de realizar este cambio, deben estar completos los datos obligatorios del contacto para ASPEL SAE. Entre ellos se encuentran los datos fiscales, de identificación, dirección y envío definidos para la operación.

La integración realiza lo siguiente:

1. Recibe el cambio de etapa del contacto.
2. Envía los datos configurados a ASPEL SAE.
3. ASPEL SAE crea el cliente y devuelve su clave.
4. HubSpot guarda la clave y el resultado de la sincronización.

La propiedad **Clave** (`clave`) identifica al cliente en ASPEL SAE. Este valor es devuelto por SAE y no debe modificarse manualmente en HubSpot.

La propiedad **Tipo cliente** es una selección en HubSpot. La integración convierte automáticamente sus valores internos a los códigos utilizados por ASPEL SAE:

| HubSpot | ASPEL SAE |
| --- | --- |
| Paciente (`PACIENTE`) | `P` |
| Médico (`MEDICO`) | `M` |
| Distribuidor (`DISTRIBUIDOR`) | `D` |
| Trabajo (`TRABAJO`) | `T` |
| Cliente diverso (`CLIENTE DIVERSO`) | `CD` |

La misma conversión se aplica en sentido inverso cuando el cambio se origina en ASPEL SAE.

### 3.2 Actualización manual de un contacto

Para solicitar una actualización, se debe cambiar **Sincronizar con ASPEL** (`sync_to_aspel`) a **Pendiente** (`pending`).

La integración intenta localizar al cliente en este orden:

1. Clave de ASPEL.
2. RFC.
3. Teléfono.
4. Correo electrónico.

Si encuentra una coincidencia única, actualiza el contacto existente. Si no encuentra ninguna coincidencia, intenta crear el contacto. Si encuentra varias coincidencias, detiene el proceso para evitar actualizar o crear un cliente incorrecto.

### 3.3 Propiedades técnicas de seguimiento

| Propiedad | Uso |
| --- | --- |
| `clave` | Identificador del cliente en ASPEL SAE. |
| `sync_to_aspel` | Solicita una sincronización manual hacia ASPEL. |
| `sync_status_aspel` | Indica si el proceso está pendiente, en proceso, exitoso o con error. |
| `last_sync_aspel` | Fecha y hora del último intento de sincronización. |
| `last_error_aspel` | Motivo del último error registrado. |

### 3.4 Cambios originados en ASPEL SAE

La integración consulta periódicamente los contactos modificados en ASPEL SAE. Cuando detecta un cambio:

- Actualiza el contacto de HubSpot si encuentra una coincidencia por clave, RFC, teléfono o correo.
- Crea el contacto en HubSpot si no existe una coincidencia.
- Evita procesar dos veces el mismo cambio.

Este proceso es automático y no requiere modificar `sync_to_aspel`.

### 3.5 Errores de contactos

Cuando ocurre un error, la integración intenta registrar una nota en el contacto de HubSpot con el contexto disponible. También actualiza las propiedades técnicas de seguimiento.

Antes de volver a intentar, se debe revisar:

- Que los campos obligatorios estén completos.
- Que el RFC, teléfono y correo correspondan al mismo cliente.
- Que no existan duplicados en HubSpot o ASPEL SAE.
- Que la clave de ASPEL no haya sido modificada manualmente.

## 4. Productos

### 4.1 Origen y frecuencia

Los productos se sincronizan automáticamente desde ASPEL SAE hacia HubSpot. El proceso consulta periódicamente únicamente los productos nuevos o modificados desde la última ejecución exitosa.

No existe un detonante manual en HubSpot para este flujo.

### 4.2 Datos sincronizados

La integración contempla las siguientes propiedades:

| HubSpot | ASPEL SAE |
| --- | --- |
| `clave` | `clave` |
| `name` | `descripcion` |
| `linea_sae` | `linea` |
| `clave_sat_sae` | `claveSat` |
| `clave_unidad_sae` | `claveUnidad` |
| `tipo_sae` | `clase` |
| `unidad_de_entrada_sae` | `unidadEntrada` |
| `unidad_de_salida_sae` | `unidadSalida` |

La **Clave** es el identificador utilizado para localizar y actualizar el producto en HubSpot. Si no existe un producto con esa clave, la integración lo crea.

Los precios comerciales utilizados en una cotización no se toman del precio base del producto de HubSpot. Se consultan en ASPEL SAE según el cliente y el almacén seleccionados en el elemento de pedido.

## 5. Negocios y contacto principal

Antes de preparar elementos de pedido o una cotización, el negocio debe tener exactamente un contacto asociado con la etiqueta **Contacto principal**.

La integración utiliza ese contacto para obtener:

- La clave del cliente en ASPEL SAE.
- La lista de precios asignada al cliente.
- Los datos fiscales y de envío requeridos para la cotización.

El contacto principal debe cumplir estas condiciones:

- Estar asociado al negocio con la etiqueta **Contacto principal**.
- Tener informada la propiedad `clave`.
- Contar con la información fiscal y de envío requerida.
- Tener una lista de precios válida cuando aplique.

Si el negocio no tiene contacto principal, tiene más de uno o el contacto no tiene clave de ASPEL, la consulta de precios y la creación de la cotización se detienen para evitar asignar datos de otro cliente.

## 6. Elementos de pedido: almacén, existencias y precio

### 6.1 Configuración inicial

Los elementos de pedido deben agregarse y configurarse antes de crear la cotización. Para cada elemento se debe revisar:

- Producto seleccionado.
- Clave del producto en ASPEL SAE.
- Cantidad solicitada.
- Almacén correspondiente a la operación.
- Lista de precios.
- Precio unitario.
- Existencias disponibles.

### 6.2 Detonante de la consulta

La consulta a ASPEL SAE se detona cuando cambia la propiedad **Almacén** (`almacen_id`) del elemento de pedido.

La integración identifica el producto por su `clave`, localiza el negocio y su contacto principal, y consulta ASPEL SAE utilizando:

- Clave del artículo.
- Clave del cliente.
- Almacén seleccionado.

ASPEL SAE determina la lista aplicable con la prioridad comercial configurada: cliente, almacén y, como último recurso, lista 1.

### 6.3 Datos actualizados en HubSpot

La respuesta de ASPEL SAE actualiza las siguientes propiedades del elemento de pedido:

| Propiedad | Descripción |
| --- | --- |
| `existencias` | Existencia disponible en el almacén. |
| `stock_maximo` | Stock máximo configurado. |
| `stock_minimo` | Stock mínimo configurado. |
| `price` | Precio aplicable al cliente y almacén. |
| `prices_list` | Número de lista de precios utilizada. |

### 6.4 Validación operativa

Después de seleccionar o modificar el almacén:

1. Guardar o actualizar los elementos de pedido según la interfaz de HubSpot.
2. Esperar entre 30 segundos y 1 minuto.
3. Refrescar la vista si los valores todavía no se muestran.
4. Confirmar precio, lista y existencias antes de crear la cotización.

Si cambia el cliente, el producto o el almacén, se debe volver a seleccionar o actualizar el almacén para refrescar la información comercial del elemento.

## 7. Creación de la cotización

### 7.1 Requisitos previos

Antes de crear o publicar la cotización, se debe confirmar que:

- El negocio tenga un único contacto principal.
- El contacto tenga clave de ASPEL SAE.
- Los datos fiscales y de envío estén completos.
- Todos los productos tengan clave de ASPEL SAE.
- Las cantidades sean correctas.
- Cada elemento tenga un almacén válido.
- El precio y la lista correspondan a la última consulta de ASPEL SAE.
- Las existencias sean suficientes para la operación.
- Los cambios hayan quedado guardados.

### 7.2 Publicación y detonante

La cotización debe crearse después de configurar y validar los elementos de pedido. Para prepararla para el envío:

1. Crear la cotización desde el negocio.
2. Revisar productos, cantidades, almacenes y precios.
3. Publicar la cotización en HubSpot.
4. Cambiar el negocio a una de las etapas autorizadas para la integración con ASPEL SAE.

La publicación por sí sola no envía la cotización. El detonante es el cambio de la propiedad **Etapa del negocio** (`dealstage`) a una etapa autorizada. La integración considera únicamente cotizaciones publicadas durante las últimas 24 horas y descarta las que ya fueron procesadas, están en proceso o tienen un error de negocio previamente registrado.

La cotización no necesita estar firmada para este flujo.

### 7.3 Validaciones realizadas por la integración

Antes de enviar la información, la integración:

1. Localiza la cotización elegible asociada al negocio.
2. Obtiene el contacto principal y su clave de cliente.
3. Obtiene el correo del remitente o vendedor de la cotización.
4. Lee la dirección de envío desde el contacto.
5. Obtiene los elementos asociados a esa cotización.
6. Reconsulta en ASPEL SAE el precio y la lista de cada producto.
7. Compara la lista del contacto, la lista del elemento y la lista devuelta por SAE.
8. Comprueba que el precio no haya cambiado y que la lista no incluya impuestos.
9. Valida los campos obligatorios antes de crear el documento.

La integración no corrige automáticamente diferencias de precio o lista. Si existe una diferencia, bloquea el envío y registra el motivo.

### 7.4 Información enviada a ASPEL SAE

La cotización incluye, entre otros datos:

- ID del negocio y de la cotización de HubSpot.
- Clave del cliente.
- Correo del vendedor.
- Dirección de envío.
- Clave del artículo, cantidad y almacén de cada partida.
- Lista de precios y precio unitario validados en ASPEL SAE.

Los IDs del negocio y la cotización permiten evitar la creación duplicada del mismo documento cuando se reintenta una operación técnica.

### 7.5 Resultado exitoso

Cuando ASPEL SAE crea o reconoce la cotización, HubSpot actualiza:

| Propiedad | Uso |
| --- | --- |
| `aspel_cve_doc` | Clave del documento en ASPEL SAE. |
| `aspel_folio` | Folio asignado por ASPEL SAE. |
| `aspel_serie` | Serie del documento. |
| `sync_status_aspel` | Resultado de la sincronización. |
| `last_sync_aspel` | Fecha y hora del último intento. |
| `last_error_aspel` | Último error, cuando aplica. |

También se agrega una nota al negocio con el documento, folio, serie, importe, fecha y el ID de la cotización de HubSpot.

## 8. Descuentos e impuestos

ASPEL SAE es la fuente de validación fiscal y calcula los impuestos del documento con base en la configuración de los productos. El middleware no debe asumir ni sustituir tasas fiscales.

Actualmente, los ajustes múltiples de impuestos y descuentos agregados directamente a una cotización de HubSpot requieren una regla comercial adicional antes de trasladarse a ASPEL SAE. Mientras esa definición no esté cerrada:

- No se debe asumir que todos los ajustes visualizados en HubSpot serán enviados a SAE.
- Los importes finales válidos son los calculados y devueltos por ASPEL SAE.
- Cualquier descuento especial debe validarse antes de cambiar el negocio a la etapa de envío.

## 9. Manejo de errores y reproceso

### 9.1 Error de negocio o de información

Ejemplos: contacto principal faltante, clave de cliente vacía, precio distinto, lista incorrecta, dirección incompleta o producto sin almacén.

La integración:

- Bloquea el envío.
- Marca la cotización con error cuando puede identificarla.
- Registra una nota en el negocio con el motivo y el contexto disponible.

Procedimiento:

1. Leer la nota registrada en el negocio.
2. Corregir la información en el contacto, negocio o elementos de pedido.
3. Guardar los cambios.
4. Generar una nueva cotización.
5. Publicarla y volver a cambiar el negocio a una etapa autorizada.

Una cotización con error de negocio no debe reutilizarse para solicitar una emisión nueva.

### 9.2 Error técnico o falta de respuesta

Ejemplos: indisponibilidad temporal, tiempo de espera agotado o error de comunicación.

En estos casos no se debe crear inmediatamente otra cotización, porque el documento pudo haberse generado en ASPEL SAE aunque HubSpot todavía no tenga la respuesta. El caso debe revisarse o reintentarse conservando los mismos IDs para aprovechar la protección contra duplicados.

### 9.3 Información para escalar un caso

El reporte debe incluir:

- Enlace del negocio en HubSpot.
- ID o nombre de la cotización.
- Contacto principal asociado.
- Texto completo de la nota o error.
- Fecha y hora aproximada del intento.
- Captura de pantalla, cuando sea posible.

## 10. Lista rápida de verificación

Antes de enviar una cotización a ASPEL SAE:

- [ ] El contacto existe en ASPEL SAE y tiene `clave` en HubSpot.
- [ ] El negocio tiene exactamente un contacto principal.
- [ ] El contacto tiene datos fiscales, dirección de envío y lista de precios.
- [ ] Todos los productos tienen clave de ASPEL SAE.
- [ ] Cada elemento tiene cantidad y almacén.
- [ ] Precio, lista y existencias ya se actualizaron.
- [ ] La cotización fue creada después de actualizar los elementos.
- [ ] La cotización está publicada.
- [ ] El negocio se moverá a una etapa autorizada.
- [ ] No existen diferencias pendientes de precio o lista.

## 11. Validaciones internas pendientes antes de capacitación

Los siguientes puntos deben confirmarse antes de convertir esta guía en material definitivo con capturas:

- Nombre visible de cada etapa de negocio autorizada para detonar la integración.
- Orden exacto de botones en HubSpot para guardar y actualizar elementos de pedido.
- Tiempo máximo acordado para considerar una operación sin respuesta.
- Responsable y canal de escalamiento operativo.
- Política comercial para representar múltiples impuestos y descuentos de HubSpot en ASPEL SAE.
- Confirmación del texto visible de la etiqueta **Contacto principal** en el portal.

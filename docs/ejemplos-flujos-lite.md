# Ejemplos de flujos - Integrador Lite

**Objetivo:** mostrar y validar las variantes de flujo disponibles actualmente.
**Audiencia:** cliente, equipo comercial, operaciones y soporte.
**Ultima revision:** 29 de septiembre de 2026.

## 1. Como funciona un flujo

Cada flujo sigue este orden:

```text
Cambio de propiedad en HubSpot
        |
        v
Validar disparador y condiciones
        |
        v
Asignar o validar propietario
        |
        v
Resolver regla Treble
        |
        v
Enviar la plantilla correspondiente
```

El paso de propietario y el paso de Treble pueden habilitarse u omitirse segun el proceso que se quiera automatizar.

## 2. Datos de ejemplo

Para ejecutar los ejemplos se pueden utilizar tres asesores:

| Clave | Propietario |
|---|---|
| A | Ana |
| B | Bruno |
| C | Carla |

Propiedades de ejemplo del contacto:

```text
lifecyclestage
campus_de_interes
nivel_escolar_de_interes
hs_analytics_source
hubspot_owner_id
phone
```

Plantillas Treble de ejemplo:

- Bienvenida general.
- Bienvenida Preescolar.
- Bienvenida Primaria.
- Bienvenida Secundaria.
- Seguimiento de admisiones.

## 3. Resumen de variantes

| Variante | Paso de propietario | Estado inicial del contacto | Politica | Paso Treble | Resultado | Validado |
|---|---|---|---|---|---|---|
| 1 | Habilitado | Sin propietario | No aplica | Habilitado | Asigna propietario y envia plantilla. | ✅ |
| 2 | Habilitado | Sin propietario | No aplica | Omitido | Solo asigna propietario. | ✅ |
| 3 | Habilitado | Con propietario | Conservar y detener | Habilitado | Conserva propietario y no envia plantilla. | ✅ |
| 4 | Habilitado | Con propietario | Conservar y continuar | Habilitado | Conserva propietario y envia plantilla. | ✅ |
| 5 | Omitido | Con propietario | Validar existente | Habilitado | No reasigna y envia plantilla. | ✅ |
| 6 | Omitido | Sin propietario | Validar existente | Habilitado | Detiene el flujo y no envia plantilla. | ✅ |
| 7 | Habilitado | Sin propietario | Asignacion fallida | Habilitado | Detiene el flujo y no envia plantilla. | ✅ |
| 8 | Cualquier estado | No cumple condiciones | No aplica | Cualquier estado | El flujo no se ejecuta. | ✅ |

## 4. Ejemplo 1 - Asignar propietario y enviar a Treble

### Caso de negocio

Un contacto se convierte en oportunidad, todavia no tiene asesor y debe recibir inmediatamente una bienvenida.

### Configuracion

```text
Disparador:
  lifecyclestage = opportunity

Condiciones:
  campus_de_interes = CDMX

Paso 1:
  Asignar propietario = habilitado
  Estrategia = secuencial o aleatoria
  Propietarios = A, B y C

Paso 2:
  Enviar por Treble = habilitado
```

### Contacto de prueba

```text
campus_de_interes: CDMX
nivel_escolar_de_interes: Primaria
hubspot_owner_id: vacio
```

### Resultado esperado

1. El flujo asigna A, B o C segun la estrategia configurada.
2. HubSpot confirma la asignacion.
3. Se selecciona la regla para Primaria.
4. Treble envia la plantilla `Bienvenida Primaria`.

```text
Resultado: propietario asignado + plantilla enviada
```

## 5. Ejemplo 2 - Solo asignar propietario

### Caso de negocio

El cliente necesita distribuir nuevos contactos entre sus asesores, pero no desea enviar WhatsApp en ese momento.

### Configuracion

```text
Paso 1:
  Asignar propietario = habilitado

Paso 2:
  Enviar por Treble = omitido
```

### Resultado esperado

- Se asigna el propietario en HubSpot.
- El flujo termina despues de la asignacion.
- No se realiza ningun envio a Treble.

```text
Resultado: propietario asignado + sin mensaje
```

## 6. Ejemplo 3 - Contacto con propietario: conservar y detener

### Caso de negocio

Un contacto ya tiene asesor. El negocio no quiere reasignarlo ni enviar un mensaje automatico desde este flujo.

### Configuracion

```text
Paso 1:
  Asignar propietario = habilitado
  Si ya tiene propietario = Conservar y detener el flujo

Paso 2:
  Enviar por Treble = habilitado
```

### Contacto de prueba

```text
hubspot_owner_id: A
```

### Resultado esperado

- Se conserva A como propietario.
- No se realiza una nueva asignacion.
- El flujo se detiene antes de Treble.

```text
Resultado: propietario conservado + sin mensaje
```

## 7. Ejemplo 4 - Contacto con propietario: conservar y continuar

### Caso de negocio

Un contacto ya tiene asesor, pero debe recibir una plantilla de seguimiento.

### Configuracion

```text
Paso 1:
  Asignar propietario = habilitado
  Si ya tiene propietario = Conservar y continuar a Treble

Paso 2:
  Enviar por Treble = habilitado
```

### Resultado esperado

- Se conserva el propietario actual.
- No se realiza una reasignacion.
- Se resuelve la regla Treble correspondiente.
- Se envia la plantilla de seguimiento.

```text
Resultado: propietario conservado + plantilla enviada
```

## 8. Ejemplo 5 - Omitir asignacion y enviar solamente a contactos asignados

### Caso de negocio

Se desea enviar una plantilla a una lista de contactos que ya fue distribuida previamente entre los asesores.

### Configuracion

```text
Paso 1:
  Asignar propietario = omitido

Paso 2:
  Enviar por Treble = habilitado
```

### Variante A: el contacto tiene propietario

```text
hubspot_owner_id: B
```

Resultado:

- Se valida que existe B.
- No se modifica el propietario.
- Se envia la plantilla Treble.

### Variante B: el contacto no tiene propietario

```text
hubspot_owner_id: vacio
```

Resultado:

- La validacion de propietario falla.
- El flujo se detiene.
- No se envia la plantilla.

## 9. Ejemplo 6 - Asignacion secuencial

### Caso de negocio

Repartir los contactos de forma ordenada y predecible entre todos los asesores.

### Configuracion

```text
Estrategia: Secuencial
Propietarios elegibles: A, B y C
```

### Prueba sugerida

Usar un flujo nuevo y cuatro contactos nuevos, todos sin propietario. El integrador conserva el ultimo turno del flujo; si se utiliza un flujo que ya proceso contactos, la secuencia continuara desde el ultimo propietario registrado.

| Contacto | Propietario esperado |
|---:|---|
| 1 | A |
| 2 | B |
| 3 | C |
| 4 | A |

```text
Secuencia esperada: A -> B -> C -> A
```

Un propietario inactivo o eliminado del flujo deja de participar en las asignaciones siguientes.

## 10. Ejemplo 7 - Asignacion aleatoria equitativa

### Caso de negocio

Repartir los contactos en orden aleatorio, garantizando que todos los asesores participen antes de repetir un ciclo.

### Configuracion

```text
Estrategia: Aleatoria por ciclos equitativos
Propietarios elegibles: A, B y C
```

### Prueba sugerida

Usar un flujo nuevo y seis contactos nuevos, todos sin propietario. Si se reutiliza un flujo con un ciclo en curso, primero debe terminarse ese ciclo para evaluar bloques completos.

Ejemplo de resultado valido:

```text
Ciclo 1: B -> A -> C
Ciclo 2: A -> C -> B
```

El orden puede cambiar, pero debe cumplir:

- A, B y C aparecen una vez en cada ciclo de tres contactos.
- No se repite el mismo propietario en dos asignaciones consecutivas.
- Ningun propietario inactivo participa.

Ejemplo invalido:

```text
Ciclo 1: A -> A -> C
```

Para esta prueba no se debe reutilizar un contacto que conserve propietario. Cada ejecucion debe usar un contacto nuevo o un contacto cuyo `hubspot_owner_id` este vacio.

## 11. Ejemplo 8 - Condiciones AND

### Caso de negocio

El flujo solo debe ejecutarse para oportunidades de CDMX que no provengan de fuentes excluidas.

### Configuracion

```text
Relacion entre grupos: Todos (AND)

Condicion 1:
  campus_de_interes = CDMX

Condicion 2:
  hs_analytics_source no esta en OFFLINE, REFERRALS
```

| Campus | Fuente | Resultado |
|---|---|---|
| CDMX | ORGANIC_SEARCH | Ejecuta el flujo. |
| CDMX | OFFLINE | No ejecuta el flujo. |
| Cancun | ORGANIC_SEARCH | No ejecuta el flujo. |

## 12. Ejemplo 9 - Condiciones OR

### Caso de negocio

Utilizar el mismo flujo para contactos interesados en CDMX o Cancun.

### Configuracion

```text
Relacion dentro del grupo: Cualquiera (OR)

campus_de_interes = CDMX
OR
campus_de_interes = Cancun
```

| Campus | Resultado |
|---|---|
| CDMX | Ejecuta el flujo. |
| Cancun | Ejecuta el flujo. |
| La Paz | No ejecuta el flujo. |

## 13. Ejemplo 10 - Elegir plantilla segun las propiedades

### Caso de negocio

Despues de resolver el propietario, enviar una plantilla distinta segun el nivel escolar.

### Reglas Treble

| Prioridad | Condicion | Plantilla resultante |
|---:|---|---|
| 300 | Nivel = Preescolar | Bienvenida Preescolar |
| 200 | Nivel = Primaria | Bienvenida Primaria |
| 100 | Nivel = Secundaria | Bienvenida Secundaria |

Ejemplos:

```text
Contacto 1: nivel Primaria   -> Bienvenida Primaria
Contacto 2: nivel Preescolar -> Bienvenida Preescolar
Contacto 3: nivel Secundaria -> Bienvenida Secundaria
```

Solo se envia la primera regla que coincide, ordenada por prioridad.

## 14. Ejemplo 11 - Regla general como respaldo

### Caso de negocio

Enviar una plantilla general cuando ninguna regla especifica de nivel coincida.

### Reglas

```text
Prioridad 300: Nivel = Preescolar -> Bienvenida Preescolar
Prioridad 200: Nivel = Primaria   -> Bienvenida Primaria
Prioridad 100: Sin condiciones    -> Bienvenida General
```

Para un contacto con nivel `Universidad`, las dos primeras reglas no coinciden y se utiliza `Bienvenida General`.

## 15. Ejemplo 12 - Fallo al asignar propietario

### Caso de negocio

HubSpot rechaza la asignacion por permisos, owner invalido o un error temporal.

### Resultado esperado

- El flujo se detiene.
- No se envia la plantilla Treble.
- Se registra el error.
- Se intenta crear una nota operativa en HubSpot.

```text
Resultado: error de asignacion + flujo detenido + sin mensaje
```

Esta regla evita contactar automaticamente un lead que no pudo quedar asociado correctamente a un asesor.

## 16. Ejemplo 13 - Ninguna regla Treble coincide

### Caso de negocio

La asignacion fue exitosa, pero no existe una regla de mensaje compatible con las propiedades del contacto.

### Resultado esperado

- Se conserva la asignacion realizada.
- No se envia una plantilla incorrecta.
- El Record indica que ninguna regla Treble coincidio.

```text
Resultado: propietario asignado + sin plantilla aplicable
```

## 17. Matriz breve para demostracion

Esta secuencia permite demostrar las capacidades principales con pocos contactos:

| Prueba | Contacto inicial | Configuracion | Resultado a mostrar |
|---:|---|---|---|
| 1 | Sin propietario | Secuencial + Treble | Asigna A y envia. |
| 2 | Sin propietario | Secuencial + Treble | Asigna B y envia. |
| 3 | Sin propietario | Solo asignar | Asigna C y no envia. |
| 4 | Con propietario A | Conservar y detener | Conserva A y no envia. |
| 5 | Con propietario B | Conservar y continuar | Conserva B y envia. |
| 6 | Con propietario C | Omitir asignacion | Valida C y envia. |
| 7 | Sin propietario | Omitir asignacion | Detiene y no envia. |
| 8 a 13 | Sin propietario | Aleatoria equitativa | Dos ciclos completos con A, B y C. |
| 14 | Sin propietario, fuente OFFLINE | Condicion negativa | No ejecuta el flujo. |
| 15 | Sin propietario, nivel Primaria | Reglas por nivel | Envia Bienvenida Primaria. |

## 18. Formato para compartir resultados

| Prueba | Contacto | Propietario inicial | Propietario final | Plantilla | Resultado |
|---:|---|---|---|---|---|
| 1 | Contacto 1 | Sin propietario | A | Bienvenida Primaria | Aprobado |
| 2 | Contacto 2 | Sin propietario | B | Bienvenida Primaria | Aprobado |
| 3 | Contacto 3 | Sin propietario | C | No aplica | Aprobado |

Para cada fila puede adjuntarse:

- captura del contacto en HubSpot;
- captura del flujo configurado;
- ID del Record generado;
- plantilla seleccionada;
- resultado final en Treble.

## 19. Resultado general esperado

El integrador permite combinar:

- disparadores por cambio de propiedad;
- condiciones AND y OR;
- operadores positivos y negativos;
- asignacion secuencial;
- asignacion aleatoria equitativa;
- conservacion de propietarios existentes;
- flujos de solo asignacion;
- flujos de asignacion y mensaje;
- flujos de mensaje para contactos previamente asignados;
- seleccion de plantillas por prioridad y propiedades del contacto;
- detencion segura cuando la asignacion falla o falta un propietario requerido.

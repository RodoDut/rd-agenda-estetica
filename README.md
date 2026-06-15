# Plugin RDT Centros Core

## 1. Propósito general

El plugin **RDT Centros Core** implementa la lógica de negocio central para un sistema de gestión de turnos online orientado a centros de estética. Su objetivo es desacoplar completamente la lógica crítica del tema visual y de otros plugins, siguiendo principios **CLEAN Architecture** y **SOLID**.

El sitio web utiliza WordPress únicamente como framework de entrega (UI, usuarios, CPT, REST API), mientras que este plugin concentra:

- Reglas de negocio
- Flujos de turnos
- Notificaciones
- Seguridad y control de acceso
- Integraciones frontend/backend

---

## 2. Estructura general del plugin

```
rdt-centros-core/
├── rdt-centros-core.php          ← Bootstrap: includes, hooks, inicialización
├── includes/
│   ├── api/                      ← Controladores REST
│   ├── assets/
│   │   ├── assets-loader.php     ← Carga condicional de JS y CSS
│   │   ├── css/                  ← Hojas de estilo por shortcode
│   │   └── js/                   ← Scripts por funcionalidad
│   ├── auth/                     ← Login redirect
│   ├── account/                  ← Mi Cuenta WooCommerce, registro de centros
│   ├── cpt/                      ← Custom Post Types
│   ├── domain/                   ← Enumeraciones de estados
│   ├── helpers/                  ← Funciones auxiliares globales
│   ├── install/                  ← Seeds de datos iniciales
│   ├── integrations/             ← Hooks del plugin SSA
│   ├── notifications/            ← Envío de emails
│   ├── repositories/             ← Acceso a datos (CPT)
│   ├── roles/                    ← Registro y restricción de roles
│   ├── security/                 ← Bloqueo del admin
│   ├── services/                 ← Lógica de negocio
│   └── shortcodes/               ← Renderizado del frontend
```

Cada carpeta representa una **responsabilidad única** conforme al principio SRP.

---

## 3. Custom Post Types (CPT)

| CPT | Archivo | Descripción |
|---|---|---|
| `servicios_clientes` | `class-cpt-servicios-clientes.php` | Servicios que cada centro ofrece a sus clientes. Cada centro gestiona los suyos. |
| `turno_cliente` | `class-cpt-turno-cliente.php` | Turnos reservados por las clientas |
| `centro_estetico` | `class-cpt-centro-estetico.php` | Perfil de cada centro estético |
| `jornada_centro` | `class-cpt-jornada-centro.php` | Jornada de trabajo de un centro en una fecha específica |

### Campos meta relevantes del CPT `centro_estetico`

| Meta key | Tipo | Descripción |
|---|---|---|
| `usuario_responsable` | int | ID del WP User dueño del centro |
| `requiere_aprobacion_turno` | string `'1'` / `''` | Si está en `'1'`, los turnos nuevos se crean como `pendiente` y el centro recibe un email para aprobar o rechazar antes de que el cliente sea notificado |

### Campos meta del CPT `servicios_clientes`

| Meta key | Tipo | Descripción |
|---|---|---|
| `centro_estetico_id` | int | ID del CPT `centro_estetico` que creó y es dueño de este servicio. |
| `duracion_servicio` | int | Duración del servicio en minutos. |
| `detalle_servicio` | string | Descripción opcional del servicio. |
| `categoria_servicio` | string | Categoría del servicio (ej: "Depilación", "Tratamiento Facial"). |

### Campos meta del CPT `turno_cliente`

| Meta key | Tipo | Descripción |
|---|---|---|
| `centro_estetico_id` | int | Relación con el CPT centro_estetico |
| `fecha` | string YYYY-MM-DD | Fecha del turno |
| `hora_inicio` | string HH:MM | Hora de inicio |
| `hora_fin` | string HH:MM | Hora de fin calculada |
| `duracion` | int | Duración en minutos |
| `tratamiento_id` | int | ID del CPT `servicios_clientes` |
| `nombre_cliente` | string | Nombre de la clienta |
| `email_cliente` | string | Email de la clienta |
| `telefono_cliente` | string | Teléfono de la clienta |
| `token_turno` | string UUID | Token único para cancelación sin login |
| `estado_turno` | string | Estado actual (ver TurnoEstado) |
| `rdt_aprobacion_token` | string | Token de un solo uso para aprobar/rechazar desde email |
| `rdt_aprobacion_token_expiry` | int | Timestamp de expiración del token de aprobación (TTL: 7 días) |

---

## 4. Domain (estados)

### `TurnoEstado.php`

| Constante | Valor | Descripción |
|---|---|---|
| `ACTIVO` | `'activo'` | Turno confirmado y vigente |
| `PENDIENTE` | `'pendiente'` | Turno creado pero pendiente de aprobación por el centro |
| `CANCELADO` | `'cancelado'` | Turno cancelado (por el cliente o por el centro) |
| `COMPLETADO` | `'completado'` | Turno que ya ocurrió |
| `AUSENTE` | `'ausente'` | El cliente no se presentó |
| `EXPIRADO` | `'expirado'` | El turno pasó sin ser completado |
| `RECHAZADO` | `'rechazado'` | Turno rechazado por el centro en el flujo de aprobación |

### `JornadaEstado.php`
Constantes de estado para una jornada: `activa`, `cancelada`, `completada`, `expirada`.

---

## 5. Repositorios

Cada repositorio encapsula el acceso a datos de un CPT. Ningún servicio o controlador hace queries directas fuera de estas clases.

### `TurnoClienteRepository`

- `crear(array $data)` — Inserta un nuevo turno
- `findByToken(string $token)` — Busca un turno por su token de cancelación
- `actualizarEstado(int $turno_id, string $estado)` — Actualiza el estado de un turno
- `findByCentroYFecha(int $centro_id, string $fecha)` — Devuelve todos los turnos (cualquier estado) de un centro en una fecha. Filtra el `centro_estetico_id` en PHP para evitar fallos por serialización de ACF.
- `findActivosByCentroYFecha(int $centro_id, string $fecha)` — Igual que el anterior pero solo turnos activos. Usado para cancelaciones masivas.

### `JornadaCentroRepository`

- `fromToken(string $token)` — Obtiene una jornada por su token público
- `findBySsaReservaId(int $ssa_reserva_id)` — Busca una jornada por su ID de reserva en SSA
- `findActivaByCentroYFecha(int $centro_id, string $fecha)` — Busca la jornada activa de un centro para una fecha. Resuelve el campo `centro_estetico_id` en PHP tolerando serialización de ACF. Usado por `TurnoCreator` en modo interno.
- `actualizarFechaYHorario(...)` — Actualiza fecha y horarios de una jornada (reagenda)
- `reactivarSiCorresponde(int $centro_id, string $fecha)` — Reactiva una jornada COMPLETADA si la fecha es futura y se canceló un turno
- `obtenerSsaReservaId(...)` — Obtiene el ID de reserva SSA de la jornada asociada a un centro y fecha
- `marcarComoCompletada / marcarComoCancelada / marcarComoActiva` — Cambios de estado por token
- `existe(int $centro_estetico_id, string $fecha)` — Verifica si ya existe una jornada para ese centro y fecha

### `ServiciosClientesRepository`

- `findByCentro(int $centro_id)` — Devuelve todos los servicios de un centro.
- `crear(int $centro_id, ...)` — Crea un nuevo servicio para un centro, respetando el límite de 10.
- `actualizar(int $id, int $centro_id, ...)` — Edita un servicio, validando que pertenezca al centro.
- `eliminar(int $id, int $centro_id)` — Elimina un servicio, validando pertenencia.
- `contarPorCentro(int $centro_id)` — Cuenta los servicios de un centro para validar el límite.

---

## 6. Servicios

### `TurnoCreator`

Orquesta la creación de un turno de cliente. Soporta dos modos de resolución de contexto:

**Modo público (por token):** la clienta accede desde la agenda pública compartida por el centro.

**Modo interno (por usuario logueado):** el centro crea un turno manualmente desde su panel. El `centro_id` se deriva del usuario autenticado en el servidor — nunca del payload.

Flujo:
1. Resolver contexto (jornada, centro, fecha, horario)
2. Validar que el horario no sea pasado
3. Obtener duración del servicio y **validar que el servicio pertenece al centro de la jornada**.
4. Validar disponibilidad del slot
5. Calcular `hora_fin`
6. Determinar el estado inicial del turno según `requiere_aprobacion_turno`
7. Persistir el turno
8. Enviar notificaciones según el flujo activo (estándar o aprobación)
9. Verificar si la jornada quedó completa y marcarla

**Flujo estándar:** turno creado como `activo` → notificación inmediata al cliente y al centro.

**Flujo de aprobación** (cuando `requiere_aprobacion_turno = '1'`): turno creado como `pendiente` → solo se notifica al centro con botones de Aprobar/Rechazar. El cliente recibe el email únicamente cuando el centro toma acción.

### `JornadaCreator`

Encapsula la lógica central para crear una `jornada_centro`. 

**Responsabilidades:**
1. Validar que el usuario responsable tenga un perfil de centro asociado.
2. **Prevención de duplicados:** Valida que no exista una jornada activa para el mismo centro en la misma fecha. En caso de conflicto, retorna un error específico (409 Conflict) junto al ID de la jornada existente.
3. Generar el token UUID de acceso público.
4. Persistir la jornada y sus metadatos.
5. Gestionar el envío opcional de notificaciones por email (permite diferir el envío en flujos como el de SSA).

### `CancelarTurno`

Cancela un turno existente por token. Flujo:
1. Buscar el turno por token
2. Validar que el estado sea cancelable
3. Actualizar estado a `cancelado`
4. Reactivar la jornada si estaba `completada` y la fecha es futura
5. Disparar el hook `rdt/turno/cancelado_por_cliente` para notificaciones

**Importante:** no dispara el hook `ssa/appointment/canceled` para evitar que la jornada del centro sea cancelada como efecto secundario.

### `HorariosCalculator`

Dado un día, un centro, una duración y el rango horario de la jornada, devuelve los slots disponibles en intervalos de 5 minutos. Considera los turnos activos existentes y filtra horarios pasados si la fecha es hoy.

### `DisponibilidadService`

Servicio auxiliar de disponibilidad (en uso por `HorariosCalculator`).

---

## 7. Endpoints REST (`/wp-json/rdt/v1/`)

| Método | Ruta | Descripción | Auth |
|---|---|---|---|
| GET | `/horarios` | Horarios disponibles para un tratamiento. Soporta modo público (por `token`) y modo interno (por `fecha` con usuario logueado). | Pública / Logueado |
| POST | `/turno` | Crea un nuevo turno. Modo público (con `token`) o interno (con `fecha` y usuario logueado). | Pública / Logueado |
| POST | `/turno/cancelar` | Cancela un turno existente por `token_turno`. | Pública |
| PATCH | `/turno/{id}/estado` | Cambia el estado de un turno (`activo`, `cancelado`, `pendiente`). Solo el centro dueño del turno puede operar. Notifica al cliente por email. | Logueado (centro) |
| GET | `/turno/{id}/aprobar?token=XXX` | Aprueba un turno pendiente desde el link del email. Token de un solo uso, TTL 7 días. | Token |
| GET | `/turno/{id}/rechazar?token=XXX` | Rechaza un turno pendiente desde el link del email. Token de un solo uso, TTL 7 días. | Token |
| GET | `/admin/centros` | Lista de centros estéticos activos para selectores de administración. | Admin (`manage_options`) |
| POST | `/admin/jornada` | Crea una nueva jornada manualmente. Utiliza `JornadaCreator` y maneja conflictos de duplicados. | Admin (`manage_options`) |
| GET | `/calendario` | Devuelve los slots del día (ocupados y libres) para el centro del usuario logueado. El `centro_id` se resuelve del usuario autenticado, nunca del parámetro de URL. | Logueado |
| GET | `/calendario/jornadas` | Devuelve las fechas con jornadas activas del centro del usuario logueado. | Logueado |
| GET | `/tratamientos` | Devuelve la lista de servicios del centro logueado. Usado por el modal de reserva interna. | Logueado |
| GET | `/registro/aprobar?token=XXX` | Aprueba el registro de un nuevo centro estético. Cambia el rol a `centro_estetico` y envía email con link de contraseña. | Token |
| GET | `/registro/rechazar?token=XXX` | Rechaza el registro de un nuevo centro estético. Elimina el CPT y el usuario. | Token |
| POST | `/centro/perfil` | Actualiza los datos del perfil del centro (nombre, teléfono, dirección, etc.). | Logueado (centro) |
| GET | `/centro/servicios` | Lista los servicios (nombre, duración, detalle) del centro logueado. | Logueado (centro) |
| POST | `/centro/servicios` | Crea un nuevo servicio para el centro logueado (límite 10). | Logueado (centro) |
| PUT | `/centro/servicios/{id}` | Actualiza un servicio existente del centro logueado. | Logueado (centro) |
| DELETE | `/centro/servicios/{id}` | Elimina un servicio del centro logueado. | Logueado (centro) |

---

## 8. Shortcodes

### `[agenda_publica]`

Formulario de reserva de turno para la clienta final. Se accede mediante un link con `?token=...` que el centro comparte con sus clientas.

- Valida el token y muestra los **servicios disponibles para ese centro específico**.
- Al seleccionar tratamiento, carga los horarios disponibles vía AJAX
- Permite completar datos del cliente y confirmar el turno
- No requiere login — el acceso está controlado por el token de jornada

### `[admin_crear_jornada]`

Herramienta de administración para la carga manual de jornadas en el sistema. 

- **Seguridad:** Solo se renderiza para usuarios con capacidad `manage_options`. Para el resto, el output es nulo.
- **Interfaz:** Permite seleccionar un centro estético, fecha y rango horario.
- **Flujo:** Al crear con éxito, muestra una tarjeta con el enlace de la agenda pública listo para copiar y un botón para realizar una nueva carga rápida.

### `[cancelar_turno]`

Pantalla de confirmación de cancelación para la clienta. Se accede mediante el link con `?token=...` incluido en el email de confirmación de turno.

- Muestra el botón de cancelación
- Al confirmar, llama al endpoint REST y muestra el resultado
- Deshabilita el botón tras la cancelación exitosa para evitar doble envío

### `[reserva_jornada]`

Página de reserva de jornada para el centro estético (integración con SSA). Renderiza el shortcode `[ssa_booking]` con prefill automático de los datos del centro.

- Los campos del formulario SSA (nombre, email, teléfono, dirección) se rellenan automáticamente con los datos del centro via polling del iframe (same-origin)
- Al confirmar la reserva, aparece un toast con botones "Completar" (redirige) y "Cancelar" (cierra)
- El `redirect_url` es configurable como atributo del shortcode

### `[calendario_centro]`

Calendario diario del panel del centro estético. Solo accesible para usuarios logueados con un centro asociado.

**Funcionalidades de visualización:**
- Carga las jornadas activas del centro y permite navegar entre ellas con flechas
- Arranca en la primera jornada activa >= hoy
- Las flechas se deshabilitan cuando no hay más jornadas en esa dirección
- Muestra los turnos ocupados (nombre, tratamiento, horario, teléfono) y los huecos libres agrupados en un único bloque por rango
- Cada turno muestra un badge de estado (`activo`, `pendiente`, `cancelado`) con color diferenciado

**Gestión de estado de turnos:**
- Cada turno ocupado tiene un dropdown que permite cambiar su estado a: `Activo`, `Pendiente` o `Cancelado`
- Al seleccionar un nuevo estado, aparece un diálogo de confirmación con el nombre del cliente y el cambio propuesto
- Al confirmar, el cambio se aplica via PATCH al endpoint `/turno/{id}/estado`
- La UI se actualiza de forma optimista (sin recargar la grilla completa)
- El cliente recibe un email notificando el nuevo estado de su turno

**Modal de reserva interna:**
- Permite al centro cargar un turno manualmente sin salir del calendario
- Carga tratamientos y jornadas en paralelo al inicializar
- Al seleccionar tratamiento, carga los horarios disponibles pre-seleccionando el slot clickeado
- Tras confirmar el turno, el calendario se recarga automáticamente
- Cierre con X, click fuera del modal o tecla Escape

**Seguridad:** el `centro_id` nunca se toma de la URL ni del payload — siempre se deriva del usuario autenticado en el servidor.

---

## 9. Flujos de notificación

### Flujo estándar (sin aprobación)

```
Cliente reserva turno
        ↓
TurnoCreator → estado: activo
        ↓
Email al cliente: confirmación + botón de cancelación
Email al centro: aviso de nuevo turno
```

### Flujo de aprobación (`requiere_aprobacion_turno = '1'`)

Para activarlo: poner `requiere_aprobacion_turno = 1` en los metas del CPT `centro_estetico`.

```
Cliente reserva turno
        ↓
TurnoCreator → estado: pendiente
        ↓
Email al centro: "Nuevo turno pendiente" con botones Aprobar / Rechazar
        ↓
Centro hace click en "Aprobar"  → GET /rdt/v1/turno/{id}/aprobar?token=XXX
                                → estado: activo
                                → Email al cliente: confirmación
Centro hace click en "Rechazar" → GET /rdt/v1/turno/{id}/rechazar?token=XXX
                                → estado: cancelado
                                → Email al cliente: rechazo
```

El token de aprobación tiene TTL de 7 días y es de un solo uso (se elimina al procesar). Si el centro no toma acción dentro del plazo, el turno permanece en estado `pendiente`.

### Flujo de cambio de estado manual desde el calendario

```
Centro cambia estado desde el dropdown del calendario
        ↓
Diálogo de confirmación (nombre cliente + estado anterior → nuevo estado)
        ↓
PATCH /rdt/v1/turno/{id}/estado
        ↓
activo    → Email al cliente: "Tu turno fue confirmado"
cancelado → Email al cliente: "Tu turno fue cancelado"
pendiente → Email al cliente: "Tu turno está pendiente de confirmación"
```

### Flujo de cancelación por el cliente

```
Cliente hace click en "Cancelar turno" del email
        ↓
GET /cancelar-turno?token=XXX (shortcode [cancelar_turno])
        ↓
Hook rdt/turno/cancelado_por_cliente
        ↓
Email al cliente: confirmación de cancelación
Email al centro: aviso de turno cancelado
```

---

## 10. Notificaciones — Clases y responsabilidades

| Clase | Archivo | Responsabilidad |
|---|---|---|
| `TurnoNotification` | `class-turno-notification.php` | Notificaciones del flujo estándar: confirmación al cliente, aviso al centro, cancelaciones por cliente y por reagenda |
| `SsaBookingMail` | `class-ssa-booking-mail.php` | Email al centro cuando SSA registra una nueva jornada. Incluye botón WhatsApp para compartir la agenda |
| `TurnoAprobacionMailer` | `class-turno-aprobacion-mailer.php` | Flujo de aprobación: email al centro con botones Aprobar/Rechazar, email al cliente cuando el turno es aprobado o rechazado |
| `TurnoEstadoMailer` | `class-turno-estado-mailer.php` | Notifica al cliente cuando el centro cambia manualmente el estado de su turno desde el calendario |
| `RegistroMailer` | `class-registro-mailer.php` | Flujo de registro de centros: emails al usuario pendiente, al admin con botones de aprobación, al usuario aprobado (con link de contraseña) y al usuario rechazado |

### `TurnoNotification` — Métodos

| Método | Destinatario | Cuándo |
|---|---|---|
| `notificarCliente` | Clienta | Al confirmar un turno (flujo estándar). Incluye detalles, link de WhatsApp al centro y botón de cancelación. |
| `notificarCentro` | Centro | Al confirmar un turno nuevo (flujo estándar). |
| `notificarCancelacionPorCliente` | Clienta | Al cancelar su propio turno. |
| `notificarCentroCancelacionPorCliente` | Centro | Cuando una clienta cancela su turno. |
| `notificarCancelacionPorReagenda` | Clienta | Cuando el centro reagenda su jornada y el turno queda cancelado. |

---

## 11. Registro de centros estéticos

### Flujo de registro

```
Usuario completa el formulario de registro en /my-account/
        ↓
CentroRegistration::procesarRegistro()
        ↓
Usuario creado con rol subscriber + CPT centro_estetico en estado pending
        ↓
RegistroMailer::enviarPendienteAlUsuario()  → email al usuario
RegistroMailer::enviarNuevoRegistroAlAdmin() → email al admin con botones
        ↓
Admin hace click en "✓ Aprobar" o "✗ Rechazar" en el email
        ↓
GET /wp-json/rdt/v1/registro/aprobar?token=XXX
GET /wp-json/rdt/v1/registro/rechazar?token=XXX
        ↓
Aprobar:  rol → centro_estetico, CPT → publish, email al usuario con link de contraseña
Rechazar: elimina CPT + usuario, email de rechazo
```

### Clases involucradas

- `CentroRegistration` — orquestador + campos del formulario + procesamiento del registro
- `RegistroUI` — popup de registro, botón, ocultamiento del formulario nativo de WooCommerce, mensajes post-registro
- `RegistroMailer` — todos los emails del flujo de registro y aprobación
- `RegistroAprobacionController` — endpoints REST de aprobación y rechazo

### Seguridad del token de registro

Token de 48 chars, TTL 7 días, uso único (se elimina al procesar).

---

## 12. Mi Cuenta WooCommerce (`WooAccountCustomizer`)

Personalización de la página `/my-account/` exclusivamente para usuarios con rol `centro_estetico`.

### Menú personalizado

Elimina: `orders`, `downloads`, `edit-address`, `payment-methods`. Agrega: `Mi Agenda` (panel-centro).

### Escritorio (`/my-account/`)

Muestra bienvenida con botón a la agenda y tarjeta con los datos del centro en solo lectura.

### Formulario de edición (`/my-account/edit-account/`)

Formulario unificado que reemplaza el nativo de WooCommerce. Incluye secciones: datos del centro estético (7 campos), datos de acceso (email) y cambio de contraseña.

**Técnica de supresión del formulario nativo:** WooCommerce 10+ registra el formulario nativo con una closure interna, haciendo imposible usar `remove_action`. La solución implementada usa output buffering (`ob_start` en prioridad 0 / `ob_get_clean` en prioridad 999) para capturar todo el output del hook y retener únicamente el formulario propio mediante regex sobre la clase CSS `rdt-edicion-form`.

### Perfil REST

Endpoint `POST /wp-json/rdt/v1/centro/perfil` para actualizar los datos del centro desde el formulario de edición vía AJAX.

---

## 13. Integración con SSA (`ssa-hooks.php`)

El plugin se integra con **Simply Schedule Appointments (SSA)** mediante sus hooks de acción.

### `ssa/appointment/booked`
Al reservar una cita en SSA, delega en `JornadaCreator` para crear el CPT `jornada_centro`. La lógica de creación está desacoplada del hook de SSA. El email de confirmación se suprime aquí para enviarse posteriormente tras la interacción con el popup de oferta de gel.

### `ssa/appointment/canceled`
Al cancelar una jornada desde SSA:
1. Cancela todos los turnos de clientes activos asociados a esa jornada
2. Notifica a cada cliente por email
3. Marca la jornada como `cancelada`

### `ssa/appointment/edited`
Al reagendar una jornada en SSA (cambio de fecha):
1. Cancela los turnos de clientes de la fecha anterior y los notifica
2. Actualiza la jornada con la nueva fecha y horario
3. Suprime el email de reagenda de SSA y envía el propio

### `rdt/turno/cancelado_por_cliente` (hook interno)
Disparado por `CancelarTurno` cuando una clienta cancela su propio turno. Envía email de confirmación a la clienta y aviso al centro. Este hook está separado de `ssa/appointment/canceled` intencionalmente para evitar que cancelar un turno de cliente cancele también la jornada del centro.

### SSA Prefill (`SsaPrefill` / `ssa-prefill.js`)

Al cargar el shortcode `[reserva_jornada]`, el iframe de SSA se rellena automáticamente con los datos del centro. Técnica: polling con `setInterval` cada 300ms sobre `contentWindow.document` (same-origin). Vue 3 no genera mutaciones `childList` detectables, por lo que MutationObserver no aplica. Los valores se inyectan con el setter nativo del descriptor de propiedad para bypasear Vue y se dispara el evento `input` para que Vue los registre.

---

## 14. Seguridad

- Los endpoints del calendario (`/calendario`, `/calendario/jornadas`, `/tratamientos`) requieren usuario logueado y derivan el `centro_id` del usuario autenticado, nunca de parámetros de URL manipulables.
- `TurnoCreator` en modo interno resuelve el `centro_id` del usuario autenticado, ignorando cualquier valor del payload.
- El endpoint `PATCH /turno/{id}/estado` verifica que el turno pertenezca al centro del usuario logueado antes de modificarlo.
- El acceso público a la agenda de una jornada está controlado por un token UUID único generado al crear cada jornada.
- Los tokens de aprobación de turnos y de registro de centros son de un solo uso, se eliminan al procesarse y tienen TTL de 7 días. Se validan con `hash_equals` para evitar timing attacks.
- Los roles de centro tienen bloqueado el acceso al panel de administración de WordPress.

---

## 15. Deuda técnica conocida

### `WooAccountCustomizer` — Violación de SRP

**Archivo:** `includes/account/class-woo-account-customizer.php`

**Problema:** La clase acumula demasiadas responsabilidades: carga de assets, menú de Mi Cuenta, renderizado del escritorio, output buffering para suprimir el formulario nativo de WooCommerce, renderizado y guardado del formulario de edición, y endpoint REST del perfil.

**Por qué existe:** WooCommerce 10+ registra el formulario nativo de edit-account con una closure interna que hace imposible usar `remove_action` con un string. La solución adoptada (output buffering + regex sobre la clase `rdt-edicion-form`) funciona pero es frágil — si WooCommerce cambia la estructura interna del hook, el fallback mostraría ambos formularios.

**Refactorización pendiente:** separar en cuatro clases:

```
account/
    class-woo-account-customizer.php  ← orquestador: hooks, menú, assets
    class-account-escritorio.php      ← renderizado del escritorio (solo lectura)
    class-account-edit-form.php       ← formulario de edición + buffering + guardado
    class-account-rest.php            ← endpoint REST del perfil del centro
```

**Prioridad:** baja — implementar post-MVP.

---

## 16. Convenciones de desarrollo

- Namespaces obligatorios en todas las clases: `RDT\CentrosEstetica\...`
- Los shortcodes no contienen lógica de negocio — solo renderizan HTML y cargan assets
- Los repositorios son la única capa que interactúa con la base de datos
- Los servicios orquestan repositorios y notificaciones sin depender del contexto HTTP
- Los controladores REST solo validan, sanitizan y delegan a servicios
- El campo `centro_estetico_id` en ACF puede estar serializado como array, objeto o entero — siempre se resuelve con lógica defensiva en PHP
- Los hooks de SSA y los hooks internos (`rdt/...`) están separados para evitar efectos secundarios no deseados entre sistemas
- Los assets siempre se cargan vía `AssetsLoader` con versionado por `filemtime` para cache-busting automático
- Paleta CSS del plugin: `#4A7A84` primario, `#ecc77c` / `#b38233` acento, `#0d141a` texto, `#e6e0e2` bordes

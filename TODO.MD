# TODO — Plugin RDT Centros Core

---

## ⚠️ Deuda técnica prioritaria

### Refactorizar `WooAccountCustomizer` (violación de SRP)

- **Archivo:** `includes/account/class-woo-account-customizer.php`
- **Problema:** la clase tiene 8 responsabilidades. Además, la solución de output buffering + regex para suprimir el formulario nativo de WooCommerce es frágil: si WooCommerce cambia la clase CSS interna del formulario o la estructura del hook `woocommerce_account_edit-account_endpoint`, el mecanismo puede fallar silenciosamente mostrando ambos formularios.
- **Solución:** separar en:
  - `class-woo-account-customizer.php` — orquestador puro: solo registra hooks
  - `class-account-escritorio.php` — renderizado del escritorio en solo lectura
  - `class-account-edit-form.php` — formulario de edición, output buffering y guardado
  - `class-account-rest.php` — endpoint REST del perfil
- **Prioridad:** baja — encarar al estabilizar el MVP

### 🔄 Unificar terminología 'tratamiento' a 'servicio'
- **Archivos afectados:** `TurnoClienteRepository.php`, `CalendarioController.php`, `TurnoAprobacionController.php`, `CalendarExportController.php`, `HorariosController.php`, `TratamientoRepository.php`, `ServiciosClientesRepository.php` y `calendario-centro.js`.
- **Problema:** Actualmente, se utilizan indistintamente los términos 'tratamiento' y 'servicio' para referirse a la misma entidad, y en algunos casos, se persisten con meta keys diferentes (`tratamiento_id` y `servicio_id`). Esto genera confusión, inconsistencia y requiere lógica de compatibilidad en varios puntos del código.
- **Solución:** Estandarizar la terminología a 'servicio' en todo el plugin (código, meta keys, nombres de variables, etc.) y eliminar cualquier referencia a 'tratamiento' para hacer el plugin más genérico y adaptable a diferentes tipos de centros.
- **Prioridad:** media — abordar para mejorar la coherencia y reducir la deuda técnica.

---

---

## Funcionalidades pendientes

# Pasos que aún faltan para completar el desarrollo del plugin:
Aquí tienes los pasos que faltan para completar el desarrollo del plugin:

1. **Agregar más funcionalidades**:
   - Añadir la opción de enviar recordatorios automáticos por email o SMS a los clientes antes de su turno programado.
   - Integrar un sistema de calificaciones y comentarios para que los clientes puedan dejar feedback sobre su experiencia con el centro estético después de su turno.
   - Implementar un sistema de gestión de inventario para que los centros puedan llevar un control de los productos y materiales necesarios para cada tratamiento reservado.
   - Implementar más opciones de personalización (como colores, insersión de logo, tamaños de fuente, etc.).
   - Añadir la opción de que los clientes deban abonar un adelanto para reservar su turno. Por defecto el turno estaría pendiente hasta que el centro de estética reciba el pago y lo apruebe manualmente.
   - Incluir más tipos de gráficos (como gráficos de pastel, barras, etc.).
   
   ****- En la carga de nuevo turno, optimizar la caja de texto del telefono, para que coincida con el formato solicitado por el plugin de WhatsApp, y así facilitar la comunicación entre el centro y el cliente.****

2. **Mejorar la interfaz de usuario**:
   - Crear un panel de configuración más completo en el editor de WordPress.
   - Añadir previsualizaciones en tiempo real de los gráficos.
   - Implementar un sistema de ayuda o documentación dentro del plugin.

3. **Optimizar el rendimiento**:
   - Minificar los archivos CSS y JavaScript.
   - Asegurarse de que el plugin sea compatible con las últimas versiones de WordPress.
   - Optimizar el código para que sea más eficiente y menos pesado.

4. **Agregar pruebas y documentación**:
   - Escribir pruebas unitarias y de integración para asegurar la calidad del código.
   - Crear una documentación completa para usuarios y desarrolladores.
   - Añadir ejemplos de uso y casos de estudio.

5. **Publicar y mantener el plugin
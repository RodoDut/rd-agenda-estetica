# Agent Guidelines for rdtecnobelleza

This document defines the policies, engineering principles and operational rules any AI agent must follow when working on this project. It is written in Spanish (primary) with short English summaries where helpful.

## Propósito

- Proveer reglas claras para que un agente automatizado (script/assistant) modifique, añada o revise código en este repositorio.
- Garantizar seguridad, calidad y trazabilidad de cambios.

## Principios de ingeniería (obligatorios)

- SOLID: aplicar principios cuando se añada o refactorice código orientado a objetos (Single Responsibility, Open/Closed, Liskov, Interface Segregation, Dependency Inversion).
- CLEAN code: funciones pequeñas, nombres expresivos, evitar side-effects ocultos.
- DRY: evitar duplicación de lógica; extraer utilidades compartidas cuando sea apropiado.
- KISS: preferir soluciones simples y mantenibles. Evitar optimizaciones prematuras.
- YAGNI: no implementar funcionalidades que no están requeridas.

## Estilo y calidad

- Seguir las convenciones del proyecto: PHP para WP, JS en `wp-content/themes/...` o `wp-content/plugins/...`.
- Documentar cambios complejos con comentarios y actualizar `README.md` si se altera la funcionalidad visible.
- Mantener tests (si los hay) actualizados. Escribir pruebas unitarias mínimas para lógica crítica.

## Seguridad y datos sensibles

- Nunca exponer credenciales, claves, contraseñas, o dumps de base de datos en el repositorio.
- `wp-config.php`, backups, `.env` y archivos de base de datos deben permanecer fuera del repo. Si han sido subidos accidentalmente, reportar y coordinar limpieza del historial (BFG o git filter-repo).
- Para credenciales locales (SFTP, tokens, etc.) preferir un archivo `.env` local que **no** se versiona; incluir en el repo un `.env.example` con placeholders y usar scripts (por ejemplo `generate-sftp`) para generar los archivos de configuración locales desde `.env`.
- Validar entradas externas (forms, webhooks) y tratar datos personales según leyes aplicables. Minimizar almacenamiento de datos sensibles.

## Comunicaciones y autenticación

- Cuando se integre con servicios externos (N8N, APIs), usar tokens distintos de credenciales de DB y nunca ponerlos en frontend.
- Preferir endpoints server-side (WordPress REST API con permisos) para operaciones CRUD seguras.

## Testing y verificación

- Antes de proponer un merge, ejecutar pruebas locales y comprobar que no se introducen errores de sintaxis.
- Verificar `git status` y `git diff` para asegurarse de que sólo se incluyen los archivos esperados.

## Commits, ramas y pull requests

- Commits pequeños y atómicos con mensajes descriptivos: verbo en presente breve y ticket/contexto si aplica.
- Crear ramas con prefijo `feature/`, `fix/` o `chore/` según convenga.
- Incluir en el PR descripción del cambio, motivos, y cualquier paso para probar localmente.

## Manejo de `.gitignore` y archivos ya versionados

- Si se detecta material sensible commiteado previamente, NO intentar borrar el historial sin coordinación: informar al responsable y seguir proceso de rotación de credenciales + limpieza del historial con herramientas apropiadas.
- **Antes de borrar o sobrescribir archivos de configuración de VS Code** (por ejemplo `.vscode/*`) o archivos de configuración de extensiones (por ejemplo `.vscode/sftp.json`), **preguntar explícitamente y obtener confirmación humana**; no eliminar ni reemplazar configuraciones de editor sin confirmar con el propietario del proyecto. Si se requiere una modificación, documentar el motivo en el commit o en la descripción del PR.

## Operaciones automatizadas (lo que puede hacer un agente)

- Crear/editar archivos de código y documentación siguiendo las reglas anteriores.
- Añadir pruebas unitarias mínimas para nueva lógica.
- Proponer commits y ramas, pero NO forzar push directo a `master`/`main`. Requiere PR humano para revisión.

## Acciones prohibidas para agentes

- No forzar push directo a `master`/`main` sin revisión humana.
- No exponer ni introducir credenciales, claves o datos personales en el repositorio.
- No ejecutar scripts remotos o comandos que alteren servidores de producción automáticamente.

## Criterios de aceptación (stop criteria)

- Código fuente sigue estándares del proyecto y pasa pruebas locales.
- Cambios documentados en `README.md` o `CHANGELOG` cuando afectan comportamiento visible.
- PRs revisables con descripciones, pasos para reproducir y evidencia de pruebas.

## Ejemplo mínimo de commit message

```
feat(reservas): intercept hook for Simply Schedule Appointments to create 'Alquileres'

- add hook in theme child to save reservation data in CPT 'Alquileres'
- create user if email not found
```

## Contacto y escalado

- Para dudas de seguridad o borrado de historial: contactar al responsable del repo (Rodolfo Duttweiler).

---

_Short English summary:_

This file defines mandatory engineering principles (SOLID, CLEAN, DRY, KISS), security rules (never commit credentials), testing, commit/PR workflow, permitted/prohibited agent actions, and acceptance criteria. Agents must create PRs for human review; never push to `master`/`main` directly.

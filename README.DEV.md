# Desarrollo local (Docker)

Este archivo explica cómo levantar un entorno local de WordPress para desarrollar el plugin `rdt-centros-core` usando Docker Compose.

Objetivos:
- Tener una instancia de WordPress que monte el `./wp-content` del repo para que los cambios en plugins/temas se reflejen de inmediato.
- Evitar añadir el core a Git (no versionar `wp-includes` ni `wp-admin`).

Requisitos:
- Docker y Docker Compose instalados en tu máquina.

Arranque rápido:

1. Levanta los servicios:

```pwsh
docker compose up -d
```

2. Abre en el navegador:

http://localhost:8000

3. Instalación de WordPress (si no se auto-instala):

Puedes abrir la URL y seguir el asistente web para crear el usuario administrador. Alternativamente, usar WP-CLI dentro del contenedor:

```pwsh
# Entra al contenedor de wordpress
docker compose exec wordpress bash

# Dentro del contenedor, usar wp-cli (viene en la imagen oficial)
wp core install --url="http://localhost:8000" --title="Local RDTeconobelleza" --admin_user="admin" --admin_password="adminpass" --admin_email="dev@example.com"

# Si ya existe la instalación, sal del contenedor
exit
```

Notas importantes:
- El `docker-compose.yml` monta `./wp-content` en el contenedor. Esto mantiene el core fuera del repo y te permite editar plugins y temas localmente.
- Las credenciales por defecto (DB user/password/root) están pensadas sólo para desarrollo. No las uses en producción.
- Si quieres exponer phpMyAdmin o Xdebug podemos añadirlos al compose.

Sugerencias de flujo de trabajo:
- Mantén branches separados (como `rdt-centros-core`) para el desarrollo del plugin.
- Para probar cambios rápidos en PHP, editar archivos bajo `wp-content/plugins/rdt-centros-core/` y recargar la página en el navegador.
- Para ejecutar tests o comandos WP-CLI desde el host, puedes usar `docker compose exec wordpress wp ...`.

Problemas comunes:
- Si la web muestra instalador cada vez: borra la base de datos (volume `db_data`) o reinstala con WP-CLI.
- Si necesitas que la instalación incluya datos de ejemplo, podemos añadir un script `init.sql` o usar WP-CLI import.

¿Quieres que añada también un archivo `.env.example` para parametrizar puertos y credenciales o que incluya phpMyAdmin en el compose? 

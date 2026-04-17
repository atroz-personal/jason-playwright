# WordPress Docker Deploy

Stack listo para levantar una instancia de WordPress usando la imagen base `wordpress:6.9.4-php8.4-fpm`, con:

- WooCommerce instalado y activado automaticamente.
- Soporte para uploads mayores a `32MB` configurado en PHP y Nginx.

## Archivos

- `docker-compose.yml`: servicios de WordPress, Nginx y MariaDB.
- `docker/wordpress/Dockerfile`: imagen derivada con utilidades base y `wp-cli`.
- `docker/wordpress/scripts/bootstrap.sh`: intenta instalar WordPress y WooCommerce automaticamente, sin tumbar `php-fpm` si todavia no se puede completar el setup.
- `docker/nginx/default.conf`: proxy para `php-fpm` con `client_max_body_size`.

## Uso

1. Crear el archivo `.env` a partir del ejemplo:

```bash
cp .env.example .env
```

2. Editar `.env` y completar credenciales, URL del sitio y limites de upload si hace falta.

3. Levantar el stack:

```bash
docker compose up -d --build
```

4. Abrir el sitio en:

```text
http://localhost:8080
```

5. Si WordPress no queda instalado automaticamente en el primer arranque, completa el instalador desde el navegador y luego reinicia el contenedor de WordPress para que WooCommerce se instale:

```bash
docker compose restart wordpress
```

## Uploads > 32 MB

La configuracion base queda en `64M`:

- PHP: `upload_max_filesize=64M`
- PHP: `post_max_size=64M`
- Nginx: `client_max_body_size 64M`

Si necesitas otro limite, cambia en `.env`:

```env
WORDPRESS_UPLOAD_MAX_FILESIZE=128M
WORDPRESS_POST_MAX_SIZE=128M
WORDPRESS_MEMORY_LIMIT=256M
```

Y ajusta tambien `client_max_body_size` en `docker/nginx/default.conf`.

## Notas

- El bootstrap intenta activar WooCommerce en cada arranque.
- Si la autoinstalacion inicial no se puede completar, el sitio igual queda disponible y puedes terminar el setup desde el navegador.
- El script usa `wp-cli`, por lo que la instalacion inicial ocurre automaticamente cuando la base de datos ya responde.

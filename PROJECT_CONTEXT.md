# WordPress Project — Context Document
> Generado: 2026-06-12 | Rama: `master`

---

## Resumen del proyecto

WordPress con WooCommerce dockerizado, desarrollado localmente en XAMPP (Windows) y desplegado en un servidor remoto Linux vía Docker. El repositorio contiene **solo los archivos custom** (no el core de WordPress ni `wp-admin/`, `wp-includes/`).

---

## Infraestructura

| Item | Valor |
|---|---|
| Stack | WordPress 6.x + WooCommerce |
| Runtime | PHP 8.2 + Apache (imagen `php:8.2-apache`) |
| Base de datos | MySQL externo → contenedor `mysql_global` |
| DB name | `wooecomerce` |
| Puerto local | `8081` → `80` (interno) |
| Red Docker | `red_global` (externa, preexistente) |
| URL producción | `http://2.24.97.209` |
| Entorno local | XAMPP — `C:\xampp\htdocs\wordpress` |

---

## Repositorio Git

| Item | Valor |
|---|---|
| Rama activa | `master` |
| Remoto | `https://github.com/canon4/wordpress-app.git` |
| Autor | Diego canon |
| Integridad | **OK** — `git fsck` sin errores |
| Sincronización | `master` al día con `origin/master` |

### Historial de commits

```
08656a2  feat(config): soporte env var WP_HOME + wp-config-local en gitignore
a5db416  change port
0a90e3b  feat: initial WordPress repo with Docker CI/CD setup
```

### Rama fantasma detectada
Existe una rama local llamada `ls` (probablemente creada por error al ejecutar `ls` como comando git). Se puede eliminar con:
```bash
git branch -d ls
```

---

## Estado del working tree (cambios sin commitear)

### Archivos modificados (sin stagear)

| Archivo | Tipo | Descripción |
|---|---|---|
| `.htaccess` | modified | `RewriteBase /wordpress/` → `RewriteBase /` (fix path producción) |
| `Dockerfile` | modified | Versión completa con extensiones PHP, mod_rewrite, permisos |
| `docker-compose.yml` | modified | Red externa `red_global`, MySQL externo, env vars `WP_HOME`/`WP_SITEURL` |
| `.github/workflows/deploy.yml` | deleted | Pipeline CI/CD eliminado localmente |
| `C:\Users\canon\Desktop\mes1_text.txt` | deleted | Archivo basura trackeado por error (ya en `.gitignore`) |

### Archivos sin trackear

| Archivo | Estado |
|---|---|
| `build.log` | Log del último `docker build` (en `.gitignore`) |
| `wp-content/plugins/shipping-system-live-rates-fulfillment-envia/` | Plugin nuevo — **pendiente de commitear** |

---

## Archivos clave del proyecto

### `Dockerfile`
- Base: `php:8.2-apache`
- Extensiones PHP instaladas: `mysqli`, `pdo_mysql`, `gd` (jpeg+webp), `zip`, `opcache`, `exif`, `intl`, `mbstring`, `xml`
- `a2enmod rewrite` + `AllowOverride All`
- Límites PHP: upload 64M, memoria 256M, ejecución 300s
- Permisos: `www-data` owner, dirs 755 / files 644

### `docker-compose.yml`
```yaml
container_name: wordpress_app
ports: "8081:80"
network: red_global (external)
env: WORDPRESS_DB_HOST=mysql_global, DB=wooecomerce, user=root
     WP_HOME=http://2.24.97.209, WP_SITEURL=http://2.24.97.209
```

### `.htaccess`
- Compresión Gzip via `mod_deflate`
- Cache de browser (1 año para imágenes/fuentes, 1 mes para CSS/JS)
- WordPress permalink rules con `RewriteBase /`

### `.gitignore`
- Excluye: `.env`, `wp-config-local.php`, core WP (`wp-admin/`, `wp-includes/`), uploads, cache, themes (repo separado), scripts de debug/fix, `*.sql`, `*.ps1`, `wp-cli.phar`
- El core de WordPress **no está en git** — lo provee la imagen Docker

---

## CI/CD

El archivo `.github/workflows/deploy.yml` existía en el repositorio pero fue eliminado localmente. Su lógica era:

```
trigger: push a branch "main"  ← NOTA: el repo usa "master", no "main"
steps:
  1. Configurar SSH con secret SSH_PRIVATE_KEY
  2. SSH al servidor → cd /srv/wordpress → git pull origin main → docker compose up -d --build
```

**Problemas del pipeline original:**
- Escuchaba la rama `main` pero el repo usa `master`
- La ruta en el servidor era `/srv/wordpress`
- Secrets necesarios: `SSH_PRIVATE_KEY`, `SSH_HOST`, `SSH_USER`

---

## Plugin pendiente

`wp-content/plugins/shipping-system-live-rates-fulfillment-envia/` — plugin de envíos/rates en vivo (Envia.com). Está instalado localmente pero **no trackeado** en git. Decidir si:
- Commitearlo directamente al repo
- Gestionarlo como submodule
- Instalarlo vía Composer/wp-cli en el build

---

## Acciones pendientes recomendadas

1. **Commitear los cambios del working tree** — Dockerfile, docker-compose, .htaccess y la eliminación del archivo basura merecen un commit limpio
2. **Decidir qué hacer con el plugin Envia** — commitearlo o excluirlo del repo
3. **Recrear o actualizar el workflow CI/CD** — corregir la rama (`main` → `master`) y validar secrets
4. **Eliminar la rama `ls`** — `git branch -d ls`
5. **Revisar si el txt basura ya está fuera del tracking** — el `.gitignore` tiene el patrón `CUserscanon*.txt` pero el archivo aún aparece como `deleted` (staged necesario)

---

## Estructura del repositorio (solo archivos custom)

```
wordpress/
├── .github/workflows/        ← deploy.yml (eliminado localmente)
├── wp-content/
│   └── plugins/
│       └── shipping-system-live-rates-fulfillment-envia/  ← sin trackear
├── .gitignore
├── .htaccess                 ← custom (performance + permalinks)
├── Dockerfile
├── docker-compose.yml
├── wp-config.php             ← sin trackear (en .gitignore)
└── wp-config-local.php       ← sin trackear (en .gitignore)
```

> Los directorios `wp-admin/`, `wp-includes/` y la mayoría de archivos `.php` del core están ignorados — provienen de la imagen Docker en producción o de XAMPP en local.

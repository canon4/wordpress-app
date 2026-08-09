# CI/CD y despliegue de la imagen

Cómo se publica `wordpress-app` en producción, por qué está diseñado así, y qué hacer cuando algo falla.

> El tema (`amazonia-theme`) tiene su **propio** pipeline, independiente de este: publica cambios visuales
> por rsync a un volumen, sin tocar esta imagen. Ver
> [`wp-content/themes/amazonia-theme/docs/08_cicd_despliegue.md`](../wp-content/themes/amazonia-theme/docs/08_cicd_despliegue.md).

---

## Cómo era antes

Manual, sobre el propio servidor: `git pull` seguido de `docker compose up -d --build`. Sin CI, sin
rollback, y `.github/workflows/deploy.yml` llevaba tiempo vacío (solo espacios en blanco) sin que nadie lo
notara, porque nunca se usaba.

Ese proceso manual tenía un efecto secundario invisible: como el build corría *en el propio checkout del
servidor*, y `wp-config.php`/`.env` viven ahí físicamente en disco (gitignored, pero presentes), el
`Dockerfile` los horneaba dentro de la imagen sin que nadie lo pidiera explícitamente — `COPY . .` los
arrastraba. Funcionaba, pero de forma frágil: cualquiera con acceso a esa imagen tenía las credenciales de
producción dentro.

## Cómo es ahora

```
push a master (que no sea solo docs)
        │
   GitHub Actions: build + push a GHCR      (imagen privada, tag sha-<corto> + latest)
        │
   ssh: scp docker-compose.yml al servidor
        │
   docker compose pull + up -d wordpress    (recrea SOLO el servicio wordpress)
        │
   healthcheck + smoke test  →  rollback automático si falla
```

El build ya no corre en el servidor. El servidor solo recibe un `docker-compose.yml` y hace `pull` de una
imagen ya construida — **nunca** tiene acceso al código fuente completo del repo en ese paso, así que
`wp-config.php`/`.env` no pueden volver a colarse en una imagen por accidente: ni siquiera están en el
contexto de build de GitHub Actions (gitignored → `actions/checkout` jamás los trae).

### `wp-config.php` y `.env`: bind mount, no build

Ambos viven **solo en el servidor** (`/srv/wordpress/wordpress-app/{wp-config.php,.env}`), nunca en git, y
`docker-compose.yml` los monta directamente en el contenedor:

```yaml
volumes:
  - wp_uploads:/var/www/html/wp-content/uploads
  - ${AMAZONIA_THEME_PATH:-/srv/amazonia/theme/current}:/var/www/html/wp-content/themes/amazonia-theme:ro
  - ${WP_CONFIG_PATH:-/srv/wordpress/wordpress-app/wp-config.php}:/var/www/html/wp-config.php:ro
```

`.env` no necesitó ningún cambio — `env_file: .env` ya se lee del host en cada arranque del contenedor, no
es un build-time copy. `wp-config.php` sí necesitó este mount nuevo, precisamente porque mover el build al
runner de GitHub Actions rompió el mecanismo accidental que lo horneaba antes.

**Si algún día vas a levantar esto en un servidor nuevo desde cero**, esos dos archivos hay que crearlos a
mano una sola vez (copiando `.env.example`/`wp-config-sample.php` y rellenándolos con los valores reales) —
exactamente como se hacía antes de este pipeline. Después de esa primera vez, ni git ni CI ni el pipeline
los vuelven a tocar jamás.

### Tags de imagen

- `sha-<corto>` — inmutable, uno por cada build. Nunca se sobreescribe.
- `latest` — cosmético, para pulls manuales. El pipeline **nunca** lo usa para decidir qué desplegar o a
  qué volver; siempre trabaja con el tag `sha-` explícito.

### Rollback

Se lee el estado *real* de Docker en el momento (`docker inspect --format '{{.Config.Image}}' <cid>`), no un
archivo de estado aparte que pueda desincronizarse. Como cada tag es inmutable, "volver" es simplemente
re-desplegar ese mismo tag — siempre disponible en GHCR.

### Qué NO dispara un deploy

`deploy.yml` tiene `paths-ignore` en el trigger de `push`: cambios que solo tocan `**.md`, `docs/`,
`entregables/`, `.claude/`, `readme.html`, `license.txt`, `.gitignore`/`.gitattributes` no reconstruyen ni
recrean el contenedor. Es lista de **bloqueo**, no de permiso — cualquier archivo no listado sigue
disparando el deploy normalmente, así que el peor caso es un deploy de más, nunca uno perdido. Filtra por
*ruta*, no por si el contenido cambia algo funcional: tocar un `.php` de un plugin dispara deploy siempre,
aunque sea un cambio cosmético.

`ci.yml` **no** tiene este filtro a propósito — si en algún momento se configura una regla de rama que
exija ese check en cada PR, un `paths-ignore` ahí podría dejar un PR bloqueado esperando un check que nunca
corre.

---

## Autenticación del servidor contra GHCR

El paquete es **privado**. El servidor necesita `docker login ghcr.io` con un PAT classic de solo
`read:packages`.

### Dos gotchas reales que ya nos mordieron

1. **`docker login` es por usuario del sistema, no global.** Cada usuario tiene su propio
   `~/.docker/config.json`. Autenticar como `root` **no** autentica al usuario `deploy`, que es quien
   ejecuta `docker compose pull` dentro del pipeline (vía SSH con la clave de `SSH_PRIVATE_KEY`). Si el
   pull falla con `unauthorized` aunque ya hiciste `docker login`, comprobá con qué usuario lo hiciste:
   ```bash
   su - deploy -c "docker login ghcr.io -u canon4 --password-stdin"   # como root, corre el login COMO deploy
   ```
2. **PAT fine-grained vs classic.** Un fine-grained token puede dar `Login Succeeded` (el login solo valida
   que sea una credencial de GitHub legítima) y aun así fallar el `pull` con `denied` — el scope real se
   verifica recién ahí, no en el login. Los fine-grained tienen soporte históricamente poco confiable para
   operaciones de GHCR. Si ves login OK pero pull `denied`, generá un token **classic**
   (<https://github.com/settings/tokens/new>) con únicamente `read:packages` marcado.

Nunca pegues el PAT en texto plano en un chat, ticket o log — usá `--password-stdin`:
```bash
echo 'EL_TOKEN' | su - deploy -c "docker login ghcr.io -u canon4 --password-stdin"
```
Si un token queda expuesto igual (por ejemplo, pegado sin querer en algún lado), tratalo como comprometido:
revocalo en GitHub y generá uno nuevo, no esperes a ver si alguien lo usa.

### Visibilidad del paquete: por qué privado

El repo `wordpress-app` es público, así que el código de los tres plugins propios (split de pagos, IA,
envíos) ya es visible en GitHub independientemente de la imagen — hacerla privada **no** oculta ese código.
Lo que sí evita es que cualquiera pueda `docker pull` y tener el sistema completo funcionando en un
comando, sin la fricción de clonar, instalar WordPress, activar plugins, etc. Costo cero: el servidor ya
necesita el PAT igual, así que no se perdió nada dejándolo privado.

Si en algún momento se quiere proteger de verdad el código propietario, la palanca real es la visibilidad
del **repositorio**, no la del paquete — eso es una decisión más grande (implica CI con repo privado,
historial de commits, quién tiene acceso) que queda fuera de este trabajo.

---

## Secretos de GitHub

| Secreto | Valor / origen |
|---|---|
| `SSH_HOST` | `2.24.97.209` |
| `SSH_USER` | `deploy` |
| `SSH_PRIVATE_KEY` | La misma clave ya verificada para el pipeline del tema (un solo par de claves para ambos pipelines, mismo servidor y mismo usuario) |
| `SITE_URL` | `https://amazoniamarket.zogui.cloud` (confirmado en vivo contra la config real de nginx del servidor — no coincide con `amazoniamarket.online`, que aparece en `.env.example`/este README como legado) |

Permisos del usuario `deploy` en el servidor, además de estar en el grupo `docker`:
```bash
setfacl -m u:deploy:rw /srv/wordpress/wordpress-app/docker-compose.yml /srv/wordpress/wordpress-app/.env
```
El pipeline sobreescribe `docker-compose.yml` (`scp`) y anota el tag desplegado en `.env` en cada corrida —
necesita escritura en esos dos archivos puntuales, nada más.

---

## Runbook

### Re-desplegar un tag ya publicado, sin reconstruir

*Actions → Deploy de la imagen → Run workflow* → campo `image_tag` con el `sha-<corto>` deseado.

### Rollback manual

```bash
prev_tag="sha-XXXXXXX"   # el tag al que querés volver
cd /srv/wordpress/wordpress-app
WP_IMAGE_TAG="$prev_tag" docker compose pull wordpress
WP_IMAGE_TAG="$prev_tag" docker compose up -d wordpress
```

### El workflow pasa pero no ves el cambio

Comprobá que el archivo que cambiaste no cayó en el `paths-ignore` de `deploy.yml` (arriba). Si el cambio
era, por ejemplo, solo en `docs/`, es esperado que no haya disparado nada.

### `docker compose pull` falla con `unauthorized` o `denied`

Ver los dos gotchas de autenticación GHCR más arriba — casi siempre es el usuario equivocado (`root` en vez
de `deploy`) o un PAT fine-grained.

### El contenedor no llega a `healthy`

```bash
docker logs --tail 100 "$(docker compose -f /srv/wordpress/wordpress-app/docker-compose.yml ps -q wordpress)"
```
Si el log muestra que WordPress no arranca, sospechá primero de `wp-config.php`: confirmá que el bind mount
sigue apuntando a un archivo real y legible (`ls -l /srv/wordpress/wordpress-app/wp-config.php`).

---

## Riesgos y decisiones asumidas

- **Cada deploy recrea el contenedor** (a diferencia del tema, que hace `apache2ctl graceful` sin
  downtime). Es inherente a cambiar de imagen — parar y arrancar el contenedor toma unos segundos.
- **`tests/run-all.php`** de los plugins propios no corren en `ci.yml` — necesitan WordPress+MySQL vivos, no
  viables en un check ligero de PR sin un esfuerzo bastante mayor (seed de DB, activar plugins).
- **Sin lock entre este pipeline y el del tema.** Viven en repos distintos; sus `concurrency:` de Actions no
  se serializan entre sí. Bajo riesgo individual (un `apache2ctl graceful` y un
  `docker compose up --force-recreate` coincidiendo en el mismo segundo no debería corromper nada), pero es
  una condición de carrera real que antes no existía.

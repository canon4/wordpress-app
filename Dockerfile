# ═══════════════════════════════════════════════════════════════════
# Stage 1 — Build de assets del tema (Tailwind CSS + subset de iconos)
# ═══════════════════════════════════════════════════════════════════
FROM node:20-bookworm-slim AS assets
WORKDIR /theme

# Python + fonttools/brotli para regenerar el subset de Material Symbols.
# python-is-python3 es necesario: en Debian solo existe el binario python3,
# y el script `build:icons` invoca `python` a secas.
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-pip python-is-python3 \
    && pip3 install --no-cache-dir --break-system-packages fonttools brotli \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Dependencias de Node primero para aprovechar la cache de capas
COPY wp-content/themes/amazonia-theme/package.json wp-content/themes/amazonia-theme/package-lock.json ./
RUN npm ci

# Resto del tema (plantillas para el scan de Tailwind, scripts para los iconos)
COPY wp-content/themes/amazonia-theme/ ./
RUN npm run build:css && npm run build:icons


# ═══════════════════════════════════════════════════════════════════
# Stage 2 — Imagen final WordPress (PHP + Apache)
# ═══════════════════════════════════════════════════════════════════
FROM php:8.2-apache

# PHP extensions required by WordPress
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
        mysqli \
        pdo_mysql \
        gd \
        zip \
        opcache \
        exif \
        intl \
        mbstring \
        xml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite for WordPress permalinks
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

RUN { \
    echo "upload_max_filesize = 64M"; \
    echo "post_max_size = 64M"; \
    echo "memory_limit = 256M"; \
    echo "max_execution_time = 300"; \
} > /usr/local/etc/php/conf.d/wordpress.ini

WORKDIR /var/www/html

COPY . .

# Assets del tema compilados en la stage 'assets' (sobrescriben los del repo
# para garantizar que tailwind.css y el subset de iconos van siempre frescos
# respecto a las plantillas, sin depender de que se hayan buildeado a mano).
COPY --from=assets /theme/assets/css/tailwind.css \
     wp-content/themes/amazonia-theme/assets/css/tailwind.css
COPY --from=assets /theme/assets/fonts/material-symbols-outlined.woff2 \
     wp-content/themes/amazonia-theme/assets/fonts/material-symbols-outlined.woff2
COPY --from=assets /theme/scripts/used-icons.txt \
     wp-content/themes/amazonia-theme/scripts/used-icons.txt

# wp-content/uploads se monta como volumen en runtime; crearla aquí evita
# que falte si el volumen está vacío en el primer arranque.
RUN mkdir -p wp-content/uploads \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fs http://localhost/ -o /dev/null || exit 1

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

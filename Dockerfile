# ═══════════════════════════════════════════════════════════════════
# Imagen WordPress (PHP + Apache)
# ═══════════════════════════════════════════════════════════════════
# El tema (amazonia-theme) tiene su propio pipeline de despliegue: se publica
# vía rsync a un volumen montado en runtime, no se hornea en esta imagen. Ver
# wp-content/themes/amazonia-theme/docs/08_cicd_despliegue.md.
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

# wp-content/uploads, el tema y wp-config.php se montan como volúmenes en
# runtime; se crean aquí para que no falte el punto de montaje si el volumen
# está vacío en el primer arranque.
RUN mkdir -p wp-content/uploads wp-content/themes/amazonia-theme \
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

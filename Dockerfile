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

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80

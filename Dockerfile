FROM wordpress:latest

# Plugins y lenguajes propios del proyecto
# Themes NO — vienen de bind mount (repo separado con su propio CI/CD)
COPY wp-content/plugins/ /var/www/html/wp-content/plugins/
COPY wp-content/languages/ /var/www/html/wp-content/languages/

COPY wp-config.php /var/www/html/wp-config.php

RUN chown -R www-data:www-data /var/www/html/wp-content

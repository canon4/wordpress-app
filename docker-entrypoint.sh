#!/bin/sh
set -e

# El volumen de uploads puede montarse con dueño root en el primer arranque
chown -R www-data:www-data /var/www/html/wp-content/uploads

exec "$@"

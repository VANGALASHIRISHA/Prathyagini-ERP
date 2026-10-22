#!/bin/sh
set -e
# Render/Railway tell us which port to listen on.
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
# Create the tables on first start only (skipped when the database already has data).
php /var/www/html/docker/init_db.php || echo "init_db failed - check DB_* environment variables"
exec apache2-foreground

# Pratyangi Fashions ERP - container for Render / Railway / Fly.io / any Docker host.
FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && sed -i 's#<Directory /var/www/>#<Directory /var/www/>\n\tAllowOverride All#; s#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf \
 && sed -i 's/^expose_php.*/expose_php = Off/' "$PHP_INI_DIR/php.ini-production" \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p /var/www/html/storage/backups \
 && chown -R www-data:www-data /var/www/html/public /var/www/html/storage \
 && chmod +x /var/www/html/docker/entrypoint.sh

ENV PORT=10000
EXPOSE 10000
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]

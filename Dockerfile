# Production image: PHP-FPM + NGINX (serversideup/php), serving on port 8080 as www-data.
FROM serversideup/php:8.4-fpm-nginx

WORKDIR /var/www/html

# Dependencies first, so the layer is cached while only the code changes.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

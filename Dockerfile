# Production image: PHP-FPM + NGINX (serversideup/php), serving on port 8080 as www-data.
FROM serversideup/php:8.4-fpm-nginx

# The Laravel scheduler runs in this same container as a supervised s6 service next to nginx and php-fpm,
# so the deployment has one built service.
USER root
# gd is required by mpdf/mpdf (PDF exports).
RUN install-php-extensions gd
RUN set -eux; \
    svc=/etc/s6-overlay/s6-rc.d/laravel-scheduler; \
    mkdir -p "$svc/dependencies.d"; \
    touch "$svc/dependencies.d/base"; \
    echo longrun > "$svc/type"; \
    printf '#!/command/execlineb -P\nwith-contenv\ncd /var/www/html\nphp artisan schedule:work\n' > "$svc/run"; \
    chmod +x "$svc/run"; \
    touch /etc/s6-overlay/user-bundles.d/user/contents.d/laravel-scheduler
USER www-data

WORKDIR /var/www/html

# Dependencies first, so the layer is cached while only the code changes.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

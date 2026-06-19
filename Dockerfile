# php-claw on the official TrueAsync PHP image.
# Image tags: https://hub.docker.com/r/trueasync/php-true-async
# Pin to a version (e.g. :0.7.2-php8.6) for reproducible builds if you prefer.
FROM trueasync/php-true-async:latest

# Composer, taken straight from its official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install runtime dependencies first: this layer stays cached until the
# composer files change. The source isn't here yet, so skip the autoloader.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-autoloader

# Application code, then a production autoloader.
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# claw runs in console mode and reads its config from /app/.env, which holds
# secrets and is never baked in — mount it at run time, e.g.:
#   docker run --rm -it -v "$PWD/.env:/app/.env" php-claw
ENTRYPOINT ["php", "bin/claw"]

FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    gdal-tools \
    gdal-dev \
    linux-headers \
    $PHPIZE_DEPS

RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    bcmath

# Clean up
RUN apk del $PHPIZE_DEPS && rm -rf /var/cache/apk/*

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Run as non-root (www-data)
RUN chown -R www-data:www-data /var/www/html
USER www-data

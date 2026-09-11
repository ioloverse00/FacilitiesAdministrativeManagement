FROM composer:2 AS composer

FROM php:8.2-apache

# Install system dependencies + compile PHP extensions in parallel
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j$(nproc) \
        curl \
        mbstring \
        pdo_mysql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Apache configuration
RUN a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
        > /etc/apache2/conf-available/fam-allowoverride.conf \
    && a2enconf fam-allowoverride

WORKDIR /var/www/html

# Copy application
COPY . /var/www/html/

# Copy Composer from official Composer image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Install production dependencies and prepare writable directories
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && mkdir -p \
        storage/documents \
        storage/logs \
        storage/reservations \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage
FROM composer:2 AS composer

FROM php:8.2-apache

# Install PHP extensions
COPY --from=mlocati/php-extension-installer:latest \
    /usr/bin/install-php-extensions \
    /usr/local/bin/

RUN install-php-extensions \
    mbstring \
    pdo_mysql \
    zip

# Apache configuration
RUN a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
        > /etc/apache2/conf-available/fam-allowoverride.conf \
    && a2enconf fam-allowoverride

WORKDIR /var/www/html

# Copy Composer
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Copy application
COPY . /var/www/html/

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
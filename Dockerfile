FROM composer:2 AS composer

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install \
        curl \
        mbstring \
        pdo_mysql \
        zip \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/fam-allowoverride.conf \
    && a2enconf fam-allowoverride

WORKDIR /var/www/html

COPY . /var/www/html/

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && mkdir -p storage/documents storage/logs storage/reservations \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage

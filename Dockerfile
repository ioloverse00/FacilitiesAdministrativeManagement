FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install \
        curl \
        mbstring \
        pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN mkdir -p storage/documents storage/logs storage/reservations \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage

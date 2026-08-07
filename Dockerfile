FROM php:8.2-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl

COPY . /var/www/html/

EXPOSE 80
